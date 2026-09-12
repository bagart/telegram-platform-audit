<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditFailurePolicy;
use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\CorrelationContext;

/**
 * Helper trait for modules that need to record authoritative audit entries.
 *
 * Usage in a command handler or service:
 *
 * ```php
 * use AuditRecording;
 *
 * $this->recordAudit(
 *     operation: 'bot.created',
 *     actor: new AuditActor(type: 'user', id: $userId),
 *     target: new AuditTarget(botId: $botId, subjectType: 'bot', subjectId: $botId),
 *     newState: ['name' => $name],
 *     source: 'management',
 * );
 * ```
 *
 * The trait checks the failure policy before appending:
 * - FAIL_OPEN: logs warning and continues
 * - FAIL_CLOSED: throws AuditException
 */
trait AuditRecording
{
    /**
     * Record an authoritative audit entry.
     *
     * @param  array<string, mixed>|null  $oldState
     * @param  array<string, mixed>|null  $newState
     *
     * @throws \BAGArt\TelegramBotAudit\AuditException if policy is FAIL_CLOSED and append fails
     */
    private function recordAudit(
        string $operation,
        AuditActor $actor,
        AuditTarget $target,
        ?array $oldState = null,
        ?array $newState = null,
        string $source = 'unknown',
        ?array $metadata = null,
    ): void {
        $sink = app(AuditSinkContract::class);
        $correlation = app(CorrelationContext::class);
        $policyResolver = app(AuditFailurePolicyResolver::class);

        $entry = AuditEntry::now(
            id: AuditEntry::generateId(),
            actor: $actor,
            target: $target,
            operation: $operation,
            oldState: $oldState,
            newState: $newState,
            source: $source,
            correlationId: $correlation->id(),
            metadata: $metadata,
        );

        try {
            $sink->append($entry);
        } catch (\Throwable $e) {
            $policy = $policyResolver->resolve($operation);

            if ($policy === AuditFailurePolicy::FailClosed) {
                throw new \BAGArt\TelegramBotAudit\AuditException(
                    "Audit append failed for operation '{$operation}': {$e->getMessage()}",
                    previous: $e,
                );
            }

            \Illuminate\Support\Facades\Log::warning('Audit append failed (fail_open)', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
