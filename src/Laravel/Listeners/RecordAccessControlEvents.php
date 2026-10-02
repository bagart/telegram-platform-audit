<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel\Listeners;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditFailurePolicy;
use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotAccess\Events\GrantCreated;
use BAGArt\TelegramBotAccess\Events\GrantRevoked;
use BAGArt\TelegramBotAccess\Grant;
use BAGArt\TelegramBotAccess\GrantScope;
use Illuminate\Support\Facades\Log;

/**
 * Records audit entries for access control events.
 *
 * Listens for GrantCreated and GrantRevoked domain events and
 * appends them to the audit sink. Respects failure policy: on
 * FailClosed, rethrows as AuditException to block the operation.
 */
final class RecordAccessControlEvents
{
    public function __construct(
        private readonly AuditSinkContract $sink,
        private readonly CorrelationContext $correlation,
        private readonly AuditFailurePolicyResolver $policyResolver,
    ) {
    }

    public function handleGrantCreated(GrantCreated $event): void
    {
        $this->record(
            operation: 'access.grant.created',
            grant: $event->grant,
            actorType: AuditActor::TYPE_USER,
            actorId: $event->actor->subjectId,
            newState: $this->grantState($event->grant),
        );
    }

    public function handleGrantRevoked(GrantRevoked $event): void
    {
        $this->record(
            operation: 'access.grant.revoked',
            grant: $event->grant,
            actorType: AuditActor::TYPE_USER,
            actorId: $event->actor->subjectId,
            oldState: $this->grantState($event->grant),
        );
    }

    /**
     * Snapshot of the grant's audited fields; workspace-scope grants also
     * carry their workspace_id (existing keys are always present).
     *
     * @return array<string, mixed>
     */
    private function grantState(Grant $grant): array
    {
        $state = [
            'effect' => $grant->effect->value,
            'capability' => $grant->capability,
            'scope' => $grant->scope->value,
            'chat_id' => $grant->chatId,
        ];

        if ($grant->scope === GrantScope::Workspace) {
            $state['workspace_id'] = $grant->workspaceId;
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $newState
     * @param  array<string, mixed>|null  $oldState
     */
    private function record(
        string $operation,
        mixed $grant,
        string $actorType,
        string $actorId,
        ?array $oldState = null,
        ?array $newState = null,
    ): void {
        $entry = AuditEntry::now(
            id: AuditEntry::generateId(),
            actor: new AuditActor(
                type: $actorType,
                id: $actorId,
            ),
            target: new AuditTarget(
                botId: $grant->botId,
                subjectType: 'grant',
                subjectId: "{$grant->subjectId}:{$grant->capability}",
                chatId: $grant->chatId,
            ),
            operation: $operation,
            oldState: $oldState,
            newState: $newState,
            source: 'access-module',
            correlationId: $this->correlation->id(),
        );

        try {
            $this->sink->append($entry);
        } catch (\Throwable $e) {
            $policy = $this->policyResolver->resolve($entry);

            if ($policy === AuditFailurePolicy::FailClosed) {
                throw new \BAGArt\TelegramBotAudit\AuditException(
                    "Audit append failed for operation '{$operation}': {$e->getMessage()}",
                    previous: $e,
                );
            }

            Log::warning('Audit append failed (fail_open)', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
