<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAudit;

/**
 * Who performed an audited operation.
 *
 * Deliberately credential-free: audit events describe actors, they never
 * carry secrets (tokens, passwords, keys).
 */
final readonly class AuditActor
{
    public const TYPE_USER = 'user';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_MODULE = 'module';

    /**
     * @param  string  $type  One of the TYPE_* constants (user / system / module).
     * @param  string  $id  Actor identifier (Telegram user ID as string, system process name, module ID).
     * @param  string|null  $displayName  Human-readable label for admin views; optional.
     */
    public function __construct(
        public string $type,
        public string $id,
        public ?string $displayName = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string)$data['type'],
            id: (string)$data['id'],
            displayName: isset($data['displayName']) ? (string)$data['displayName'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'displayName' => $this->displayName,
        ];
    }
}
