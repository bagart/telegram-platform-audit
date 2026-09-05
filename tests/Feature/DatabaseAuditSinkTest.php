<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Feature;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\AuditQueryFilter;
use BAGArt\TelegramBotAudit\Laravel\DatabaseAuditQuery;
use BAGArt\TelegramBotAudit\Laravel\DatabaseAuditSink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DatabaseAuditSinkTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('audit.driver', 'database');
        $app['config']->set('audit.database.table', 'audit_entries');
    }

    public function test_append_and_query_round_trip(): void
    {
        $sink = $this->app->make(DatabaseAuditSink::class);
        $query = $this->app->make(DatabaseAuditQuery::class);

        $entry = AuditEntry::now(
            id: 'test-entry-1',
            actor: new AuditActor(AuditActor::TYPE_USER, '100', 'Test User'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: 'module.enabled',
            oldState: ['enabled' => false],
            newState: ['enabled' => true],
            source: 'engine',
            metadata: ['reason' => 'admin request'],
        );

        $sink->append($entry);

        $results = iterator_to_array($query->query(new AuditQueryFilter(botId: 'bot-1')));

        self::assertCount(1, $results);
        self::assertSame('test-entry-1', $results[0]->id);
        self::assertSame('module.enabled', $results[0]->operationString());
        self::assertSame('engine', $results[0]->source);
        self::assertSame(['enabled' => false], $results[0]->oldState);
        self::assertSame(['enabled' => true], $results[0]->newState);
        self::assertSame(['reason' => 'admin request'], $results[0]->metadata);
    }

    public function test_tenant_scoping_isolation(): void
    {
        $sink = $this->app->make(DatabaseAuditSink::class);
        $query = $this->app->make(DatabaseAuditQuery::class);

        $sink->append(AuditEntry::now(
            id: 'entry-bot1',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: 'module.enabled',
            source: 'engine',
        ));

        $sink->append(AuditEntry::now(
            id: 'entry-bot2',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-2', 'module', 'cinema'),
            operation: 'module.enabled',
            source: 'engine',
        ));

        $bot1Entries = iterator_to_array($query->query(new AuditQueryFilter(botId: 'bot-1')));
        $bot2Entries = iterator_to_array($query->query(new AuditQueryFilter(botId: 'bot-2')));

        self::assertCount(1, $bot1Entries);
        self::assertSame('entry-bot1', $bot1Entries[0]->id);

        self::assertCount(1, $bot2Entries);
        self::assertSame('entry-bot2', $bot2Entries[0]->id);
    }

    public function test_operation_prefix_filtering(): void
    {
        $sink = $this->app->make(DatabaseAuditSink::class);
        $query = $this->app->make(DatabaseAuditQuery::class);

        $sink->append(AuditEntry::now(
            id: 'e1',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: 'module.enabled',
            source: 'engine',
        ));

        $sink->append(AuditEntry::now(
            id: 'e2',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: 'module.disabled',
            source: 'engine',
        ));

        $sink->append(AuditEntry::now(
            id: 'e3',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'bot', 'mybot'),
            operation: 'bot.created',
            source: 'engine',
        ));

        $moduleEntries = iterator_to_array($query->query(new AuditQueryFilter(
            botId: 'bot-1',
            operation: 'module.*',
        )));

        self::assertCount(2, $moduleEntries);
    }

    public function test_null_bot_id_is_platform_scope(): void
    {
        $sink = $this->app->make(DatabaseAuditSink::class);
        $query = $this->app->make(DatabaseAuditQuery::class);

        $sink->append(AuditEntry::now(
            id: 'platform-entry',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'installer'),
            target: new AuditTarget(null, 'platform', 'all'),
            operation: 'module.installed',
            source: 'engine',
        ));

        $platformEntries = iterator_to_array($query->query(new AuditQueryFilter()));
        self::assertCount(1, $platformEntries);
        self::assertSame('platform-entry', $platformEntries[0]->id);

        $botEntries = iterator_to_array($query->query(new AuditQueryFilter(botId: 'bot-1')));
        self::assertCount(0, $botEntries);
    }

    public function test_count_works(): void
    {
        $sink = $this->app->make(DatabaseAuditSink::class);
        $query = $this->app->make(DatabaseAuditQuery::class);

        $sink->append(AuditEntry::now(
            id: 'e1',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: 'module.enabled',
            source: 'engine',
        ));

        $sink->append(AuditEntry::now(
            id: 'e2',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'sys'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: 'module.disabled',
            source: 'engine',
        ));

        $count = $query->count(new AuditQueryFilter(botId: 'bot-1'));
        self::assertSame(2, $count);
    }
}
