<?php

namespace App\Domain\Providers\Dtos;

/**
 * One step of a provider's credential setup guide, rendered as an
 * ordered list under the help text. `lines` carries the monospace
 * detail — console URLs, request snippets, permission paths — and an
 * optional `url` links out for more.
 */
final readonly class CredentialHelpStep
{
    /**
     * @param  list<string>  $lines  technical detail lines, rendered monospace
     */
    public function __construct(
        public string $body,
        public array $lines = [],
        public ?string $url = null,
        public ?string $urlLabel = null,
    ) {}
}
