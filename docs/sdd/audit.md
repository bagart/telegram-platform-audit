# Audit — SDD

> Append-only audit module for the Telegram bot platform. Entries are tamper-evident (hash chain), schema-versioned, and correlated across transport boundaries.

## Architecture Overview

```
Producers (commands, listeners, services)
    │
    ▼
┌─────────────────────┐
│  AuditSinkContract   │  append-only write
│  ┌─────────────────┐ │
│  │ CountingAuditSink│ │  decorator: counters + latency
│  └────┬────────────┘ │
│       ▼              │
│  DatabaseAuditSink   │  DB persistence + hash chain
│  InMemoryAuditSink   │  tests / lightweight hosts
└─────────────────────┘
    │
    ▼
┌─────────────────────┐
│   audit_entries DB   │  hash chain, sequence-ordered
└─────────────────────┘
    │
    ▼
┌─────────────────────┐
│ AuditQueryContract   │  read-only query + count
│  DatabaseAuditQuery  │
└─────────────────────┘
    │
    ▼
AuditController (admin UI) + AuditMetricsCollector + AuditHealthProbe
```

## Core Model

**AuditEntry** — final readonly DTO, `SCHEMA_VERSION = 2`, `JsonSerializable`. Fields: `id`, `actor` (AuditActor), `target` (AuditTarget), `operation` (Operation enum or namespaced string), `oldState`, `newState`, `source`, `occurredAt`, `correlationId`, `sourceVersion`, `metadata`, `hash`, `prevHash`.

- **Write path:** any component → `AuditSinkContract::append(AuditEntry)` — no update/delete.
- **Query path:** `AuditQueryContract::query(AuditQueryFilter)` — separated from write (ADR-002).
- **Failure policy:** `AuditFailurePolicyResolver` decides FAIL_OPEN vs FAIL_CLOSED per caller context — audit loss must never break business flow unless policy says so.
- **DTO versioning:** `SCHEMA_VERSION` constant, `fromJsonV1()` / `fromJsonV2()` deserialization. V2 added hash/prevHash.

**AuditActor** — `type` (user/system/module), `id`, optional `displayName`. Credential-free by design.

**AuditTarget** — `botId` (nullable = platform scope), `subjectType`, `subjectId`, optional `chatId`.

**Operation** — PHP enum for platform-reserved operations (module lifecycle, bot management, access control). Modules use namespaced strings without touching the enum. `AuditEntry` accepts `Operation|string`.

**AuditQueryFilter** — all-optional filter DTO. Operation supports glob `*` for prefix matching.

## Observability

Three layers, decoupled:

1. **Real-time (process-scoped):** `CountingAuditSink` decorator wraps the inner sink. On every append, records latency (ms) and counts into `AuditCounters`. On failure, records failure count. `AuditCounters::snapshot()` returns totals, by-operation, by-bot, failure-by-operation, and latency histogram (min/max/avg/p50/p95/p99). Counters reset on process restart.

2. **Historical (DB aggregation):** `AuditMetricsCollector` queries last 24h of entries, aggregates by operation, source, and actor type. Exposes real-time counters via `counters()`.

3. **Health:** `AuditHealthProbe` checks sink writability — counts total, last-hour, last-day entries. Status: `healthy` (recent entries exist) or `degraded` (zero entries in last hour but total > 0).

All three are registered as singletons via `AuditServiceProvider`.

## Tamper-Evidence

SHA-256 hash chain. Each entry's hash = `SHA256(json(entry_data) + prevHash)`. Chain ordered by `sequence` column (monotonic auto-increment). First entry has `prevHash = null`.

`AuditHasher` computes hashes. `DatabaseAuditSink` writes both `hash` and `prev_hash` on append. Verification via `audit:verify` — walks entries in ascending sequence, recomputes hashes, reports breaks without crashing.

## Event Listeners

Fully decoupled — source modules fire Laravel events; audit catches them. No module imports `BAGArt\TelegramBotAudit\*`.

| Listener | Events Consumed | Operations Recorded |
|---|---|---|
| `RecordAccessControlEvents` | `GrantCreated`, `GrantRevoked` | `access.grant.created`, `access.grant.revoked` |
| `RecordModuleLifecycleEvents` | `BotCreated`, `BotDeleted`, `BotTokenRotated`, `BotModuleSettingChanged`, `BotModuleEnabled`, `BotModuleDisabled` | `bot.created`, `bot.deleted`, `bot.token.rotated`, `module.enablement.changed`, `module.settings.changed`, `module.runtime.enabled`, `module.runtime.disabled` |

Listeners respect failure policy — FAIL_CLOSED rethrows `AuditException` to block the originating operation.

## Module Events Registrar (W-D3, 2026-10-08)

Before W-D3 the seam could only record what the hardcoded 8-entry table told it to: **108 module actions across 7 modules fired no event at all** (14 antispam + 13 summarizer + 10 tts + 11 stt + 6 nettools + 28 proxy + 26 mafia) — rule changes, enforcement, appeals, provider-token changes, proxy audits and mafia decisions left no operator-visible trail, and every new platform event forced a central audit-package edit.

**Contract & seam (dependency direction forces the home):** `ModuleAuditEventContract` lives in `telegram-bot-lib` (`src/Contracts/Modules/`) — audit requires bot-lib, modules require bot-lib but never audit, so a contract in the audit package would be unreachable to module authors. 10 methods: `moduleId`, `operation`, `botId`, `subjectType`, `subjectId`, `actorId`, `actorType`, `oldState`, `newState`, `context()` (metadata; credentials/token values forbidden). New registrar verb `auditEvent(string $eventClass): self` on `TgModuleRegistrar` (declarative raw-store contribution, same `web*` pattern: `TypedModuleRegistrar` asserts class-exists then throws `moduleScopeRequired`, `ModuleScopedRegistrar` stamps the booting module id). New append-only `TgAuditEventRegistry` in `telegram-bot-lib/src/Modules/` (clone of `TgWebUiRegistry`), singleton-bound beside the four web registries, threaded into `ModuleBootloader` as a 5th ctor argument.

**One event class per module, not per action.** Each module ships `src/Events/<Module>AuditEvent.php` implementing the contract, with a `public const string` per operation name (keeps 108 call sites typo-free and greppable). Registry = **7 `auditEvent()` calls**, not 108 — matches `AuditEntry`'s own `Operation|string` docblock (namespaced module strings were already anticipated). No `TgModuleConfig` field (registrar verb makes a config key redundant; DTO stays at 14 fields).

**Consumption:** `AuditServiceProvider::registerEventListeners()` refactored into (1) a private const table of the existing 8 platform mappings (byte-for-byte parity — `event:list` must not change for platform events, incl. B5's 3 dead Bot* entries kept for C9) and (2) a registry pass after them: `bound()` guard → `is_a(…, ModuleAuditEventContract, true)` contract check (drop + error log, never throw out of boot) → stamped-module ownership check (drop + warning) → first-wins dedupe (warning) → `$events->listen($class, GenericModuleAuditListener::class.'@handle')`. Ordering is safe by construction: module `register()` runs during the engine provider's `boot()`; the pass runs inside the existing `app->booted()` callback, so the registry is fully populated. New `GenericModuleAuditListener` (singleton beside the existing listeners) builds `AuditEntry` field-for-field from the contract and appends in the try/catch copied from `RecordModuleLifecycleEvents` (FailClosed → rethrow; FailOpen → warning + continue).

**Failure policy:** `DefaultAuditFailurePolicyResolver` gains a 3rd ctor param `array $operationPrefixes`, bound from new config key `failure_policy.prefixes` (empty by default) in both host and package `config/audit.php`. Precedence: exact match → config prefix (declared order, first hit) → hardcoded arms (`access.`/`bot.`/`role.`/`module.runtime.`) → default. All 108 module ops resolve `fail_open` (no proposed name matches a hardcoded prefix); the mechanism exists so an operator can escalate a tier later without an engine release.

**Module wiring:** 7 event classes, 7 registrar calls, **108 dispatch sites** (`event(new <Module>AuditEvent(CONST, …))`), operation vocabulary promoted from the design pack §4 to decision. Context discipline: only structured non-secret facts (ids, counts, rule deltas as old/newState, request id); provider tokens (summarizer/tts/stt token-store rows) record existence only — never the value; enforcement = explicit test, not runtime filtering. Actor = requesting user/admin where the path has one, else system.

**Deferred contracts:** per-action typed events (registry accepts any contract class without seam changes); `TgModuleConfig.auditOperations` (reconsider if diagnostics should introspect the operation vocabulary); write-volume mitigation (high-frequency rows serialize on `DatabaseAuditSink`'s `lockForUpdate()` over an unindexed `sequence` — sampling/throttle or a `sequence` index if latency shows); platform listener table → per-package registries once B5's dead mappings are fixed; module-scoped policy tiers via the config-prefix hook.

Parity canary: `php artisan event:list` lists all 8 platform mappings plus the 7 module event classes → `GenericModuleAuditListener@handle`. No schema change; no discovery (explicit registry only; constraints C1–C14).

## Correlation

Transport-agnostic via `CorrelationContext` interface. Implementations:

- **HTTP:** `CorrelationMiddleware` — extracts `X-Correlation-Id` / `X-Request-Id` header, UUID fallback, sets on `MutableCorrelationContext` singleton, echoes back in response header.
- **CLI:** `StaticCorrelationContext` with command signature or static ID.
- **Queue:** Job ID as correlation source.

## Retention

`RetentionPruner` deletes entries older than configured days. Logs a warning that pruning may break hash chain (run `audit:verify` after).

**Scheduled:** `audit:prune` runs daily at 03:00 via Laravel scheduler. Supports `--days=N` override and `--dry-run` mode.

## Commands

| Command | Description | Schedule |
|---|---|---|
| `audit:prune` | Delete entries older than retention period | Daily 03:00 |
| `audit:verify` | Walk hash chain, recompute hashes, report breaks | Manual |

`audit:verify` processes entries in batches (`--batch=N`, default 1000), walks in ascending sequence order, checks `prevHash` linkage and recomputed hash. Exit code 0 = OK, 1 = breaks found.

## Configuration

`config/audit.php` (publishable via `audit-config` tag):

```php
'driver'             => env('AUDIT_DRIVER', 'database'),        // 'database' | 'memory'
'database.connection' => env('AUDIT_DB_CONNECTION'),             // null = default
'database.table'      => env('AUDIT_DB_TABLE', 'audit_entries'),
'failure_policy.default' => env('AUDIT_FAILURE_DEFAULT', 'fail_open'),
'failure_policy.operations' => [                                 // per-operation overrides
    'access.grant.created' => 'fail_closed',
    // ...
],
'retention.days'     => (int) env('AUDIT_RETENTION_DAYS', 365),
```

`DefaultAuditFailurePolicyResolver` — prefix-based matching: `access.*`, `bot.*`, `role.*` → FAIL_CLOSED; `module.runtime.*` → FAIL_OPEN; config overrides win.

## Database

Table `audit_entries` (two migrations):

| Column | Type | Notes |
|---|---|---|
| `id` | string(36) | PK |
| `hash` | string(64) | nullable, SHA-256, unique index |
| `prev_hash` | string(64) | nullable |
| `actor_type` | string | indexed |
| `actor_id` | string | indexed |
| `actor_display_name` | string | nullable |
| `bot_id` | string | nullable, indexed |
| `chat_id` | bigint | nullable, indexed |
| `subject_type` | string | indexed |
| `subject_id` | string | indexed |
| `operation` | string | indexed |
| `old_state` | json | nullable |
| `new_state` | json | nullable |
| `source` | string | |
| `source_version` | string | nullable |
| `metadata` | json | nullable |
| `correlation_id` | string | nullable, indexed |
| `schema_version` | smallint | default 2 |
| `sequence` | bigint unsigned | nullable, monotonic chain order |
| `occurred_at` | timestamp | indexed |
| `created_at` | timestamp | |

Composite indexes: `(bot_id, operation, occurred_at)`, `(bot_id, actor_type, actor_id)`, `(bot_id, subject_type, subject_id)`.

## API Routes

Admin-gated (`web` + `auth` + `verified` middleware):

| Method | URI | Action |
|---|---|---|
| GET | `/admin/audit` | `AuditController@index` — paginated query |
| GET | `/admin/audit/{id}` | `AuditController@show` — single entry lookup |

## Testing

```bash
vendor/bin/pest --testsuite audit-unit --testsuite audit-feature
# or from module:
cd misc/BAGArt/telegram-platform-audit && composer test
```

