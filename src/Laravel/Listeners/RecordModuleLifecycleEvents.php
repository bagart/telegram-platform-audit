<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Laravel\Listeners;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotManagement\Events\BotCreated;
use BAGArt\TelegramBotManagement\Events\BotDeleted;
use BAGArt\TelegramBotManagement\Events\BotModuleSettingChanged;
use BAGArt\TelegramBotManagement\Events\BotTokenRotated;
use BAGArt\TelegramModuleEngine\Events\BotModuleDisabled;
use BAGArt\TelegramModuleEngine\Events\BotModuleEnabled;
use Illuminate\Support\Facades\Log;

/**
 * Records audit entries for bot and module lifecycle events.
 */
final class RecordModuleLifecycleEvents
{
    public function __construct(
        private readonly AuditSinkContract $sink,
        private readonly CorrelationContext $correlation,
    ) {}

    public function handleBotCreated(BotCreated $event): void
    {
        $this->record(
            operation: 'bot.created',
            botId: $event->botId,
            subjectType: 'bot',
            subjectId: $event->botId,
            newState: ['secret_token' => $event->secretToken !== '' ? '[set]' : '[empty]'],
            source: 'management',
        );
    }

    public function handleBotDeleted(BotDeleted $event): void
    {
        $this->record(
            operation: 'bot.deleted',
            botId: $event->botId,
            subjectType: 'bot',
            subjectId: $event->botId,
            source: 'management',
        );
    }

    public function handleBotTokenRotated(BotTokenRotated $event): void
    {
        $this->record(
            operation: 'bot.token.rotated',
            botId: $event->botId,
            subjectType: 'bot',
            subjectId: $event->botId,
            newState: ['secret_token' => '[rotated]'],
            source: 'management',
        );
    }

    public function handleBotModuleEnabled(BotModuleEnabled $event): void
    {
        $this->record(
            operation: 'module.runtime.enabled',
            botId: $event->botId,
            subjectType: 'module',
            subjectId: $event->moduleId,
            newState: ['module_id' => $event->moduleId, 'revision' => $event->revision],
            source: 'module-engine',
        );
    }

    public function handleBotModuleDisabled(BotModuleDisabled $event): void
    {
        $this->record(
            operation: 'module.runtime.disabled',
            botId: $event->botId,
            subjectType: 'module',
            subjectId: $event->moduleId,
            newState: ['module_id' => $event->moduleId, 'revision' => $event->revision],
            source: 'module-engine',
        );
    }

    public function handleBotModuleSettingChanged(BotModuleSettingChanged $event): void
    {
        $operation = $event->isEnabled !== null
            ? 'module.enablement.changed'
            : 'module.settings.changed';

        $this->record(
            operation: $operation,
            botId: $event->botId,
            subjectType: 'module',
            subjectId: $event->moduleId,
            newState: [
                'module_id' => $event->moduleId,
                'bot_id' => $event->botId,
                'chat_id' => $event->chatId,
                'is_enabled' => $event->isEnabled,
                'has_settings' => $event->settings !== null,
            ],
            source: 'management',
        );
    }

    private function record(
        string $operation,
        ?string $botId,
        string $subjectType,
        string $subjectId,
        ?array $oldState = null,
        ?array $newState = null,
        string $source = 'unknown',
    ): void {
        try {
            $entry = AuditEntry::now(
                id: AuditEntry::generateId(),
                actor: new AuditActor(type: AuditActor::TYPE_SYSTEM, id: 'system'),
                target: new AuditTarget(
                    botId: $botId,
                    subjectType: $subjectType,
                    subjectId: $subjectId,
                ),
                operation: $operation,
                oldState: $oldState,
                newState: $newState,
                source: $source,
                correlationId: $this->correlation->id(),
            );

            $this->sink->append($entry);
        } catch (\Throwable $e) {
            Log::warning('Failed to record lifecycle audit entry', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
