<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\FileConversion\ConversionLimits;
use Bherila\GenAiLaravel\FileConversion\ZipBounds;
use Orchestra\Testbench\TestCase;

/**
 * XLSX and DOCX are ZIP containers, and the parsers this package hands them to
 * materialise their contents in-process before any row, cell or time ceiling
 * can run. The archive's own central directory is therefore the only place a
 * decompression bomb can be refused — everything downstream is already too
 * late.
 *
 * The fixtures here are built byte by byte rather than checked in, so what is
 * being tested is visible: a real zip bomb is a boring file with dishonest
 * arithmetic in its header.
 */
class ZipBoundsTest extends TestCase
{
    public function test_reads_entry_count_and_sizes_from_a_real_archive(): void
    {
        $bounds = ZipBounds::read($this->zip([
            ['name' => 'a.txt', 'body' => str_repeat('a', 1000)],
            ['name' => 'b.txt', 'body' => str_repeat('b', 2000)],
        ]));

        $this->assertNotNull($bounds);
        $this->assertSame(2, $bounds->entries);
        $this->assertSame(3000, $bounds->uncompressedBytes);
        $this->assertSame(2000, $bounds->largestEntryBytes);
        $this->assertSame([], $bounds->unsafeNames);
        $this->assertSame([], $bounds->duplicateNames);
    }

    public function test_a_decompression_bomb_is_refused_before_any_parser_opens_it(): void
    {
        // One kilobyte of stored bytes claiming to expand to a gigabyte. A real
        // bomb compresses that ratio for real; the header arithmetic is what
        // the preflight reads, and it is the same either way.
        $bomb = $this->zip([['name' => 'x', 'body' => str_repeat("\0", 1024), 'declaredUncompressed' => 1_073_741_824]]);

        $this->expectException(GenAiFileTooLargeException::class);
        (new ConversionLimits)->assertArchiveWithinBounds(ZipBounds::read($bomb), 'spreadsheet');
    }

    public function test_an_extreme_compression_ratio_is_refused_even_under_the_size_limit(): void
    {
        // Comfortably under maxUncompressedBytes, but expanding 1000x — a shape
        // no real Office document has.
        $bounds = new ZipBounds(
            entries: 3,
            compressedBytes: 1_000,
            uncompressedBytes: 1_000_000,
            largestEntryBytes: 1_000_000,
            unsafeNames: [],
            duplicateNames: [],
        );

        $this->expectException(GenAiFileTooLargeException::class);
        (new ConversionLimits)->assertArchiveWithinBounds($bounds, 'spreadsheet');
    }

    public function test_an_entry_escaping_the_archive_root_is_refused(): void
    {
        foreach (['../../etc/passwd', '/etc/passwd', 'C:\\windows\\win.ini', 'xl/../../../x'] as $name) {
            $bounds = ZipBounds::read($this->zip([['name' => $name, 'body' => 'x']]));

            $this->assertNotNull($bounds, "Bounds should be readable for [{$name}].");
            $this->assertSame([$name], $bounds->unsafeNames, "[{$name}] should be flagged.");

            try {
                (new ConversionLimits)->assertArchiveWithinBounds($bounds);
                $this->fail("Expected [{$name}] to be refused.");
            } catch (GenAiFatalException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_duplicate_entries_are_refused(): void
    {
        $bounds = ZipBounds::read($this->zip([
            ['name' => 'xl/workbook.xml', 'body' => 'one'],
            ['name' => 'xl/workbook.xml', 'body' => 'two'],
        ]));

        $this->assertNotNull($bounds);
        $this->assertSame(['xl/workbook.xml'], $bounds->duplicateNames);

        $this->expectException(GenAiFatalException::class);
        (new ConversionLimits)->assertArchiveWithinBounds($bounds);
    }

    public function test_too_many_entries_are_refused(): void
    {
        $bounds = new ZipBounds(
            entries: 100_000,
            compressedBytes: 100_000,
            uncompressedBytes: 200_000,
            largestEntryBytes: 10,
            unsafeNames: [],
            duplicateNames: [],
        );

        $this->expectException(GenAiFileTooLargeException::class);
        (new ConversionLimits)->assertArchiveWithinBounds($bounds);
    }

    public function test_an_ordinary_office_sized_archive_passes(): void
    {
        $bounds = ZipBounds::read($this->zip([
            ['name' => '[Content_Types].xml', 'body' => str_repeat('<x/>', 200)],
            ['name' => 'xl/workbook.xml', 'body' => str_repeat('<sheet/>', 500)],
            ['name' => 'xl/worksheets/sheet1.xml', 'body' => str_repeat('<c r="A1"/>', 5_000)],
        ]));

        (new ConversionLimits)->assertArchiveWithinBounds($bounds, 'spreadsheet');
        $this->addToAssertionCount(1);
    }

    /** Not every input is an archive: a CSV has no bounds to read, and that is not a failure. */
    public function test_bytes_that_are_not_a_zip_yield_no_bounds_and_are_not_refused(): void
    {
        foreach (['name,amount\nacme,12\n', '', 'PK', str_repeat("\x00", 4096)] as $notAZip) {
            $this->assertNull(ZipBounds::read($notAZip));
            (new ConversionLimits)->assertArchiveWithinBounds(ZipBounds::read($notAZip));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Build a ZIP with stored (uncompressed) entries.
     *
     * `declaredUncompressed` writes a size the body does not have, which is
     * exactly what a decompression bomb does — the preflight reads the claim,
     * so the test can make the claim directly.
     *
     * @param  list<array{name: string, body: string, declaredUncompressed?: int}>  $entries
     */
    private function zip(array $entries): string
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $body = $entry['body'];
            $size = $entry['declaredUncompressed'] ?? strlen($body);
            $crc = crc32($body);

            // Local file header: stored, no data descriptor.
            $localHeader = "PK\x03\x04".pack('vvvvvVVVvv', 20, 0, 0, 0, 0, $crc, strlen($body), $size, strlen($name), 0).$name;
            $local .= $localHeader.$body;

            $central .= "PK\x01\x02".pack(
                'vvvvvvVVVvvvvvVV',
                20, 20, 0, 0, 0, 0, $crc, strlen($body), $size, strlen($name), 0, 0, 0, 0, 0, $offset,
            ).$name;

            $offset += strlen($localHeader) + strlen($body);
        }

        $count = count($entries);

        return $local.$central."PK\x05\x06".pack('vvvvVVv', 0, 0, $count, $count, strlen($central), $offset, 0);
    }
}
