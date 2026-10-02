# telegram-platform-audit

Append-only audit sink for the Telegram bot platform.

## Essence

Every state-changing operation across the platform produces typed, immutable `AuditEntry` records. Audit is a **sink** — producers write, nothing reads back. The contract is append-only by design.

## Services

| Service | Role |
|---|---|
| `AuditSinkContract` | Append-only write (`append`) |
| `AuditQueryContract` | Read-only query (`query`, `count`) |
| `CorrelationContext` | Request-scoped correlation ID |
| `AuditFailurePolicyResolver` | Per-operation fail-open / fail-closed policy |
| `CountingAuditSink` | Decorator — in-memory counters + latency histogram |
| `AuditCounters` | Real-time counters (by operation, by bot, failures, latency p50/p95/p99) |
| `AuditMetricsCollector` | DB aggregation (24h) + real-time counters snapshot |
| `AuditHealthProbe` | Sink writability check (healthy / degraded) |
| `RetentionPruner` | Deletes entries older than retention period |
| `AuditRecording` | Helper trait for direct audit recording from services |
| `AuditHasher` | SHA-256 hash computation for tamper-evident chain |

## Domains (Event Listeners)

| Domain | Events Consumed | Operations Recorded |
|---|---|---|
| Access Control | `GrantCreated`, `GrantRevoked` | `access.grant.created`, `access.grant.revoked` |
| Bot Management | `BotCreated`, `BotDeleted`, `BotTokenRotated`, `BotModuleSettingChanged` | `bot.created`, `bot.deleted`, `bot.token.rotated`, `module.enablement.changed`, `module.settings.changed` |
| Module Engine | `BotModuleEnabled`, `BotModuleDisabled` | `module.runtime.enabled`, `module.runtime.disabled` |

Source modules are fully decoupled — they fire Laravel events; audit catches them. No module imports `BAGArt\TelegramBotAudit\*`.

## DTOs

**AuditEntry** (`SCHEMA_VERSION = 2`): `id`, `actor` (AuditActor), `target` (AuditTarget), `operation` (Operation|string), `oldState`, `newState`, `source`, `occurredAt`, `correlationId`, `metadata`, `hash`, `prevHash`.

**AuditQueryFilter**: `botId`, `actorType`, `actorId`, `subjectType`, `subjectId`, `operation` (glob `*`), `source`, `correlationId`, `after`, `before`, `limit`, `offset`.

## Enums

- **Operation**: `BotCreated`, `BotDeleted`, `BotTokenRotated`, `ModuleEnabled`, `ModuleDisabled`, `AccessGrantCreated`, `AccessGrantRevoked`, + namespaced strings
- **AuditFailurePolicy**: `FailOpen`, `FailClosed`

## Drivers

- **database** — production, `audit_entries` table
- **memory** — tests, lightweight hosts

## Observability

- **Real-time**: `AuditCounters` via `CountingAuditSink` decorator — append counts by operation/bot, failure counts, latency histogram
- **Historical**: `AuditMetricsCollector` — SQL aggregation over 24h (by operation, source, actor type)
- **Health**: `AuditHealthProbe` — sink writability check

## Tamper-Evidence

SHA-256 hash chain. Each entry's hash covers its serialized data + the previous entry's hash.
Chain ordered by `sequence` column (monotonic append counter). Verify with `php artisan audit:verify`.

## Correlation

- **HTTP**: `CorrelationMiddleware` in host `web` group — `X-Correlation-Id` / `X-Request-Id` headers, UUID fallback
- **CLI**: Static correlation ID
- **Queue**: Job ID

## Configuration

Host `config/audit.php` with fail-closed for critical operations (`access.grant.*`, `bot.created`, `bot.deleted`, `bot.token.rotated`).

```env
AUDIT_DRIVER=database
AUDIT_RETENTION_DAYS=365
AUDIT_FAILURE_DEFAULT=fail_open
```

## Commands

| Command | Description | Schedule |
|---|---|---|
| `audit:prune` | Delete entries older than retention | Daily 03:00 |
| `audit:verify` | Verify hash chain integrity (reports breaks) | Manual |

## Database

Table `audit_entries`: `id`, `hash` (nullable, SHA-256), `prev_hash` (nullable), `actor_*`, `bot_id`, `chat_id`, `subject_*`, `operation`, `old_state` (json), `new_state` (json), `metadata` (json), `source`, `correlation_id`, `schema_version` (default 2), `sequence` (auto-incrementing chain order), `occurred_at`, `created_at`.

Indexes: `(bot_id, operation, occurred_at)`, `(bot_id, actor_type, actor_id)`, `(bot_id, subject_type, subject_id)`.

## Usage

```php
$sink = app(AuditSinkContract::class);
$sink->append(AuditEntry::now(
    id: AuditEntry::generateId(),
    actor: new AuditActor(type: 'user', id: '123'),
    target: new AuditTarget(botId: 'bot1', subjectType: 'module', subjectId: 'antispam'),
    operation: 'module.enabled',
    newState: ['enabled' => true],
    source: 'management',
));

$query = app(AuditQueryContract::class);
$entries = $query->query(new AuditQueryFilter(botId: 'bot1', operation: 'module.*'));
```

## Testing

```bash
vendor/bin/pest --testsuite audit-unit --testsuite audit-feature
# or from module:
cd misc/BAGArt/telegram-platform-audit && composer test
```
