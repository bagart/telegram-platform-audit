<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditException;
use BAGArt\TelegramBotAudit\AuditFailurePolicy;
use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotAudit\DefaultAuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\InMemoryAuditSink;
use BAGArt\TelegramBotAudit\Laravel\Listeners\RecordModuleLifecycleEvents;
use BAGArt\TelegramBotManagement\Events\BotCreated;
use BAGArt\TelegramBotManagement\Events\BotDeleted;
use BAGArt\TelegramBotManagement\Events\BotModuleSettingChanged;
use BAGArt\TelegramBotManagement\Events\BotTokenRotated;
use BAGArt\TelegramModuleEngine\Events\BotModuleDisabled;
use BAGArt\TelegramModuleEngine\Events\BotModuleEnabled;

beforeEach(function () {
    $this->sink = new InMemoryAuditSink();
    $this->correlation = new \BAGArt\TelegramBotAudit\StaticCorrelationContext();
    $this->policyResolver = new DefaultAuditFailurePolicyResolver();
    $this->listener = new RecordModuleLifecycleEvents(
        sink: $this->sink,
        correlation: $this->correlation,
        policyResolver: $this->policyResolver,
    );
});

it('records bot.created', function () {
    $this->listener->handleBotCreated(new BotCreated(botId: 'bot-abc', secretToken: 's1'));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->operation)->toBe('bot.created');
    expect($entries[0]->target->botId)->toBe('bot-abc');
});

it('records bot.deleted', function () {
    $this->listener->handleBotDeleted(new BotDeleted(botId: 'bot-abc'));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->operation)->toBe('bot.deleted');
});

it('records bot.token.rotated', function () {
    $this->listener->handleBotTokenRotated(new BotTokenRotated(botId: 'bot-abc'));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->operation)->toBe('bot.token.rotated');
    expect($entries[0]->newState)->toBe(['secret_token' => '[rotated]']);
});

it('records module.runtime.enabled', function () {
    $this->listener->handleBotModuleEnabled(new BotModuleEnabled(
        botId: 'bot-abc',
        moduleId: 'antispam',
        revision: 1,
    ));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->operation)->toBe('module.runtime.enabled');
    expect($entries[0]->target->subjectId)->toBe('antispam');
});

it('records module.runtime.disabled', function () {
    $this->listener->handleBotModuleDisabled(new BotModuleDisabled(
        botId: 'bot-abc',
        moduleId: 'antispam',
        revision: 2,
    ));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->operation)->toBe('module.runtime.disabled');
});

it('records module.enablement.changed for isEnabled', function () {
    $this->listener->handleBotModuleSettingChanged(new BotModuleSettingChanged(
        moduleId: 'antispam',
        botId: 'bot-abc',
        chatId: null,
        isEnabled: true,
        settings: null,
    ));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->operation)->toBe('module.enablement.changed');
});

it('records module.settings.changed for settings update', function () {
    $this->listener->handleBotModuleSettingChanged(new BotModuleSettingChanged(
        moduleId: 'antispam',
        botId: 'bot-abc',
        chatId: 123,
        isEnabled: null,
        settings: ['threshold' => 0.8],
    ));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->operation)->toBe('module.settings.changed');
    expect($entries[0]->target->botId)->toBe('bot-abc');
});

it('handles null botId for platform-level setting change', function () {
    $this->listener->handleBotModuleSettingChanged(new BotModuleSettingChanged(
        moduleId: 'antispam',
        botId: null,
        chatId: null,
        isEnabled: true,
        settings: null,
    ));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->operation)->toBe('module.enablement.changed');
    expect($entries[0]->target->botId)->toBeNull();
    expect($entries[0]->target->subjectType)->toBe('module');
    expect($entries[0]->target->subjectId)->toBe('antispam');
});

it('uses actor from event when provided', function () {
    $this->listener->handleBotCreated(new BotCreated(
        botId: 'bot-abc',
        secretToken: 's1',
        actorId: 'user-42',
        actorType: AuditActor::TYPE_USER,
    ));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->actor->id)->toBe('user-42');
    expect($entries[0]->actor->type)->toBe(AuditActor::TYPE_USER);
});

it('falls back to system actor when event has no actor info', function () {
    $this->listener->handleBotCreated(new BotCreated(
        botId: 'bot-abc',
        secretToken: 's1',
    ));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->actor->id)->toBe('system');
    expect($entries[0]->actor->type)->toBe(AuditActor::TYPE_SYSTEM);
});

it('uses actor from BotModuleSettingChanged event', function () {
    $this->listener->handleBotModuleSettingChanged(new BotModuleSettingChanged(
        moduleId: 'antispam',
        botId: 'bot-abc',
        chatId: null,
        isEnabled: true,
        settings: null,
        actorId: 'admin-7',
        actorType: 'user',
    ));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->actor->id)->toBe('admin-7');
    expect($entries[0]->actor->type)->toBe('user');
});

it('uses actor from BotModuleEnabled event', function () {
    $this->listener->handleBotModuleEnabled(new BotModuleEnabled(
        botId: 'bot-abc',
        moduleId: 'antispam',
        revision: 1,
        actorId: 'cli-operator',
        actorType: AuditActor::TYPE_SYSTEM,
    ));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->actor->id)->toBe('cli-operator');
    expect($entries[0]->actor->type)->toBe(AuditActor::TYPE_SYSTEM);
});

it('throws AuditException on FailClosed when sink fails', function () {
    $failingSink = new class () implements AuditSinkContract {
        public function append(AuditEntry $entry): void
        {
            throw new \RuntimeException('DB unavailable');
        }
    };

    $listener = new RecordModuleLifecycleEvents(
        sink: $failingSink,
        correlation: $this->correlation,
        policyResolver: new DefaultAuditFailurePolicyResolver(),
    );

    $listener->handleBotCreated(new BotCreated(botId: 'bot-abc', secretToken: 's1'));
})->throws(AuditException::class, 'Audit append failed');

it('logs warning on FailOpen when sink fails', function () {
    $failingSink = new class () implements AuditSinkContract {
        public function append(AuditEntry $entry): void
        {
            throw new \RuntimeException('DB unavailable');
        }
    };

    $policyResolver = new DefaultAuditFailurePolicyResolver(
        operationOverrides: ['bot.created' => AuditFailurePolicy::FailOpen->value],
    );

    $listener = new RecordModuleLifecycleEvents(
        sink: $failingSink,
        correlation: $this->correlation,
        policyResolver: $policyResolver,
    );

    $listener->handleBotCreated(new BotCreated(botId: 'bot-abc', secretToken: 's1'));

    // No exception thrown — FailOpen logs and continues
    $this->assertTrue(true);
});
