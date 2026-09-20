<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\FileConversion\ConversionLimits;
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

    public function test_assert_request_within_measures_the_encoded_payload(): void
    {
        $payload = ['messages' => [['content' => str_repeat('a', 500)]]];

        // Comfortably inside.
        FileLimits::assertRequestWithin($payload, 10_000, 'Test provider');

        try {
            FileLimits::assertRequestWithin($payload, 100, 'Test provider');
            $this->fail('Expected GenAiFileTooLargeException.');
        } catch (GenAiFileTooLargeException $e) {
            $this->assertSame(100, $e->limitBytes);
            // Measured on the serialized payload, not on the raw content: JSON
            // syntax and escaping are part of what the provider counts.
            $this->assertGreaterThan(500, $e->actualBytes);
        }
    }

    public function test_assert_request_within_is_a_noop_without_a_limit(): void
    {
        FileLimits::assertRequestWithin(['x' => str_repeat('a', 1000)], null, 'Test provider');

        $this->addToAssertionCount(1);
    }

    public function test_human_bytes_renders_provider_limits_readably(): void
    {
        $this->assertSame('512 B', FileLimits::humanBytes(512));
        $this->assertSame('4.5 MB', FileLimits::humanBytes(4_718_592));
        $this->assertSame('20 MB', FileLimits::humanBytes(20 * 1024 * 1024));
        $this->assertSame('2 GB', FileLimits::humanBytes(2 * 1024 * 1024 * 1024));
    }

    // ── conversion allowance ─────────────────────────────────────────────────

    /**
     * A conversion ceiling chosen in isolation can exceed the provider's whole
     * request budget, so the extract is built and the request assembled before
     * anything notices. The allowance is what the rest of the request leaves
     * behind, halved for JSON escaping and split between the conversions that
     * share it.
     */
    public function test_allowance_is_what_the_rest_of_the_request_leaves(): void
    {
        $budget = 20 * 1024 * 1024;
        $reserve = 65_536;

        $this->assertSame(
            intdiv($budget - 0 - $reserve, 2),
            FileLimits::conversionOutputAllowance($budget, 0),
        );
        $this->assertSame(
            intdiv($budget - 1_000_000 - $reserve, 2),
            FileLimits::conversionOutputAllowance($budget, 1_000_000),
        );
    }

    public function test_conversions_sharing_a_request_split_the_allowance(): void
    {
        $budget = 20 * 1024 * 1024;

        $one = FileLimits::conversionOutputAllowance($budget, 0, 1);
        $four = FileLimits::conversionOutputAllowance($budget, 0, 4);

        $this->assertNotNull($one);
        $this->assertNotNull($four);
        $this->assertSame(intdiv($one, 4), $four);
    }

    public function test_a_request_already_over_budget_leaves_no_allowance(): void
    {
        $this->assertSame(0, FileLimits::conversionOutputAllowance(1024, 1024 * 1024));
    }

    public function test_a_provider_without_a_request_ceiling_imposes_no_allowance(): void
    {
        $this->assertNull(FileLimits::conversionOutputAllowance(null, 0));
        $this->assertNull(FileLimits::conversionOutputAllowance(1024, 0, 0));
    }

    public function test_the_allowance_only_ever_tightens_configured_limits(): void
    {
        $limits = new ConversionLimits(maxOutputBytes: 1_000);

        $this->assertSame(500, $limits->withMaxOutputBytes(500)->maxOutputBytes);
        // A roomier budget never raises a ceiling the host deliberately lowered.
        $this->assertSame(1_000, $limits->withMaxOutputBytes(50_000)->maxOutputBytes);
        $this->assertSame(0, $limits->withMaxOutputBytes(-5)->maxOutputBytes);
        // Everything else is carried over untouched.
        $this->assertSame($limits->maxCells, $limits->withMaxOutputBytes(10)->maxCells);
        $this->assertSame($limits->maxSeconds, $limits->withMaxOutputBytes(10)->maxSeconds);
    }
}
