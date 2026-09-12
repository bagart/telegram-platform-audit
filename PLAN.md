# Audit Module — Remaining Work

> Revised 2026-09-12. Phases 1–6 shipped. Phase 7 partial (schedule done, composer.prod pending). Phase 8 outstanding.

---

## Current State (~75%)

| Aspect | Status |
|---|---|
| Domain DTOs (`AuditEntry`, `AuditActor`, `AuditTarget`, `Operation`) | ✅ |
| `AuditEntry::generateId()` | ✅ |
| Contracts (`AuditSinkContract`, `AuditQueryContract`) | ✅ |
| `InMemoryAuditSink` | ✅ |
| `AuditFailurePolicy` + `DefaultAuditFailurePolicyResolver` | ✅ |
| `CorrelationContext` + `MutableCorrelationContext` + `StaticCorrelationContext` | ✅ |
| `CorrelationMiddleware` (HTTP request correlation) | ✅ |
| `AuditServiceProvider` (singleton bindings, config, migrations, routes) | ✅ |
| `config/audit.php` | ✅ |
| Database migration (`audit_entries`) | ✅ |
| `DatabaseAuditSink` + `DatabaseAuditQuery` | ✅ |
| `RetentionPruner` + `audit:prune` command | ✅ |
| Schedule (daily at 03:00) | ✅ |
| Observational audit: `RecordAccessControlEvents` listener | ✅ |
| Observational audit: `RecordModuleLifecycleEvents` listener | ✅ |
| Admin controller (index + show) | ✅ |
| `AuditRecording` trait (authoritative helper) | ✅ |
| Unit + integration tests (58 tests) | ✅ |
| README | ✅ |
| Authoritative audit wiring (synchronous in transaction) | ❌ Phase 4.1 |
| Full lifecycle event mapping (all engine events) | ❌ Phase 4.3 |
| Cross-module event subscriptions (management, menu, proxy) | ❌ Phase 6.2 |
| `composer.prod.json` entry | ❌ Phase 7 |
| Observability (metrics, health probes) | ❌ Phase 8 |

---

## Remaining Phases

### Phase 4 — Audit Wiring (partial)

**4.1 Authoritative audit** — for security-sensitive operations, audit is a
synchronous side effect of the command (NOT a listener). Modules use
`AuditRecording` trait for this.

**4.3 Remaining lifecycle event mapping** — engine events need to be
dispatched by the module engine before the audit module can subscribe.

### Phase 6.2 — Cross-Module Event Subscriptions

Requires the following modules to dispatch domain events:

| Module | Events needed |
|---|---|
| Management | `BotCreated`, `BotDeleted`, `BotTokenRotated` |
| Engine | `BotModuleEnabled`, `BotModuleDisabled`, `ModuleRuntimeFailed` |
| Menu | `RoleGranted`, `RoleRevoked` |

Once events exist, the audit module registers listeners in `AuditServiceProvider::boot()`.

### Phase 7 — Retention & Housekeeping

- Add `composer.prod.json` entry.

### Phase 8 — Observability

- Counters: `audit.entries.appended` (by operation, by bot), `audit.entries.failed` (by operation, by policy).
- Histogram: `audit.append.latency`.
- Sink write/read health as platform health probe.

---

## Deferred: Tamper-Evidence (Hash Chain)

Deferred until concrete threat model / compliance requirement emerges.
