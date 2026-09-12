<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel\Listeners;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotAccess\Events\GrantCreated;
use BAGArt\TelegramBotAccess\Events\GrantRevoked;
use Illuminate\Support\Facades\Log;

/**
 * Records audit entries for access control events.
 *
 * Listens for GrantCreated and GrantRevoked domain events and
 * appends them to the audit sink.
 */
final class RecordAccessControlEvents
{
    public function __construct(
        private readonly AuditSinkContract $sink,
        private readonly CorrelationContext $correlation,
    ) {}

    public function handleGrantCreated(GrantCreated $event): void
    {
        $this->record(
            operation: 'access.grant.created',
            grant: $event->grant,
            actorType: AuditActor::TYPE_USER,
            actorId: $event->actor->subjectId,
            newState: [
                'effect' => $event->grant->effect->value,
                'capability' => $event->grant->capability,
                'scope' => $event->grant->scope->value,
                'chat_id' => $event->grant->chatId,
            ],
        );
    }

    public function handleGrantRevoked(GrantRevoked $event): void
    {
        $this->record(
            operation: 'access.grant.revoked',
            grant: $event->grant,
            actorType: AuditActor::TYPE_USER,
            actorId: $event->actor->subjectId,
            oldState: [
                'effect' => $event->grant->effect->value,
                'capability' => $event->grant->capability,
                'scope' => $event->grant->scope->value,
                'chat_id' => $event->grant->chatId,
            ],
        );
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
        try {
            $entry = new AuditEntry(
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

            $this->sink->append($entry);
        } catch (\Throwable $e) {
            Log::warning('Failed to record access control audit entry', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
