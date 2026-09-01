<?php

namespace Bherila\GenAiLaravel\FileConversion;

/**
 * Resource ceilings applied when converting an Office document.
 *
 * Both converters hand attacker-controllable bytes to a large third-party
 * parser. XLSX and DOCX are ZIP containers, so a few kilobytes on the wire can
 * expand into gigabytes of worksheet — the classic decompression bomb — and a
 * sparse sheet with a cell at XFD1048576 produces a rectangular grid that is
 * enormous even from an honest file. These limits bound the input, the work, and
 * the output so a hostile or merely pathological document fails fast instead of
 * exhausting the worker.
 *
 * Defaults are deliberately generous for real business documents and far below
 * what it takes to hurt a process. Pass a custom instance to convert() to tighten
 * them for untrusted uploads.
 */
final class ConversionLimits
{
    /**
     * @param  int  $maxInputBytes  Decoded size of the document handed to the parser.
     * @param  int  $maxOutputBytes  Size of the produced text or PDF.
     * @param  int  $maxRowsPerSheet  Rows read from any single worksheet.
     * @param  int  $maxCells  Cells read across the whole workbook.
     * @param  float  $maxSeconds  Wall-clock budget for the conversion loop this package runs.
     */
    public function __construct(
        public readonly int $maxInputBytes = 33_554_432,      // 32 MB
        public readonly int $maxOutputBytes = 33_554_432,     // 32 MB
        public readonly int $maxRowsPerSheet = 100_000,
        public readonly int $maxCells = 2_000_000,
        public readonly float $maxSeconds = 60.0,
    ) {}

    /**
     * Tighter ceilings for documents supplied by end users.
     */
    public static function untrusted(): self
    {
        return new self(
            maxInputBytes: 8_388_608,   // 8 MB
            maxOutputBytes: 4_194_304,  // 4 MB
            maxRowsPerSheet: 20_000,
            maxCells: 250_000,
            maxSeconds: 15.0,
        );
    }
}
