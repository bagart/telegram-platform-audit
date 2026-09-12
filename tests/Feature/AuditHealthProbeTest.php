<?php

declare(strict_types=1);

use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditQueryContract;
use BAGArt\TelegramBotAudit\AuditQueryFilter;
use BAGArt\TelegramBotAudit\InMemoryAuditSink;
use BAGArt\TelegramBotAudit\Laravel\AuditHealthProbe;
use BAGArt\TelegramBotAudit\Laravel\AuditMetricsCollector;

beforeEach(function () {
    $this->sink = new InMemoryAuditSink();
    $this->query = new class ($this->sink) implements AuditQueryContract {
        public function __construct(
            private readonly InMemoryAuditSink $sink,
        ) {}

        public function query(AuditQueryFilter $filter): array
        {
            return $this->sink->snapshot();
        }

        public function count(AuditQueryFilter $filter): int
        {
            return $this->sink->count();
        }
    };
});

it('reports healthy status when entries exist', function () {
    $this->sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: null, subjectType: 'test', subjectId: '1'),
        operation: 'test.created',
        source: 'test',
    ));

    $probe = new AuditHealthProbe($this->query);
    $result = $probe->check();

    expect($result['status'])->toBe('healthy')
        ->and($result['entries_total'])->toBe(1)
        ->and($result['driver'])->toBe(config('audit.driver', 'database'));
});

it('reports healthy status with zero entries', function () {
    $probe = new AuditHealthProbe($this->query);
    $result = $probe->check();

    expect($result['status'])->toBe('healthy')
        ->and($result['entries_total'])->toBe(0);
});

it('collects metrics by operation and source', function () {
    $this->sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: null, subjectType: 'test', subjectId: '1'),
        operation: 'bot.created',
        source: 'management',
    ));
    $this->sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'user', id: '1'),
        target: new AuditTarget(botId: null, subjectType: 'test', subjectId: '2'),
        operation: 'bot.created',
        source: 'management',
    ));
    $this->sink->append(AuditEntry::now(
        id: AuditEntry::generateId(),
        actor: new AuditActor(type: 'system', id: 'cron'),
        target: new AuditTarget(botId: null, subjectType: 'test', subjectId: '3'),
        operation: 'module.enabled',
        source: 'engine',
    ));

    $collector = new AuditMetricsCollector($this->query);
    $metrics = $collector->collect();

    expect($metrics['by_operation'])->toHaveKeys(['bot.created', 'module.enabled'])
        ->and($metrics['by_operation']['bot.created'])->toBe(2)
        ->and($metrics['by_operation']['module.enabled'])->toBe(1)
        ->and($metrics['by_source'])->toHaveKeys(['management', 'engine'])
        ->and($metrics['total_last_day'])->toBe(3);
});
