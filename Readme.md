# telegram-platform-audit

Audit module for the Telegram bot platform — append-only audit sink contract with typed, versioned audit entries.

## Architecture

```
Producers (access, management, engine, modules)
         → AuditSinkContract::append(AuditEntry)
         → AuditEntries (immutable, versioned)
         ← AuditQueryContract::query(AuditQueryFilter)
```

Audit is a **sink, not a peer**: producers write events into it, nothing reads back into producers. The contract is append-only by design — there is no update or delete method.

## Contracts

| Contract | Purpose |
|---|---|
| `AuditSinkContract` | Append-only write (single `append` method) |
| `AuditQueryContract` | Read-only query (query + count) |
| `CorrelationContext` | Transport-agnostic correlation ID |
| `AuditFailurePolicyResolver` | Per-operation failure policy (fail_open / fail_closed) |

## DTOs

### AuditEntry (versioned, `SCHEMA_VERSION = 1`)

| Field | Description |
|---|---|
| `id` | Unique entry ID (ULID/UUID) |
| `actor` | `AuditActor` — who performed the operation |
| `target` | `AuditTarget` — bot/chat scope + subject |
| `operation` | `Operation` enum or namespaced string |
| `oldState` | State before (nullable) |
| `newState` | State after (nullable) |
| `source` | Producing subsystem (e.g. "access-control", "engine") |
| `occurredAt` | ISO 8601 timestamp |
| `correlationId` | Request-scoped correlation ID |
| `metadata` | Free-form structured data (credentials forbidden) |

### AuditQueryFilter

| Field | Description |
|---|---|
| `botId` | Filter by bot (null = platform-level) |
| `actorType` | Filter by actor type |
| `actorId` | Filter by actor ID |
| `subjectType` | Filter by subject type |
| `subjectId` | Filter by subject ID |
| `operation` | Filter by operation (supports `*` glob) |
| `source` | Filter by producing subsystem |
| `correlationId` | Filter by correlation ID |
| `after` / `before` | Time range |
| `offset` / `limit` | Pagination |

## Enums

| Enum | Values |
|---|---|
| `Operation` | `BotCreated`, `BotUpdated`, `BotDeleted`, `BotTokenRotated`, `ModuleEnabled`, `ModuleDisabled`, `AccessGrantCreated`, `AccessGrantRevoked`, `SettingsChanged` |
| `AuditFailurePolicy` | `FailOpen`, `FailClosed` |

## Drivers

| Driver | Use Case |
|---|---|
| `database` | Production — persists to `audit_entries` table |
| `memory` | Tests, lightweight hosts |

## Correlation

- **HTTP:** `CorrelationMiddleware` extracts `X-Correlation-Id` or `X-Request-Id` headers, generates UUID if missing
- **CLI:** Static correlation ID from command signature
- **Queue:** Job ID as correlation

## Listeners

| Listener | Trigger |
|---|---|
| `RecordAccessControlEvents` | `GrantCreated` / `GrantRevoked` domain events |
| `RecordModuleLifecycleEvents` | Module enable/disable/settings-changed events |

## Scheduling

`audit:prune` command runs daily at 03:00 (configured in `AuditServiceProvider`).

Retention: configurable via `config/audit.php` → `retention.days` (default: 365).

## Configuration

Copy or merge `config/audit.php` into the host:

```bash
cp misc/BAGArt/telegram-platform-audit/config/audit.php config/audit.php
```

```env
AUDIT_DRIVER=database
AUDIT_RETENTION_DAYS=365
AUDIT_FAILURE_DEFAULT=fail_open
```

## Database

Run migrations:
```bash
php artisan migrate
```

Creates `audit_entries` table with:
- JSON columns: `old_state`, `new_state`, `metadata`
- Indexes: `bot_id`, `actor_type`, `actor_id`, `subject_type`, `operation`, `occurred_at`, `correlation_id`
- Composite index: `(bot_id, operation, occurred_at)`

## Usage

```php
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditTarget;

$sink = app(AuditSinkContract::class);

$sink->append(AuditEntry::now(
    id: AuditEntry::generateId(),
    actor: new AuditActor(type: 'user', id: '123'),
    target: new AuditTarget(botId: 'bot1', subjectType: 'module', subjectId: 'antispam'),
    operation: 'module.enabled',
    newState: ['enabled' => true],
    source: 'management',
));
```

### Querying

```php
use BAGArt\TelegramBotAudit\AuditQueryContract;
use BAGArt\TelegramBotAudit\AuditQueryFilter;

$query = app(AuditQueryContract::class);

$entries = $query->query(new AuditQueryFilter(
    botId: 'bot1',
    operation: 'module.*',
    after: now()->subDays(7),
));

$total = $query->count(new AuditQueryFilter(botId: 'bot1'));
```

## Testing

```bash
# From host
vendor/bin/pest --testsuite Audit

# From module
cd misc/BAGArt/telegram-platform-audit
composer test
```
