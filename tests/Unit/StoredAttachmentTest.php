<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Mcp\StoredAttachment;
use Orchestra\Testbench\TestCase;

/**
 * A stored attachment records where its bytes are. Either this package owns
 * them, and knows the disk and the path; or the host owns them behind one
 * opaque reference it alone can resolve. A record that mixes the two, or that
 * carries half of either, names bytes nobody can fetch — so the constructor is
 * the only place that can still refuse it.
 */
class StoredAttachmentTest extends TestCase
{
    private const SHA = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_a_complete_disk_location_is_accepted(): void
    {
        $attachment = $this->make(disk: 'local', path: 'genai-mcp/1/2');

        $this->assertSame('local', $attachment->disk);
        $this->assertSame('genai-mcp/1/2', $attachment->path);
        $this->assertNull($attachment->hostReference);
    }

    public function test_a_lone_host_reference_is_accepted(): void
    {
        $attachment = $this->make(hostReference: 'document:7');

        $this->assertSame('document:7', $attachment->hostReference);
        $this->assertNull($attachment->disk);
        $this->assertNull($attachment->path);
    }

    public function test_every_incomplete_or_mixed_storage_mode_is_rejected(): void
    {
        $invalid = [
            'no location at all' => [null, null, null],
            'disk without path' => ['local', null, null],
            'path without disk' => [null, 'genai-mcp/1/2', null],
            'both modes at once' => ['local', 'genai-mcp/1/2', 'document:7'],
            'disk beside a host reference' => ['local', null, 'document:7'],
            'path beside a host reference' => [null, 'genai-mcp/1/2', 'document:7'],
        ];

        foreach ($invalid as $label => [$disk, $path, $hostReference]) {
            try {
                $this->make(disk: $disk, path: $path, hostReference: $hostReference);
                $this->fail("Expected [{$label}] to be rejected.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_empty_storage_locations_are_rejected(): void
    {
        $invalid = [
            'empty disk' => ['', 'genai-mcp/1/2', null],
            'empty path' => ['local', '', null],
            'empty host reference' => [null, null, ''],
        ];

        foreach ($invalid as $label => [$disk, $path, $hostReference]) {
            try {
                $this->make(disk: $disk, path: $path, hostReference: $hostReference);
                $this->fail("Expected [{$label}] to be rejected.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_package_ownership_still_requires_package_storage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->make(hostReference: 'document:7', packageOwned: true);
    }

    private function make(
        ?string $disk = null,
        ?string $path = null,
        ?string $hostReference = null,
        bool $packageOwned = false,
    ): StoredAttachment {
        return new StoredAttachment('report.pdf', 'application/pdf', 12, self::SHA, $disk, $path, $hostReference, $packageOwned);
    }
}
