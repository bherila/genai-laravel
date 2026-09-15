<?php

namespace Bherila\GenAiLaravel\Mcp;

final readonly class StoredAttachment
{
    public function __construct(
        public string $name,
        public string $mimeType,
        public int $size,
        public string $sha256,
        public ?string $disk = null,
        public ?string $path = null,
        public ?string $hostReference = null,
        public bool $packageOwned = false,
    ) {
        if (($disk === null || $path === null) === ($hostReference === null)) {
            throw new \InvalidArgumentException('Provide either a disk/path pair or one opaque host reference.');
        }
    }
}
