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
        if ($disk === '' || $path === '' || $hostReference === '') {
            throw new \InvalidArgumentException('Attachment storage locations must not be empty strings.');
        }
        // Exactly one complete storage mode. Half a disk location alongside a
        // host reference used to slip through, leaving a record that neither
        // this package nor the host can resolve.
        if (($disk !== null) !== ($path !== null) || ($disk !== null) === ($hostReference !== null)) {
            throw new \InvalidArgumentException('Provide either a complete disk and path pair or one opaque host reference, never both and never half of either.');
        }
        if ($name === '' || str_contains($name, "\0") || $mimeType === '' || preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/D', $mimeType) !== 1) {
            throw new \InvalidArgumentException('Attachment name and MIME type must be valid.');
        }
        if ($size < 0 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('Attachment size and lowercase SHA-256 digest must be valid.');
        }
        if ($packageOwned && $hostReference !== null) {
            throw new \InvalidArgumentException('Only package storage paths can be package-owned.');
        }
    }
}
