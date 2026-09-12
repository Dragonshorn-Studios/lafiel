<?php

namespace App\Domain\Providers\Dtos;

/**
 * One credential form field as declared by a credential schema.
 * `name` is the snake_case payload key; the page renders camelCase
 * Livewire properties against it.
 */
final readonly class CredentialField
{
    public const TYPE_TEXT = 'text';

    public const TYPE_PASSWORD = 'password';

    public const TYPE_SELECT = 'select';

    /**
     * @param  list<string>  $options  select choices, in render order
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = self::TYPE_TEXT,
        public array $options = [],
        public ?string $placeholder = null,
    ) {}
}
