<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Unit;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\InMemoryAuditSink;
use PHPUnit\Framework\TestCase;

final class InMemoryAuditSinkTest extends TestCase
{
    public function test_sink_satisfies_append_only_contract(): void
    {
        self::assertInstanceOf(AuditSinkContract::class, new InMemoryAuditSink());
    }

    public function test_entries_accumulate_in_append_order(): void
    {
        $sink = new InMemoryAuditSink();
        $first = $this->entry('entry-1', 'module.enable');
        $second = $this->entry('entry-2', 'config.update');
        $third = $this->entry('entry-3', 'activation.deny');

        $sink->append($first);
        $sink->append($second);
        $sink->append($third);

        self::assertSame(3, $sink->count());
        self::assertSame([$first, $second, $third], $sink->snapshot());
    }

    public function test_snapshot_is_a_copy_not_a_live_reference(): void
    {
        $sink = new InMemoryAuditSink();
        $sink->append($this->entry('entry-1', 'module.enable'));

        $snapshot = $sink->snapshot();
        $snapshot[] = $this->entry('sneaky', 'hijack');

        self::assertSame(1, $sink->count());
        self::assertSame('entry-1', $sink->snapshot()[0]->id);
    }

    public function test_sink_has_no_mutation_api(): void
    {
        $methods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(InMemoryAuditSink::class))->getMethods(),
        );

        foreach ($methods as $method) {
            self::assertDoesNotMatchRegularExpression(
                '/update|delete|remove|clear|flush|truncate|replace|set(?!Snapshot)/i',
                $method,
                "InMemoryAuditSink method {$method} breaks the append-only guarantee",
            );
        }

        $contractMethods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(AuditSinkContract::class))->getMethods(),
        );
        self::assertSame(['append'], $contractMethods);
    }

    public function test_sink_accepts_entries_with_null_bot_id(): void
    {
        $sink = new InMemoryAuditSink();
        $entry = AuditEntry::now(
            id: 'platform-entry',
            actor: new AuditActor(AuditActor::TYPE_SYSTEM, 'installer'),
            target: new AuditTarget(null, 'platform', 'all'),
            operation: 'module.installed',
            source: 'engine',
        );

        $sink->append($entry);

        self::assertSame(1, $sink->count());
        self::assertNull($sink->snapshot()[0]->target->botId);
    }

    private function entry(string $id, string $operation): AuditEntry
    {
        return AuditEntry::now(
            id: $id,
            actor: new AuditActor(AuditActor::TYPE_USER, '100'),
            target: new AuditTarget('bot-1', 'module', 'cinema'),
            operation: $operation,
            source: 'engine',
        );
    }
}
