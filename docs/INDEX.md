# telegram-platform-audit — Docs Index

> `bagart/telegram-platform-audit` (BAGArt\TelegramBotAudit). Append-only audit trail with hash chain, observability, event listeners, and retention. Verified 2026-09-18.

| Need | File |
|---|---|
| Full architecture, model, observability, tamper-evidence, events, correlation, retention, config, DB schema, commands, API routes | `sdd/audit.md` |

## Source map

`AuditSinkContract.php`, `AuditEntry.php` (SCHEMA_VERSION + fromJsonV1/fromJsonV2), `AuditActor.php`, `AuditTarget.php`, `Operation.php`, `AuditFailurePolicy(+Resolver)`, `AuditQueryContract.php`, `AuditQueryFilter.php`, `CorrelationContext.php`, `StaticCorrelationContext.php`, `MutableCorrelationContext.php`, `InMemoryAuditSink.php`, `AuditException.php` — core domain.

`Laravel/` — `AuditServiceProvider.php` (singleton registration, event wiring, schedule), `DatabaseAuditSink.php` (+ hash chain), `DatabaseAuditQuery.php`, `CountingAuditSink.php` (decorator), `AuditCounters.php`, `AuditHasher.php`, `AuditHealthProbe.php`, `AuditMetricsCollector.php`, `RetentionPruner.php`, `AuditRecording.php` (trait), `Console/Commands/` (audit:prune, audit:verify), `Http/Controllers/AuditController.php`, `Listeners/` (RecordAccessControlEvents, RecordModuleLifecycleEvents), `Middleware/CorrelationMiddleware.php`.

`config/audit.php` — driver, database, failure policy, retention. `database/migrations/` — two migrations (create table + hash chain columns).
