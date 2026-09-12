<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\CorrelationContext;
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
    $this->listener = new RecordModuleLifecycleEvents(
        sink: $this->sink,
        correlation: $this->correlation,
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
