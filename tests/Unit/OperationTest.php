<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit\Tests\Unit;

use BAGArt\TelegramBotAudit\Operation;
use PHPUnit\Framework\TestCase;

final class OperationTest extends TestCase
{
    public function test_all_values_use_dot_notation(): void
    {
        foreach (Operation::cases() as $case) {
            self::assertStringContainsString('.', $case->value, "Operation {$case->name} must use dot notation");
            self::assertMatchesRegularExpression('/^[a-z]+\.[a-z.]+$/', $case->value);
        }
    }

    public function test_enum_values_are_unique(): void
    {
        $values = array_map(static fn (Operation $op): string => $op->value, Operation::cases());
        self::assertSame($values, array_unique($values));
    }

    public function test_round_trip_through_json(): void
    {
        $data = ['operation' => Operation::ModuleEnabled->value];
        self::assertSame('module.enabled', $data['operation']);
        self::assertSame(Operation::ModuleEnabled, Operation::from($data['operation']));
    }

    public function test_module_lifecycle_operations(): void
    {
        self::assertSame('module.installed', Operation::ModuleInstalled->value);
        self::assertSame('module.uninstalled', Operation::ModuleUninstalled->value);
        self::assertSame('module.enabled', Operation::ModuleEnabled->value);
        self::assertSame('module.disabled', Operation::ModuleDisabled->value);
        self::assertSame('module.connected', Operation::ModuleConnected->value);
        self::assertSame('module.disconnected', Operation::ModuleDisconnected->value);
        self::assertSame('module.suspended', Operation::ModuleSuspended->value);
        self::assertSame('module.config.changed', Operation::ModuleConfigChanged->value);
        self::assertSame('module.config.migrated', Operation::ModuleConfigMigrated->value);
        self::assertSame('module.runtime.failed', Operation::ModuleRuntimeFailed->value);
        self::assertSame('module.runtime.recovered', Operation::ModuleRuntimeRecovered->value);
    }

    public function test_bot_operations(): void
    {
        self::assertSame('bot.created', Operation::BotCreated->value);
        self::assertSame('bot.deleted', Operation::BotDeleted->value);
        self::assertSame('bot.token.rotated', Operation::BotTokenRotated->value);
    }

    public function test_access_operations(): void
    {
        self::assertSame('access.grant.created', Operation::AccessGrantCreated->value);
        self::assertSame('access.grant.revoked', Operation::AccessGrantRevoked->value);
        self::assertSame('access.decision.made', Operation::AccessDecisionMade->value);
    }

    public function test_role_operations(): void
    {
        self::assertSame('role.granted', Operation::RoleGranted->value);
        self::assertSame('role.revoked', Operation::RoleRevoked->value);
    }
}
