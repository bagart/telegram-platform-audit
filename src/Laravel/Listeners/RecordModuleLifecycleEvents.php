<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel\Listeners;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\CorrelationContext;
use Illuminate\Support\Facades\Log;

/**
 * Records audit entries for module lifecycle events.
 *
 * This listener can be wired to module enable/disable events
 * from the Telegram Module Engine.
 */
final class RecordModuleLifecycleEvents
{
    public function __construct(
        private readonly AuditSinkContract $sink,
        private readonly CorrelationContext $correlation,
    ) {}

    /**
     * Record a module enable event.
     */
    public function handleModuleEnabled(object $event): void
    {
        $this->record(
            operation: 'module.runtime.enabled',
            event: $event,
            actorType: AuditActor::TYPE_USER,
        );
    }

    /**
     * Record a module disable event.
     */
    public function handleModuleDisabled(object $event): void
    {
        $this->record(
            operation: 'module.runtime.disabled',
            event: $event,
            actorType: AuditActor::TYPE_USER,
        );
    }

    /**
     * Record a module settings change event.
     */
    public function handleModuleSettingsChanged(object $event): void
    {
        $this->record(
            operation: 'module.settings.changed',
            event: $event,
            actorType: AuditActor::TYPE_USER,
        );
    }

    /**
     * @param  string  $operation
     */
    private function record(
        string $operation,
        object $event,
        string $actorType,
    ): void {
        try {
            // Extract event properties via reflection or interface
            $moduleId = $this->extractProperty($event, 'moduleId') ?? 'unknown';
            $botId = $this->extractProperty($event, 'botId');
            $actorId = $this->extractProperty($event, 'actorId') ?? 'system';

            $entry = new AuditEntry(
                id: AuditEntry::generateId(),
                actor: new AuditActor(
                    type: $actorType,
                    id: (string) $actorId,
                ),
                target: new AuditTarget(
                    botId: $botId ? (string) $botId : null,
                    subjectType: 'module',
                    subjectId: (string) $moduleId,
                ),
                operation: $operation,
                oldState: null,
                newState: [
                    'module_id' => $moduleId,
                    'bot_id' => $botId,
                ],
                source: 'module-engine',
                correlationId: $this->correlation->id(),
            );

            $this->sink->append($entry);
        } catch (\Throwable $e) {
            Log::warning('Failed to record module lifecycle audit entry', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract a property from an event object.
     */
    private function extractProperty(object $event, string $name): mixed
    {
        if (property_exists($event, $name)) {
            return $event->$name;
        }

        return null;
    }
}
