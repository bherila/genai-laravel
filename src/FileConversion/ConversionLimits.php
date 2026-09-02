<?php

namespace Bherila\GenAiLaravel\FileConversion;

/**
 * Best-effort resource ceilings for Office-document conversion.
 *
 * These bound the *accidental* cases — the 400,000-row export somebody uploads
 * by mistake, the sparse sheet with a cell at XFD1048576, the conversion that
 * would otherwise pin a worker for ten minutes. Defaults are generous for real
 * business documents and well below what it takes to hurt a process.
 *
 * They are **not a security boundary**, and this package does not claim one.
 * Only $maxInputBytes applies before the document reaches the parser; everything
 * else is checked while walking a workbook PhpSpreadsheet has already opened, or
 * against output PhpWord has already rendered. XLSX and DOCX are ZIP containers,
 * and both libraries materialise their contents in-process before any traversal
 * limit here can run — so a decompression bomb sized just under $maxInputBytes
 * can still exhaust memory, and no ceiling in this class will stop it.
 *
 * Converting documents from people you do not trust needs isolation this package
 * cannot provide from inside your worker: run the conversion in a separate
 * process with an enforced memory cap and CPU/wall-clock limit (a dedicated
 * queue worker with a low `memory_limit`, a container with `--memory`, a
 * `ulimit -v` wrapper), and treat a killed process as a rejected upload. Tighten
 * the values here as a first filter on top of that, not in place of it.
 */
final class ConversionLimits
{
    /**
     * @param  int  $maxInputBytes  Decoded size of the document handed to the parser.
     * @param  int  $maxOutputBytes  Size of the produced text or PDF.
     * @param  int  $maxRowsPerSheet  Rows read from any single worksheet.
     * @param  int  $maxCells  Cells read across the whole workbook.
     * @param  float  $maxSeconds  Wall-clock budget for one conversion, measured from
     *                             the moment convert() is entered. Checked only where
     *                             this package holds control — it cannot interrupt a
     *                             parse or a render already running inside PhpSpreadsheet,
     *                             PhpWord or the PDF renderer.
     */
    public function __construct(
        public readonly int $maxInputBytes = 33_554_432,      // 32 MB
        public readonly int $maxOutputBytes = 33_554_432,     // 32 MB
        public readonly int $maxRowsPerSheet = 100_000,
        public readonly int $maxCells = 2_000_000,
        public readonly float $maxSeconds = 60.0,
    ) {}

    /**
     * Build limits from `config('genai.conversion')`, falling back to the
     * defaults above for any key that is absent or null.
     *
     * Clients call this when no explicit policy was injected, so the values are
     * reachable from an application that only ever touches the facade or the
     * factory — not just from a direct SpreadsheetToText::convert() call.
     */
    public static function fromConfig(): self
    {
        $cfg = function_exists('config') ? (array) config('genai.conversion', []) : [];
        $defaults = new self;

        return new self(
            maxInputBytes: (int) ($cfg['max_input_bytes'] ?? $defaults->maxInputBytes),
            maxOutputBytes: (int) ($cfg['max_output_bytes'] ?? $defaults->maxOutputBytes),
            maxRowsPerSheet: (int) ($cfg['max_rows_per_sheet'] ?? $defaults->maxRowsPerSheet),
            maxCells: (int) ($cfg['max_cells'] ?? $defaults->maxCells),
            maxSeconds: (float) ($cfg['max_seconds'] ?? $defaults->maxSeconds),
        );
    }
}
