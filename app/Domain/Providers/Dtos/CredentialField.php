<?php

namespace App\Domain\Providers\Dtos;

/**
 * One credential form field as declared by a credential schema.
 * `name` is the snake_case payload key; the page binds it nested under
 * `credential.` (so validation and storage keep the schema's keys).
 */
final readonly class CredentialField
{
    public const TYPE_TEXT = 'text';

    public const TYPE_PASSWORD = 'password';

    public const TYPE_SELECT = 'select';

    /**
     * @param  array<string, string>  $options  select choices as value => label, in render order
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = self::TYPE_TEXT,
        public array $options = [],
        public ?string $placeholder = null,
    ) {
        if ($type === self::TYPE_SELECT && $options === []) {
            throw new \InvalidArgumentException("Select credential field [{$name}] requires options.");
        }
    }

    public function isSecret(): bool
    {
        return $this->type === self::TYPE_PASSWORD;
    }

    public function isSelect(): bool
    {
        return $this->type === self::TYPE_SELECT;
    }
}
