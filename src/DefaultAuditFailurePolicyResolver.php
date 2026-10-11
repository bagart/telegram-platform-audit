<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Default failure policy resolver.
 *
 * Maps operation prefixes to FAIL_OPEN / FAIL_CLOSED. The default can be
 * overridden per-operation via config('audit.failure_policy.operations').
 *
 * Default rules:
 *  - module.runtime.*, telemetry, diagnostics → FAIL_OPEN
 *  - module.enabled, module.disabled          → FAIL_OPEN (configurable)
 *  - access.*, bot.*, role.*                  → FAIL_CLOSED
 */
final class DefaultAuditFailurePolicyResolver implements AuditFailurePolicyResolver
{
    /** @var array<string, AuditFailurePolicy> */
    private array $overrides;

    /** @var array<string, AuditFailurePolicy> declared prefix => policy, first declared hit wins */
    private array $prefixes = [];

    /**
     * @param  array<string, string>  $operationOverrides  operation => 'fail_open'|'fail_closed'
     * @param  string  $defaultPolicy  fallback policy for unmatched operations
     * @param  array<string, string>  $operationPrefixes  operation prefix => 'fail_open'|'fail_closed';
     *                                                    consulted after exact overrides,
     *                                                    before the hardcoded arms
     */
    public function __construct(
        array $operationOverrides = [],
        private string $defaultPolicy = AuditFailurePolicy::FailOpen->value,
        array $operationPrefixes = [],
    ) {
        foreach ($operationOverrides as $operation => $policy) {
            $this->overrides[$operation] = AuditFailurePolicy::from($policy);
        }

        foreach ($operationPrefixes as $prefix => $policy) {
            $this->prefixes[$prefix] = AuditFailurePolicy::from($policy);
        }
    }

    public function resolve(AuditEntry $entry): AuditFailurePolicy
    {
        $operation = $entry->operation;

        // Exact match override
        if (isset($this->overrides[$operation])) {
            return $this->overrides[$operation];
        }

        // Config-declared prefixes, declared order, first hit wins
        foreach ($this->prefixes as $prefix => $policy) {
            if (str_starts_with($operation, $prefix)) {
                return $policy;
            }
        }

        // Hardcoded platform arms (most specific first)
        return match (true) {
            str_starts_with($operation, 'access.') => AuditFailurePolicy::FailClosed,
            str_starts_with($operation, 'bot.') => AuditFailurePolicy::FailClosed,
            str_starts_with($operation, 'role.') => AuditFailurePolicy::FailClosed,
            str_starts_with($operation, 'module.runtime.') => AuditFailurePolicy::FailOpen,
            default => AuditFailurePolicy::from($this->defaultPolicy),
        };
    }
}
