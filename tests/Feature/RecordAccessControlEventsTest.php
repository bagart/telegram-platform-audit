<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\CorrelationContext;
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

it('records audit entry for grant created event', function () {
    $sink = app(AuditSinkContract::class);
    $listener = new RecordAccessControlEvents(
        sink: $sink,
        correlation: app(CorrelationContext::class),
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'menu.invoke',
        scope: GrantScope::Bot,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');

    $listener->handleGrantCreated(new GrantCreated($grant, $actor));

    // Verify entry was recorded
    if ($sink instanceof \BAGArt\TelegramBotAudit\InMemoryAuditSink) {
        $entries = $sink->snapshot();
        expect($entries)->toHaveCount(1);
        expect($entries[0]->operation)->toBe('access.grant.created');
        expect($entries[0]->actor->id)->toBe('admin1');
        expect($entries[0]->target->botId)->toBe('bot123');
    }
});

it('records audit entry for grant revoked event', function () {
    $sink = app(AuditSinkContract::class);
    $listener = new RecordAccessControlEvents(
        sink: $sink,
        correlation: app(CorrelationContext::class),
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'menu.invoke',
        scope: GrantScope::Bot,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');

    $listener->handleGrantRevoked(new GrantRevoked($grant, $actor));

    // Verify entry was recorded
    if ($sink instanceof \BAGArt\TelegramBotAudit\InMemoryAuditSink) {
        $entries = $sink->snapshot();
        expect($entries)->toHaveCount(1);
        expect($entries[0]->operation)->toBe('access.grant.revoked');
        expect($entries[0]->oldState)->not->toBeNull();
    }
});

it('includes correlation id in audit entries', function () {
    $sink = app(AuditSinkContract::class);
    $correlation = app(CorrelationContext::class);

    // Set correlation ID if mutable
    if ($correlation instanceof \BAGArt\TelegramBotAudit\MutableCorrelationContext) {
        $correlation->setId('test-correlation-id');
    }

    $listener = new RecordAccessControlEvents(
        sink: $sink,
        correlation: $correlation,
    );

    $grant = new Grant(
        botId: 'bot123',
        subjectId: 'user1',
        capability: 'menu.invoke',
        scope: GrantScope::Bot,
    );

    $actor = new ActorContext(subjectId: 'admin1', source: 'cli');
    $listener->handleGrantCreated(new GrantCreated($grant, $actor));

    if ($sink instanceof \BAGArt\TelegramBotAudit\InMemoryAuditSink) {
        $entries = $sink->snapshot();
        expect($entries[0]->correlationId)->not->toBeNull();
    }
});
