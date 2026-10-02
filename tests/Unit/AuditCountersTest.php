<?php

declare(strict_types=1);

use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\InMemoryAuditSink;
use BAGArt\TelegramBotAudit\Laravel\AuditCounters;
use BAGArt\TelegramBotAudit\Laravel\CountingAuditSink;

it('records append counters by operation and bot', function () {
    $counters = new AuditCounters();
    $sink = new CountingAuditSink(
        inner: new InMemoryAuditSink(),
        counters: $counters,
    );

    $sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '1'),
        operation: 'bot.created',
        source: 'test',
    ));

    $sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '2'),
        operation: 'bot.created',
        source: 'test',
    ));

    $snapshot = $counters->snapshot();

    expect($snapshot['total_appended'])->toBe(2)
        ->and($snapshot['by_operation'])->toHaveKey('bot.created')
        ->and($snapshot['by_operation']['bot.created'])->toBe(2)
        ->and($snapshot['by_bot'])->toHaveKey('bot1')
        ->and($snapshot['by_bot']['bot1'])->toBe(2);
});

it('records append counters with null botId for platform scope', function () {
    $counters = new AuditCounters();
    $sink = new CountingAuditSink(
        inner: new InMemoryAuditSink(),
        counters: $counters,
    );

    $sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'system', id: 'system'),
        target: new AuditTarget(botId: null, subjectType: 'platform', subjectId: '1'),
        operation: 'module.runtime.enabled',
        source: 'engine',
    ));

    $snapshot = $counters->snapshot();

    expect($snapshot['total_appended'])->toBe(1)
        ->and($snapshot['by_bot'])->toBeEmpty();
});

it('records failure counters when append throws', function () {
    $counters = new AuditCounters();
    $failingSink = new class ($counters) implements \BAGArt\TelegramBotAudit\AuditSinkContract {
        public function __construct(
            private readonly AuditCounters $counters,
        ) {
        }

        public function append(AuditEntry $entry): void
        {
            throw new \RuntimeException('DB connection lost');
        }
    };

    // CountingAuditSink wraps the inner sink and catches failures
    $countingSink = new CountingAuditSink(
        inner: $failingSink,
        counters: $counters,
    );

    try {
        $countingSink->append(AuditEntry::now(
            id: AuditEntry::generateId(),
            actor: new AuditActor(type: 'user', id: '1'),
            target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '1'),
            operation: 'bot.created',
            source: 'test',
        ));
    } catch (\Throwable) {
        // Expected
    }

    $snapshot = $counters->snapshot();

    expect($snapshot['total_failed'])->toBe(1)
        ->and($snapshot['failed_by_operation'])->toHaveKey('bot.created')
        ->and($snapshot['failed_by_operation']['bot.created'])->toBe(1);
});

it('computes latency statistics', function () {
    $counters = new AuditCounters();

    // Record multiple appends with varying latency
    foreach ([10, 20, 30, 40, 50] as $latencyMs) {
        $counters->recordAppend('test.op', 'bot1', $latencyMs);
    }

    $snapshot = $counters->snapshot();

    expect($snapshot['latency']['count'])->toBe(5)
        ->and($snapshot['latency']['min'])->toBe(10)
        ->and($snapshot['latency']['max'])->toBe(50)
        ->and($snapshot['latency']['avg'])->toBe(30.0)
        ->and($snapshot['latency']['p50'])->toBe(30);
});

it('returns empty latency stats when no samples', function () {
    $counters = new AuditCounters();
    $snapshot = $counters->snapshot();

    expect($snapshot['latency']['count'])->toBe(0)
        ->and($snapshot['latency']['min'])->toBe(0)
        ->and($snapshot['latency']['max'])->toBe(0)
        ->and($snapshot['latency']['avg'])->toBe(0.0);
});

it('delegates append to inner sink', function () {
    $inner = new InMemoryAuditSink();
    $counters = new AuditCounters();
    $sink = new CountingAuditSink(inner: $inner, counters: $counters);

    $entry = AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: 'bot1', subjectType: 'test', subjectId: '1'),
        operation: 'test.created',
        source: 'test',
    );

    $sink->append($entry);

    expect($inner->count())->toBe(1)
        ->and($inner->snapshot()[0]->id)->toBe($entry->id);
});
