<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\FileLimits;
use Orchestra\Testbench\TestCase;

class FileLimitsTest extends TestCase
{
    /**
     * Every provider limit is quoted in decoded bytes, so measuring the base64
     * string instead would over-report by a third and under-enforce every limit.
     */
    public function test_decoded_length_matches_an_actual_decode(): void
    {
        foreach (['', 'a', 'ab', 'abc', 'abcd', 'hello world', str_repeat('x', 999)] as $raw) {
            $this->assertSame(
                strlen($raw),
                FileLimits::decodedLength(base64_encode($raw)),
                'Mismatch for a '.strlen($raw).'-byte payload.',
            );
        }
    }

    public function test_decoded_length_ignores_line_wrapping(): void
    {
        $raw = random_bytes(4096);

        $this->assertSame(4096, FileLimits::decodedLength(chunk_split(base64_encode($raw), 76, "\n")));
    }

    public function test_decoded_length_handles_unpadded_base64(): void
    {
        $this->assertSame(2, FileLimits::decodedLength(rtrim(base64_encode('hi'), '=')));
        $this->assertSame(5, FileLimits::decodedLength(rtrim(base64_encode('hello'), '=')));
    }

    public function test_content_length_reads_strings_and_seekable_streams(): void
    {
        $this->assertSame(5, FileLimits::contentLength('hello'));

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'hello world');
        rewind($stream);

        $this->assertSame(11, FileLimits::contentLength($stream));

        fclose($stream);
    }

    public function test_assert_within_allows_a_file_at_exactly_the_limit(): void
    {
        FileLimits::assertWithin(100, 100, 'Test', 'the file');

        $this->addToAssertionCount(1);
    }

    public function test_assert_within_reports_both_sizes_on_the_exception(): void
    {
        try {
            FileLimits::assertWithin(101, 100, 'Test provider', 'the file');
            $this->fail('Expected GenAiFileTooLargeException.');
        } catch (GenAiFileTooLargeException $e) {
            $this->assertSame(101, $e->actualBytes);
            $this->assertSame(100, $e->limitBytes);
            $this->assertStringContainsString('Test provider', $e->getMessage());
        }
    }

    public function test_human_bytes_renders_provider_limits_readably(): void
    {
        $this->assertSame('512 B', FileLimits::humanBytes(512));
        $this->assertSame('4.5 MB', FileLimits::humanBytes(4_718_592));
        $this->assertSame('20 MB', FileLimits::humanBytes(20 * 1024 * 1024));
        $this->assertSame('2 GB', FileLimits::humanBytes(2 * 1024 * 1024 * 1024));
    }
}
