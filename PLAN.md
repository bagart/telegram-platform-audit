# Audit Module — Remaining Work

> Revised 2026-09-12. Phases 1–3 shipped. Phase 4 partially done (listeners, middleware, correlation). Phase 7 done (scheduling).

---

## Current State (~65%)

| Aspect | Status |
|---|---|
| Domain DTOs (`AuditEntry`, `AuditActor`, `AuditTarget`, `Operation`) | ✅ |
| Contracts (`AuditSinkContract`, `AuditQueryContract`) | ✅ |
| `InMemoryAuditSink` | ✅ |
| `AuditFailurePolicy` + `DefaultAuditFailurePolicyResolver` | ✅ |
| `CorrelationContext` + `MutableCorrelationContext` + `StaticCorrelationContext` | ✅ |
| `CorrelationMiddleware` (HTTP request correlation) | ✅ |
| `AuditServiceProvider` (singleton bindings, config, migrations) | ✅ |
| `config/audit.php` | ✅ |
| Database migration (`audit_entries`) | ✅ |
| `DatabaseAuditSink` + `DatabaseAuditQuery` | ✅ |
| `RetentionPruner` + `audit:prune` command | ✅ |
| Schedule (daily at 03:00) | ✅ |
| Observational audit: `RecordAccessControlEvents` listener | ✅ |
| Observational audit: `RecordModuleLifecycleEvents` listener | ✅ |
| Unit + integration tests (53+ tests) | ✅ |
| README | ✅ |
| Authoritative audit wiring (synchronous in transaction) | ❌ Phase 4.1 |
| Full lifecycle event mapping (all engine events) | ❌ Phase 4.3 |
| Query/Read API (admin controller) | ❌ Phase 5 |
| Cross-module integration (management, menu, proxy) | ❌ Phase 6 |
| `composer.prod.json` entry | ❌ Phase 7 |
| Observability (metrics, health probes) | ❌ Phase 8 |

---

## Remaining Phases

### Phase 4 — Audit Wiring (partial)

**4.1 Authoritative audit** — for security-sensitive operations, audit is a
synchronous side effect of the command (NOT a listener):

```
Command / domain operation
       │
       ▼
  state transition (DB transaction)
       │
       ▼
  AuditSinkContract::append()  ← synchronous, in transaction scope
       │
       ▼
  event dispatch (post-commit)
```

Callers check `AuditFailurePolicyResolver::resolve()` before append:
`FAIL_CLOSED` → throw `AuditException`; `FAIL_OPEN` → log + continue.

Used by: access decisions, bot token rotation, bot deletion, role grants,
security-critical module operations.

**4.3 Remaining lifecycle event mapping:**

| Engine Event | Audit Operation | Mode |
|---|---|---|
| `BotModuleEnabled` | `module.enabled` | Authoritative |
| `BotModuleDisabled` | `module.disabled` | Authoritative |
| `BotModuleBlocked` | `module.suspended` | Authoritative |
| `BotModuleConnected` | `module.connected` | Authoritative |
| `BotModuleDisconnected` | `module.disconnected` | Authoritative |
| `ModuleInstalled` | `module.installed` | Authoritative |
| `ModuleUninstalled` | `module.uninstalled` | Authoritative |
| `ModuleRuntimeFailed` | `module.runtime.failed` | Observational |
| `ModuleRuntimeRecovered` | `module.runtime.recovered` | Observational |

---

### Phase 5 — Query/Read API

- `AuditQueryContract` + `AuditQueryFilter` exist but need an HTTP entry point.
- Create `src/Laravel/Http/Controllers/AuditController.php`:
  - `index()` — paginated entries for a bot (or platform scope).
  - `show($id)` — single entry.
  - Routes under `/admin/{botId}/audit` and `/admin/audit`.
  - Gated by platform admin middleware.

---

### Phase 6 — Cross-Module Integration

| Module | Integration |
|---|---|
| Engine lifecycle | Inject `AuditSinkContract` + `AuditFailurePolicyResolver` into lifecycle methods. Authoritative entries in transaction scope. |
| Management | Bot creation → `bot.created`, deletion → `bot.deleted`, token rotation → `bot.token.rotated` (all authoritative, FAIL_CLOSED). |
| Access | Grant creation → `access.grant.created`, revocation → `access.grant.revoked` (authoritative, FAIL_CLOSED). |
| Menu | Role grants → `role.granted`, revocation → `role.revoked` (authoritative). |
| Proxy | Own `AuditDeliveryQueue` — separate concern. Cross-reference via `correlation_id` only if needed. |

---

### Phase 7 — Retention & Housekeeping

- Add `composer.prod.json` entry.

---

### Phase 8 — Observability

- Counters: `audit.entries.appended` (by operation, by bot), `audit.entries.failed` (by operation, by policy).
- Histogram: `audit.append.latency`.
- Sink write/read health as platform health probe.
- Alert if `FAIL_CLOSED` failures > 0; alert if p99 latency > 100ms.

---

## Deferred: Tamper-Evidence (Hash Chain)

Deferred until concrete threat model / compliance requirement emerges.
`sequence` column preserves the option. See ADR-001 in git history.

---

## ADR Summary

- **ADR-001:** Domain contract library + Laravel adapter, NOT a `TgModuleContract` plugin.
- **ADR-002:** Append-only sink + separate query contract.
- **ADR-003:** Nullable `bot_id` — platform scope vs bot scope.
- **ADR-004:** `SCHEMA_VERSION` + `fromJsonV1()` for forward-compatible evolution.
- **ADR-005:** `AuditFailurePolicy` — explicit fail-open/fail-closed per operation.
- **ADR-006:** Authoritative (synchronous) vs observational (event listener) audit.
- **ADR-007:** `CorrelationContext` is transport-agnostic.
- **ADR-008:** `Operation` enum for platform ops, strings for module ops.
