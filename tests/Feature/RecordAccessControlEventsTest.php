<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditException;
use BAGArt\TelegramBotAudit\AuditFailurePolicy;
use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotAudit\DefaultAuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\InMemoryAuditSink;
use BAGArt\TelegramBotAudit\Laravel\Listeners\RecordAccessControlEvents;
use BAGArt\TelegramBotAccess\Events\GrantCreated;
use BAGArt\TelegramBotAccess\Events\GrantRevoked;
use BAGArt\TelegramBotAccess\Grant;
use BAGArt\TelegramBotAccess\GrantEffect;
use BAGArt\TelegramBotAccess\GrantScope;
use BAGArt\TelegramBotAccess\ActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->sink = new InMemoryAuditSink();
    $this->app->instance(AuditSinkContract::class, $this->sink);
    $this->policyResolver = new DefaultAuditFailurePolicyResolver();
});

it('records audit entry for grant created event', function () {
    $listener = new RecordAccessControlEvents(
        sink: $this->sink,
        correlation: app(CorrelationContext::class),
        policyResolver: $this->policyResolver,
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'menu.invoke',
        scope: GrantScope::Bot,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');

    $listener->handleGrantCreated(new GrantCreated($grant, $actor));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->operation)->toBe('access.grant.created');
    expect($entries[0]->actor->id)->toBe('admin1');
    expect($entries[0]->target->botId)->toBe('bot123');
});

it('records audit entry for grant revoked event', function () {
    $listener = new RecordAccessControlEvents(
        sink: $this->sink,
        correlation: app(CorrelationContext::class),
        policyResolver: $this->policyResolver,
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'menu.invoke',
        scope: GrantScope::Bot,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');

    $listener->handleGrantRevoked(new GrantRevoked($grant, $actor));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->operation)->toBe('access.grant.revoked');
    expect($entries[0]->oldState)->not->toBeNull();
});

it('records workspace_id in newState for workspace-scope grants', function () {
    $listener = new RecordAccessControlEvents(
        sink: $this->sink,
        correlation: app(CorrelationContext::class),
        policyResolver: $this->policyResolver,
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'game.initiate',
        scope: GrantScope::Workspace,
        workspaceId: 5,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');

    $listener->handleGrantCreated(new GrantCreated($grant, $actor));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->newState)->toMatchArray([
        'effect' => 'allow',
        'capability' => 'game.initiate',
        'scope' => 'workspace',
        'chat_id' => null,
        'workspace_id' => 5,
    ]);
});

it('records workspace_id in oldState for revoked workspace-scope grants', function () {
    $listener = new RecordAccessControlEvents(
        sink: $this->sink,
        correlation: app(CorrelationContext::class),
        policyResolver: $this->policyResolver,
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'game.initiate',
        scope: GrantScope::Workspace,
        workspaceId: 9,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');

    $listener->handleGrantRevoked(new GrantRevoked($grant, $actor));

    $entries = $this->sink->snapshot();
    expect($entries)->toHaveCount(1);
    expect($entries[0]->oldState)->toMatchArray([
        'effect' => 'allow',
        'capability' => 'game.initiate',
        'scope' => 'workspace',
        'chat_id' => null,
        'workspace_id' => 9,
    ]);
});

it('keeps the legacy state keys and omits workspace_id for non-workspace grants', function () {
    $listener = new RecordAccessControlEvents(
        sink: $this->sink,
        correlation: app(CorrelationContext::class),
        policyResolver: $this->policyResolver,
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'menu.invoke',
        scope: GrantScope::Chat,
        chatId: 111,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');

    $listener->handleGrantCreated(new GrantCreated($grant, $actor));

    $entries = $this->sink->snapshot();
    expect($entries[0]->newState)->toMatchArray([
        'effect' => 'allow',
        'capability' => 'menu.invoke',
        'scope' => 'chat',
        'chat_id' => 111,
    ]);
    expect($entries[0]->newState)->not->toHaveKey('workspace_id');
});

it('includes correlation id in audit entries', function () {
    $correlation = app(CorrelationContext::class);

    if ($correlation instanceof \BAGArt\TelegramBotAudit\MutableCorrelationContext) {
        $correlation->setId('test-correlation-id');
    }

    $listener = new RecordAccessControlEvents(
        sink: $this->sink,
        correlation: $correlation,
        policyResolver: $this->policyResolver,
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'menu.invoke',
        scope: GrantScope::Bot,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');
    $listener->handleGrantCreated(new GrantCreated($grant, $actor));

    $entries = $this->sink->snapshot();
    expect($entries[0]->correlationId)->not->toBeNull();
});

it('throws AuditException on FailClosed when sink fails', function () {
    $failingSink = new class () implements AuditSinkContract {
        public function append(AuditEntry $entry): void
        {
            throw new \RuntimeException('DB unavailable');
        }
    };

    $listener = new RecordAccessControlEvents(
        sink: $failingSink,
        correlation: app(CorrelationContext::class),
        policyResolver: new DefaultAuditFailurePolicyResolver(),
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'menu.invoke',
        scope: GrantScope::Bot,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');

    $listener->handleGrantCreated(new GrantCreated($grant, $actor));
})->throws(AuditException::class, 'Audit append failed');

it('logs warning on FailOpen when sink fails', function () {
    $failingSink = new class () implements AuditSinkContract {
        public function append(AuditEntry $entry): void
        {
            throw new \RuntimeException('DB unavailable');
        }
    };

    $policyResolver = new DefaultAuditFailurePolicyResolver(
        operationOverrides: ['access.grant.created' => AuditFailurePolicy::FailOpen->value],
    );

    $listener = new RecordAccessControlEvents(
        sink: $failingSink,
        correlation: app(CorrelationContext::class),
        policyResolver: $policyResolver,
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'menu.invoke',
        scope: GrantScope::Bot,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');

    $listener->handleGrantCreated(new GrantCreated($grant, $actor));

    // No exception thrown — FailOpen logs and continues
    $this->assertTrue(true);
});
