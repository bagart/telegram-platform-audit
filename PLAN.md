# Audit Module — Remaining Work

> Revised 2026-09-12. Phases 1–7 shipped. Phase 8 (observability) outstanding.

---

## Current State (~85%)

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
| Cross-module event wiring: access, management, engine | ✅ |
| Admin controller (index + show) | ✅ |
| `AuditRecording` trait (authoritative helper) | ✅ |
| `composer.prod.json` entry | ✅ |
| Unit + integration tests (65 tests) | ✅ |
| README | ✅ |
| Observability (metrics, health probes) | ❌ Phase 8 |

---

## Remaining Phases

### Phase 8 — Observability

- Counters: `audit.entries.appended` (by operation, by bot), `audit.entries.failed` (by operation, by policy).
- Histogram: `audit.append.latency`.
- Sink write/read health as platform health probe.

---

## Cross-Module Event Integration

| Module | Events Dispatched | Listener |
|---|---|---|
| Access | `GrantCreated`, `GrantRevoked` | `RecordAccessControlEvents` |
| Management | `BotCreated`, `BotDeleted`, `BotTokenRotated`, `BotModuleSettingChanged` | `RecordModuleLifecycleEvents` |
| Engine | `BotModuleEnabled`, `BotModuleDisabled` | `RecordModuleLifecycleEvents` |

All event-listener mappings registered in `AuditServiceProvider::boot()`.

---

## Deferred: Tamper-Evidence (Hash Chain)

Deferred until concrete threat model / compliance requirement emerges.
