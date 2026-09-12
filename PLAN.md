# Audit Module — Remaining Work

> Revised 2026-09-04 after phase audit.
> Phases 1–3 shipped (domain DTOs, contracts, Laravel integration, DB storage, prune command).

---

## Current State

| Aspect | Status |
|---|---|
| Domain DTOs (`AuditEntry`, `AuditActor`, `AuditTarget`, `Operation`) | ✅ |
| Contracts (`AuditSinkContract`, `AuditQueryContract`) | ✅ |
| `InMemoryAuditSink` | ✅ |
| `AuditFailurePolicy` + `DefaultAuditFailurePolicyResolver` | ✅ |
| `CorrelationContext` + `StaticCorrelationContext` | ✅ |
| `AuditServiceProvider` (singleton bindings, config, migrations) | ✅ |
| `config/audit.php` | ✅ |
| Database migration (`audit_entries`) | ✅ |
| `DatabaseAuditSink` + `DatabaseAuditQuery` | ✅ |
| `RetentionPruner` + `audit:prune` command | ✅ |
| Unit + integration tests | ✅ |
| Authoritative audit wiring (command → append) | ❌ |
| Observational audit wiring (event → listener) | ❌ |
| Cross-module integration | ❌ |
| Query/Read API (admin controller) | ❌ |
| Observability (metrics, health) | ❌ |
| `composer.prod.json` entry | ❌ |
| README | ❌ |

---

## Remaining Phases

### Phase 4 — Audit Wiring (Authoritative + Observational)

Goal: imperative and event-driven audit paths produce entries.

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

**4.2 Observational audit** — lifecycle telemetry and non-critical operations:

Create `src/Laravel/Listeners/RecordLifecycleAudit.php`:
- Receives lifecycle event, extracts `moduleId`, `botId`, `actor`, `operationId`.
- Builds `AuditEntry`, appends via `AuditSinkContract` with `FAIL_OPEN`.
- Registered in `AuditServiceProvider::boot()` for each lifecycle event class.

**4.3 Lifecycle event mapping:**

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

**4.4 Correlation wiring:**
- HTTP middleware sets `CorrelationContext` from `X-Correlation-Id` (or ULID).
- CLI commands set context from command signature or ULID.
- Queue workers set context from job properties.
- `AuditEntry::now()` accepts `CorrelationContext`.

---

### Phase 5 — Query/Read API

Goal: admin UI, Telegram bot, CLI can query audit history.

- `AuditQueryContract` + `AuditQueryFilter` exist but need an HTTP entry point.
- Create `src/Laravel/Http/Controllers/AuditController.php`:
  - `index()` — paginated entries for a bot (or platform scope).
  - `show($id)` — single entry.
  - Routes under `/admin/{botId}/audit` and `/admin/audit`.
  - Gated by platform admin middleware.

---

### Phase 6 — Cross-Module Integration

Goal: all platform modules actually use the audit system.

| Module | Integration |
|---|---|
| Engine lifecycle | Inject `AuditSinkContract` + `AuditFailurePolicyResolver` into lifecycle methods. Authoritative entries in transaction scope. |
| Management | Bot creation → `bot.created`, deletion → `bot.deleted`, token rotation → `bot.token.rotated` (all authoritative, FAIL_CLOSED). |
| Access | Grant creation → `access.grant.created`, revocation → `access.grant.revoked` (authoritative, FAIL_CLOSED). |
| Menu | Role grants → `role.granted`, revocation → `role.revoked` (authoritative). |
| Proxy | Own `AuditDeliveryQueue` — separate concern. Cross-reference via `correlation_id` only if needed. |

---

### Phase 7 — Retention & Housekeeping

`RetentionPruner` + `audit:prune` command shipped. Remaining:

- Register schedule in `AuditServiceProvider::boot()`:
  ```php
  $schedule->command('audit:prune')->daily();
  ```
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

## Priority Matrix

| Phase | Priority | Depends On |
|---|---|---|
| 4 — Audit Wiring | **P1** | Phases 1–3 (done) |
| 5 — Query/Read API | **P1** | Phase 3 (done) |
| 6 — Cross-Module Integration | **P1** | Phases 1–4 |
| 7 — Retention & Housekeeping | **P2** | Phase 3 (done) |
| 8 — Observability | **P2** | Phase 2 (done) |

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
