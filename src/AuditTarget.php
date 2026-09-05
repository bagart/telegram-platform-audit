<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * What an audited operation acted upon.
 *
 * Tenant-scoped per the platform rule: bot as tenant, chat optional within
 * the bot, plus a free-form subject reference (entity type + id).
 *
 * bot_id is nullable: NULL = platform scope (e.g. module.installed),
 * non-NULL = bot scope (e.g. module.enabled).
 */
final readonly class AuditTarget
{
    /**
     * @param  string|null  $botId  Bot tenant ID; NULL for platform-scope operations.
     * @param  string  $subjectType  Target entity type, e.g. "module", "bot_setting", "chat_member".
     * @param  string  $subjectId  Target entity identifier.
     * @param  int|null  $chatId  Telegram chat ID when the operation is chat-scoped.
     */
    public function __construct(
        public ?string $botId,
        public string $subjectType,
        public string $subjectId,
        public ?int $chatId = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            botId: isset($data['botId']) ? (string) $data['botId'] : null,
            subjectType: (string)$data['subjectType'],
            subjectId: (string)$data['subjectId'],
            chatId: isset($data['chatId']) ? (int)$data['chatId'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'botId' => $this->botId,
            'subjectType' => $this->subjectType,
            'subjectId' => $this->subjectId,
            'chatId' => $this->chatId,
        ];
    }
}
