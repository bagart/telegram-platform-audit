<?php

declare(strict_types=1);

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditQueryFilter;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\Laravel\AuditCounters;
use BAGArt\TelegramBotAudit\Laravel\AuditMetricsCollector;
use BAGArt\TelegramBotAudit\Laravel\DatabaseAuditQuery;
use BAGArt\TelegramBotAudit\Laravel\DatabaseAuditSink;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('collects metrics via DatabaseAuditQuery without crashing on Generator', function () {
    $sink = new DatabaseAuditSink();
    $query = new DatabaseAuditQuery();

    $sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: null, subjectType: 'test', subjectId: '1'),
        operation: 'bot.created',
        source: 'management',
    ));
    $sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'system', id: 'cron'),
        target: new AuditTarget(botId: null, subjectType: 'test', subjectId: '2'),
        operation: 'module.enabled',
        source: 'engine',
    ));
    $sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: null, subjectType: 'test', subjectId: '3'),
        operation: 'bot.created',
        source: 'management',
    ));

    $collector = new AuditMetricsCollector($query, new AuditCounters());
    $metrics = $collector->collect();

    expect($metrics['total_last_day'])->toBe(3)
        ->and($metrics['by_operation']['bot.created'])->toBe(2)
        ->and($metrics['by_operation']['module.enabled'])->toBe(1)
        ->and($metrics['by_source']['management'])->toBe(2)
        ->and($metrics['by_source']['engine'])->toBe(1);
});
