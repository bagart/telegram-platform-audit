# Audit Module — Improvement & Integration Plan

> Revised 2026-09-02 after architecture review.
> Module status: **pure-domain scaffold** (5 source files, zero consumers, zero Laravel integration).

---

## Current State

| Aspect | Status |
|---|---|
| Domain DTOs (`AuditEntry`, `AuditActor`, `AuditTarget`) | ✅ Done — `final readonly`, versioned JSON, credential-free |
| Contract (`AuditSinkContract::append`) | ✅ Done — append-only by design |
| In-memory implementation (`InMemoryAuditSink`) | ✅ Done — tests + lightweight hosts |
| Unit tests | ✅ Done — round-trip, immutability, no-credential invariants |
| Laravel ServiceProvider | ❌ Missing |
| Config file (`config/audit.php`) | ❌ Missing |
| Service container bindings | ❌ Missing |
| Persistent storage sink (DB) | ❌ Missing |
| Database migration | ❌ Missing |
| Operation model (enum + namespaced strings) | ❌ Missing |
| AuditFailurePolicy | ❌ Missing |
| CorrelationContext abstraction | ❌ Missing |
| Authoritative audit wiring (command → append) | ❌ Missing |
| Observational audit wiring (event → listener) | ❌ Missing |
| Query/read API | ❌ Missing |
| Tenant scoping (nullable bot_id) | ❌ Missing |
| Retention / pruning | ❌ Missing |
| README | ❌ Missing |
| Integration in other modules | ❌ Zero `use` statements outside the module |
| `composer.prod.json` entry | ❌ Missing |

---

## Target Architecture

```
                    ┌─────────────────────────┐
                    │      Domain Audit       │
                    │                         │
                    │  AuditEntry             │
                    │  AuditActor             │
                    │  AuditTarget            │
                    │  Operation (enum)       │
                    │  AuditSinkContract      │
                    │  AuditQueryContract     │
                    │  AuditFailurePolicy     │
                    │  CorrelationContext     │
                    └───────────┬─────────────┘
                                │
                      Laravel adapter layer
                                │
                    ┌───────────▼─────────────┐
                    │  AuditServiceProvider   │
                    │                         │
                    │  config                 │
                    │  bindings               │
                    │  migrations             │
                    │  listeners              │
                    └─────┬───────────┬───────┘
                          │           │
                 ┌────────▼──┐  ┌─────▼────────┐
                 │ Database  │  │    Memory    │
                 │   Sink    │  │     Sink     │
                 └───────────┘  └──────────────┘

 Producers (authoritative — command → append → event):

   Engine lifecycle ──────┐
   Management CRUD ───────┤
   Access decisions ──────┤──→ AuditSinkContract
   Menu role grants ──────┘

 Producers (observational — event → listener → append):

   Engine events ─────────┐
   Runtime telemetry ─────┤──→ AuditListener → AuditSinkContract
   Module diagnostics ────┘

 Readers:

   Admin API ─────────────→ AuditQueryContract
   CLI commands ───────────→ AuditQueryContract
   Telegram /settings ────→ AuditQueryContract
```

**Key constraint:** `AppServiceProvider` and `config/tg_modules.php` know nothing about `BAGArt\TelegramBotAudit\*`. The sole Laravel registration point is `AuditServiceProvider`, auto-discovered via Composer.

---

## Plan

### Phase 1 — Domain Contract Cleanup & Operation Model

Goal: domain layer is complete, extensible, and ready for integration.

#### 1.1 Operation Model

Create `src/Operation.php` — platform-reserved enum with dot-notation values:

```php
enum Operation: string
{
    // Module lifecycle
    case ModuleInstalled = 'module.installed';
    case ModuleUninstalled = 'module.uninstalled';
    case ModuleEnabled = 'module.enabled';
    case ModuleDisabled = 'module.disabled';
    case ModuleConnected = 'module.connected';
    case ModuleDisconnected = 'module.disconnected';
    case ModuleSuspended = 'module.suspended';
    case ModuleConfigChanged = 'module.config.changed';
    case ModuleConfigMigrated = 'module.config.migrated';
    case ModuleRuntimeFailed = 'module.runtime.failed';
    case ModuleRuntimeRecovered = 'module.runtime.recovered';

    // Bot management
    case BotCreated = 'bot.created';
    case BotDeleted = 'bot.deleted';
    case BotTokenRotated = 'bot.token.rotated';

    // Access control
    case AccessGrantCreated = 'access.grant.created';
    case AccessGrantRevoked = 'access.grant.revoked';
    case AccessDecisionMade = 'access.decision.made';

    // Menu / roles
    case RoleGranted = 'role.granted';
    case RoleRevoked = 'role.revoked';
}
```

Module-specific operations use namespaced strings — the audit package does NOT need to know about them:

```
proxy.probe.completed
proxy.pool.rotated
antispam.rule.triggered
summarizer.digest.generated
mafia.game.created
```

`AuditEntry` accepts `Operation|string` — enum for platform ops, raw string for module ops. Stored as canonical string in all cases.

#### 1.2 AuditEntry Updates

Update `src/AuditEntry.php`:
- Add `source_version` field (nullable string — module/lib version that produced the entry).
- Add `metadata` field (nullable array — free-form structured data: reason, request_ip, user_agent, module_version; credentials/secrets prohibited by contract, not just documentation).
- Make `bot_id` nullable in `AuditTarget` (platform-scope operations like `ModuleInstalled` may have no bot).

#### 1.3 AuditFailurePolicy

Create `src/AuditFailurePolicy.php`:

```php
enum AuditFailurePolicy: string
{
    case FailOpen = 'fail_open';
    case FailClosed = 'fail_closed';
}
```

Create `src/AuditFailurePolicyResolver.php`:

```php
interface AuditFailurePolicyResolver
{
    public function resolve(AuditEntry $entry): AuditFailurePolicy;
}
```

Default resolver maps operations to policies:

| Operation Category | Default Policy |
|---|---|
| `module.runtime.*`, telemetry, diagnostics | `FAIL_OPEN` |
| `module.enabled`, `module.disabled` | `FAIL_OPEN` (configurable) |
| `access.*`, `bot.*`, `role.*` | `FAIL_CLOSED` |
| Any operation tagged `security: true` in metadata | `FAIL_CLOSED` |

`AuditSinkContract` remains simple (`append(): void`). The policy is enforced at the call site (service layer or listener), NOT inside the sink. This keeps the sink composable and the policy explicit.

#### 1.4 CorrelationContext

Create `src/CorrelationContext.php`:

```php
interface CorrelationContext
{
    public function id(): ?string;
}
```

Create `src/StaticCorrelationContext.php` — simple implementation holding an ID.

This is NOT HTTP-specific. Sources that set it:

- HTTP middleware (`X-Correlation-Id` header or generated ULID)
- CLI command (command signature or generated ULID)
- Queue worker (job correlation ID or parent correlation)
- Telegram update handler (update_id or generated ULID)
- Daemon/tickable (tick ID or generated ULID)

Audit entries pick up correlation from the context, not from HTTP directly.

#### 1.5 Tests

- Operation enum: all values round-trip through JSON, dot-notation filtering works.
- AuditEntry: nullable bot_id, metadata field, source_version field.
- AuditFailurePolicyResolver: default mapping is correct, custom resolver overrides work.
- CorrelationContext: static implementation works, nullable ID.

---

### Phase 2 — Laravel Integration Shell

Goal: module is registrable in Laravel, bound in the container, configurable.

#### 2.1 Service Provider

Create `src/Laravel/AuditServiceProvider.php`:
- **Owns ALL bindings** — `AppServiceProvider` never references `BAGArt\TelegramBotAudit\*`.
- Registers `AuditSinkContract` as singleton, resolved from `config('audit.driver')`.
- Registers `AuditQueryContract` as singleton, resolved from same driver.
- Registers `CorrelationContext` as singleton (default: `StaticCorrelationContext`).
- Registers `AuditFailurePolicyResolver` as singleton (default resolver).
- Publishes `config/audit.php` via `$this->publishes()`.
- Registers migration path via `$this->loadMigrationsFrom()`.
- Registers event listeners in `boot()` (Phase 4).

```php
// Binding resolution:
AuditSinkContract
       │
       ▼
AuditServiceProvider (reads config('audit.driver'))
       │
       ├── 'database' → DatabaseAuditSink
       └── 'memory'   → InMemoryAuditSink
```

#### 2.2 Config File

Create `config/audit.php`:
```php
return [
    'driver' => env('AUDIT_DRIVER', 'database'),

    'database' => [
        'connection' => env('AUDIT_DB_CONNECTION'),
        'table' => 'audit_entries',
    ],

    'failure_policy' => [
        'default' => env('AUDIT_FAILURE_DEFAULT', 'fail_open'),
        'operations' => [
            // 'access.grant.created' => 'fail_closed',
            // 'bot.token.rotated' => 'fail_closed',
        ],
    ],

    'retention' => [
        'days' => env('AUDIT_RETENTION_DAYS', 365),
    ],
];
```

No `correlation.header` — correlation is transport-agnostic via `CorrelationContext`.

#### 2.3 Provider Registration

- Add `AuditServiceProvider` to `bootstrap/providers.php` (host-level, Composer auto-discovery).
- Add to `composer.prod.json` with VCS repo.
- Do NOT add to `config/tg_modules.php` — audit is not a `TgModuleContract` plugin.

#### 2.4 Tests

- Feature test: `AuditServiceProvider` binds `AuditSinkContract` and `AuditQueryContract`.
- Feature test: config is publishable.
- Feature test: driver switching (database → memory) resolves correct implementation.

---

### Phase 3 — Persistent Storage

Goal: audit entries survive restarts, are queryable, support tenant scoping.

#### 3.1 Database Migration

Create migration for `audit_entries` table:

```
id              ULID, PK
actor_type      string, index
actor_id        string, index
bot_id          nullable string, index       — NULL = platform scope
chat_id         nullable bigint, index
subject_type    string, index
subject_id      string, index
operation       string, index                — canonical dot-notation string
old_state       json, nullable
new_state       json, nullable
source          string
source_version  nullable string
metadata        json, nullable               — free-form; credentials forbidden by contract
correlation_id  nullable string, index
schema_version  smallint, default 1
sequence        bigint, unsigned, auto-increment — for ordering / future tamper-evidence
occurred_at     timestamp, index
created_at      timestamp
```

Indexes:
- Composite: `(bot_id, operation, occurred_at)` — most common query pattern.
- Composite: `(bot_id, actor_type, actor_id)` — actor history.
- Composite: `(bot_id, subject_type, subject_id)` — subject history.
- Single: `operation`, `occurred_at`, `correlation_id`, `sequence`.

#### 3.2 Database Sink Implementation

Create `src/Laravel/DatabaseAuditSink.php`:
- Implements `AuditSinkContract`.
- Inserts into `audit_entries` via Query Builder.
- Reads connection/table from `config('audit.database.*')`.
- Throws on infrastructure failure — caller decides policy via `AuditFailurePolicyResolver`.

#### 3.3 Eloquent Model (query-side)

Create `src/Laravel/AuditEntryModel.php`:
- Table `audit_entries`.
- Casts `old_state`/`new_state`/`metadata` to `array`.
- Scopes: `forBot($query, $botId)`, `forOperation($query, $op)`, `after($query, $date)`, `before($query, $date)`.
- Method `toDomain(): \BAGArt\TelegramBotAudit\AuditEntry` — converts model back to domain DTO.

#### 3.4 Tests

- Feature test: insert via `DatabaseAuditSink`, verify row, verify domain round-trip.
- Test: tenant scoping (bot_id isolation — platform entries with NULL bot_id).
- Test: metadata field persists and round-trips.
- Test: sequence auto-increments correctly.
- Test: config-driven connection resolution.

---

### Phase 4 — Audit Wiring (Authoritative + Observational)

Goal: both imperative and event-driven audit paths produce entries.

#### 4.1 Authoritative Audit (command → append → event)

For security-sensitive operations, audit is a synchronous side effect of the command, NOT an event listener:

```
Command / domain operation
       │
       ▼
  state transition (DB transaction)
       │
       ▼
  AuditSinkContract::append()  ← synchronous, in same transaction scope
       │
       ▼
  event dispatch (post-commit)
```

Callers check `AuditFailurePolicyResolver::resolve()` before append:

```php
$policy = $resolver->resolve($entry);
try {
    $sink->append($entry);
} catch (\Throwable $e) {
    if ($policy === AuditFailurePolicy::FailClosed) {
        throw new AuditException("Audit required but failed: {$e->getMessage()}", previous: $e);
    }
    // FAIL_OPEN: log and continue
    Log::warning('audit.append.failed', ['error' => $e->getMessage(), 'operation' => $entry->operation]);
}
```

Used by: access decisions, bot token rotation, bot deletion, role grants, security-critical module operations.

#### 4.2 Observational Audit (event → listener → append)

For lifecycle telemetry and non-critical operations, event listeners produce audit entries:

Create `src/Laravel/Listeners/RecordLifecycleAudit.php`:
- Receives lifecycle event via Laravel event dispatcher.
- Extracts `moduleId`, `botId`, `actor`, `operationId` from event payload.
- Builds `AuditEntry` with `AuditActor`, `AuditTarget`, operation string.
- Appends to `AuditSinkContract` with `FAIL_OPEN` policy (observational, never blocks).
- Registered in `AuditServiceProvider::boot()` for each lifecycle event class.

#### 4.3 Lifecycle Event Mapping

| Engine Event | Audit Operation | Mode |
|---|---|---|
| `BotModuleEnabled` | `module.enabled` | Authoritative (in lifecycle method) |
| `BotModuleDisabled` | `module.disabled` | Authoritative |
| `BotModuleBlocked` | `module.suspended` | Authoritative |
| `BotModuleConnected` | `module.connected` | Authoritative |
| `BotModuleDisconnected` | `module.disconnected` | Authoritative |
| `ModuleInstalled` | `module.installed` | Authoritative |
| `ModuleUninstalled` | `module.uninstalled` | Authoritative |
| `ModuleRuntimeFailed` | `module.runtime.failed` | Observational |
| `ModuleRuntimeRecovered` | `module.runtime.recovered` | Observational |

#### 4.4 Correlation Wiring

- `AuditServiceProvider` registers `CorrelationContext` as singleton.
- HTTP middleware sets context from `X-Correlation-Id` header (or generates ULID).
- CLI commands set context from command signature or generate ULID.
- Queue workers set context from job properties.
- `AuditEntry::now()` accepts `CorrelationContext` and calls `->id()`.

#### 4.5 Tests

- Feature test: authoritative append in lifecycle method → entry exists, commit happens.
- Feature test: append failure + FAIL_CLOSED → exception thrown, state not committed.
- Feature test: append failure + FAIL_OPEN → warning logged, operation succeeds.
- Feature test: event listener produces observational entry.
- Feature test: correlation propagates from HTTP context to audit entry.
- Feature test: correlation propagates from CLI context to audit entry.

---

### Phase 5 — Query/Read API

Goal: admin UI, Telegram bot, and CLI can query audit history.

#### 5.1 Query Contract

Create `src/AuditQueryContract.php`:
```php
interface AuditQueryContract
{
    /** @return iterable<AuditEntry> */
    public function query(AuditQueryFilter $filter): iterable;

    public function count(AuditQueryFilter $filter): int;
}
```

#### 5.2 Query Filter DTO

Create `src/AuditQueryFilter.php`:
```php
final readonly class AuditQueryFilter
{
    public function __construct(
        public ?string $botId = null,       // NULL = platform scope
        public ?string $actorType = null,
        public ?string $actorId = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $operation = null,    // exact match or prefix with '*'
        public ?string $source = null,
        public ?string $correlationId = null,
        public ?string $after = null,        // ISO 8601
        public ?string $before = null,       // ISO 8601
        public int $limit = 100,
        public int $offset = 0,
    ) {}
}
```

#### 5.3 Database Query Implementation

Create `src/Laravel/DatabaseAuditQuery.php`:
- Implements `AuditQueryContract`.
- Builds query from `AuditQueryFilter`.
- `bot_id = NULL` → platform scope; `bot_id = X` → bot scope.
- Operation filter supports prefix matching: `module.*` matches all module operations.
- Returns domain `AuditEntry` DTOs via `AuditEntryModel::toDomain()`.

#### 5.4 Admin API Controller (optional, Phase 5+)

Create `src/Laravel/Http/Controllers/AuditController.php`:
- `index()` — paginated audit entries for a bot (or platform scope).
- `show($id)` — single entry.
- Registered under `/admin/{botId}/audit` and `/admin/audit` route groups.
- Gated by platform admin middleware.

#### 5.5 Tests

- Feature test: query by bot, operation prefix, time range — correct entries.
- Feature test: platform scope (NULL bot_id) returns platform entries.
- Feature test: tenant scoping enforced (can't cross bot boundaries).
- Feature test: prefix matching (`module.*` returns all module operations).
- Feature test: pagination works correctly.

---

### Phase 6 — Cross-Module Integration

Goal: all platform modules actually use the audit system.

#### 6.1 Engine Integration

- Engine lifecycle methods inject `AuditSinkContract` + `AuditFailurePolicyResolver`.
- Lifecycle operations produce authoritative audit entries (synchronous, in transaction scope).
- Runtime events produce observational entries (via listener).

#### 6.2 Management Module

- Bot creation → `bot.created` (authoritative).
- Bot deletion → `bot.deleted` (authoritative, FAIL_CLOSED).
- Bot token rotation → `bot.token.rotated` (authoritative, FAIL_CLOSED).

#### 6.3 Access Module

- `AccessDecision` already mentions audit in comments — wire to `AuditSinkContract`.
- Grant creation → `access.grant.created` (authoritative, FAIL_CLOSED).
- Grant revocation → `access.grant.revoked` (authoritative, FAIL_CLOSED).

#### 6.4 Menu Module

- Role grants via `GrantCommand` → `role.granted` (authoritative).
- Role revocation → `role.revoked` (authoritative).

#### 6.5 Proxy Module

- Proxy module has its own `AuditDeliveryQueue` — separate concern (probe results, not platform lifecycle). No integration needed. If cross-reference is required later, use `correlation_id`.

#### 6.6 Tests

- For each module integration: feature test that the expected audit entry is produced.
- Feature test: FAIL_CLOSED operations actually block on audit failure.

---

### Phase 7 — Retention & Housekeeping

Goal: audit data doesn't grow unbounded, old entries are pruned safely.

#### 7.1 Retention Pruner

Create `src/Laravel/RetentionPruner.php`:
- Deletes entries older than `config('audit.retention.days')`.
- Dry-run mode (returns count without deleting).
- Orders by `sequence` to avoid gaps in tamper-evidence if enabled later.

#### 7.2 Artisan Command

Create `src/Laravel/Console/Commands/AuditPruneCommand.php`:
- `php artisan audit:prune [--days=N] [--dry-run]`
- Registered in `AuditServiceProvider`.

#### 7.3 Scheduled Execution

Register in `AuditServiceProvider::boot()`:
```php
$schedule->command('audit:prune')->daily();
```

#### 7.4 Tests

- Feature test: prune removes old entries, keeps recent.
- Feature test: dry-run doesn't delete.

---

### Phase 8 — Observability

Goal: audit system is self-monitoring.

#### 8.1 Metrics

- Counter: `audit.entries.appended` (by operation, by bot).
- Counter: `audit.entries.failed` (by operation, by failure policy).
- Histogram: `audit.append.latency`.

#### 8.2 Health Check

- Sink write/read health registered as platform health probe.

#### 8.3 Alerting

- Alert if `audit.entries.failed` with `FAIL_CLOSED` policy > 0.
- Alert if append latency p99 > 100ms.

---

## Deferred: Tamper-Evidence (Hash Chain)

**Decision: defer to separate ADR after real requirement emerges.**

Current reasoning:
- Hash chain requires serialized sequence (`bot_id + sequence`) and atomic `previous_hash` acquisition.
- Concurrent appends break naive "read last hash" approaches.
- Without a concrete threat model and compliance requirement, the complexity is not justified.
- The `sequence` column in the migration preserves the option for future implementation.
- A proper design needs: optimistic locking or SELECT FOR UPDATE, gap detection, and verification CLI.

When the requirement materializes, create `docs/adr/audit-hash-chain.md` with:
- Threat model
- Concurrency strategy (optimistic vs pessimistic)
- Sequence partitioning (global vs per-bot)
- Verification command design

---

## Priority Matrix

| Phase | Priority | Effort | Depends On |
|---|---|---|---|
| 1 — Domain Contract Cleanup & Operation Model | **P0** | Medium | — |
| 2 — Laravel Integration Shell | **P0** | Small | Phase 1 |
| 3 — Persistent Storage | **P0** | Medium | Phase 1, 2 |
| 4 — Audit Wiring (Authoritative + Observational) | **P1** | Medium | Phase 1, 2, 3 |
| 5 — Query/Read API | **P1** | Medium | Phase 3 |
| 6 — Cross-Module Integration | **P1** | Large | Phase 1–4 |
| 7 — Retention & Housekeeping | **P2** | Small | Phase 3 |
| 8 — Observability | **P2** | Small | Phase 2 |

---

## Immediate Next Steps

1. Update domain DTOs: nullable `bot_id`, add `source_version`, `metadata` to `AuditEntry`.
2. Create `Operation` enum with dot-notation values.
3. Create `AuditFailurePolicy` + `AuditFailurePolicyResolver`.
4. Create `CorrelationContext` interface.
5. Create `AuditServiceProvider` + `config/audit.php` — sole Laravel registration point.
6. Create database migration + `DatabaseAuditSink`.
7. Add `composer.prod.json` entry.
8. Wire Engine lifecycle methods (authoritative audit).

---

## Architecture Decision Records

### ADR-001: Domain contract + Laravel adapter, not a TgModuleContract plugin

The audit package is a **domain contract library with a Laravel adapter layer**, not a module in the `TgModuleContract` sense. It does not implement `descriptor()`, does not appear in `config/tg_modules.php`, and has no bot commands or routes.

Its `AuditServiceProvider` registers via standard Composer/Laravel auto-discovery (listed in `bootstrap/providers.php`). This keeps audit as infrastructure — injected into modules that need it, invisible to the module engine.

### ADR-002: Append-only sink, separate query contract

`AuditSinkContract` has only `append()`. Querying is `AuditQueryContract`. This enforces "audit is a sink, not a peer" — producers write, readers read, never the same interface. Callers cannot accidentally read from the write path or vice versa.

### ADR-003: Nullable bot_id — platform scope vs bot scope

`AuditTarget.botId` is nullable. `NULL` = platform scope (e.g. `module.installed`), non-NULL = bot scope (e.g. `module.enabled`). This reflects the reality that some operations act on the platform as a whole, not on a specific bot.

### ADR-004: Schema versioning from day one

`AuditEntry.SCHEMA_VERSION = 1` with `fromJsonV1()` ensures forward-compatible evolution. Future changes add `fromJsonV2()` etc. without breaking persisted entries.

### ADR-005: AuditFailurePolicy — explicit fail-open/fail-closed per operation

Audit failure behavior is NOT "always swallow exceptions." It is an explicit policy per operation category, resolved at the call site via `AuditFailurePolicyResolver`. Security-sensitive operations (`access.*`, `bot.*`, `role.*`) default to `FAIL_CLOSED`. Observational operations (`module.runtime.*`) default to `FAIL_OPEN`. The policy is enforced by the caller, NOT by the sink — keeping the sink composable and the policy explicit.

### ADR-006: Authoritative vs observational audit

Two audit paths exist:
- **Authoritative**: command → state transition → synchronous `append()` → event. Used for security-critical operations where audit must succeed for the operation to be considered complete.
- **Observational**: event → listener → `append()` with `FAIL_OPEN`. Used for lifecycle telemetry where missing an audit entry is degraded but not fatal.

This split prevents event-listener-based audit from becoming a silent single point of failure for security operations.

### ADR-007: CorrelationContext is transport-agnostic

Correlation IDs come from many sources (HTTP headers, CLI signatures, queue job IDs, daemon tick IDs). `CorrelationContext` is an interface, not an HTTP middleware. The HTTP middleware is one implementation that reads `X-Correlation-Id`; other transports set the context differently. `AuditEntry` consumes `CorrelationContext`, not raw strings.

### ADR-008: Operation model — enum for platform, strings for modules

`Operation` enum covers platform-reserved operations with dot-notation values (`module.enabled`, `bot.created`, `access.grant.created`). Module-specific operations use namespaced strings (`proxy.probe.completed`). `AuditEntry` accepts `Operation|string` and stores the canonical string. This means adding a new module's audit operations does NOT require changing the audit package.
