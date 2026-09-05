<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Platform-reserved audit operations with dot-notation values.
 *
 * Module-specific operations use namespaced strings (e.g. "proxy.probe.completed")
 * and do NOT require changing this enum. AuditEntry accepts Operation|string.
 *
 * @see https://core.telegram.org/bots/api — operations are unrelated to the
 *      Telegram API; this enum covers platform-internal lifecycle events.
 */
enum Operation: string
{
    // Module lifecycle
    case ModuleInstalled = 'module.installed';
    case ModuleUninstalled = 'module.uninstalled';
    case ModuleEnabled = 'module.enabled';
    case ModuleDisabled = 'module.disabled';
    case ModuleConnected = 'module.connected';
    case ModuleDisconnected = 'module.disconnected';
    case ModuleSuspended = 'module.suspended';
    case ModuleConfigChanged = 'module.config.changed';
    case ModuleConfigMigrated = 'module.config.migrated';
    case ModuleRuntimeFailed = 'module.runtime.failed';
    case ModuleRuntimeRecovered = 'module.runtime.recovered';

    // Bot management
    case BotCreated = 'bot.created';
    case BotDeleted = 'bot.deleted';
    case BotTokenRotated = 'bot.token.rotated';

    // Access control
    case AccessGrantCreated = 'access.grant.created';
    case AccessGrantRevoked = 'access.grant.revoked';
    case AccessDecisionMade = 'access.decision.made';

    // Menu / roles
    case RoleGranted = 'role.granted';
    case RoleRevoked = 'role.revoked';
}
