<?php

namespace Bherila\GenAiLaravel\FileConversion;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Stops PhpSpreadsheet reading cells beyond the extraction ceilings.
 *
 * Without this the row and cell limits bound only what is *rendered*: load()
 * has already built every cell of the workbook in memory by the time the
 * renderer starts counting, so a sheet with one value at XFD1048576 costs its
 * full width before any ceiling applies.
 *
 * The filter is consulted per cell during load, so the ceilings bound what is
 * read. It is deliberately generous by a row: the renderer still applies the
 * exact limits and appends the truncation marker, and this only has to stop
 * the workbook from being materialised in full.
 */
final readonly class BoundedReadFilter implements IReadFilter
{
    private int $maxColumnIndex;

    public function __construct(
        private int $maxRow,
        int $maxColumns = 16_384,
    ) {
        $this->maxColumnIndex = max(1, $maxColumns);
    }

    /** Derive a filter from the extraction ceilings a conversion is running under. */
    public static function for(ConversionLimits $limits): self
    {
        // One row of slack so the renderer, not the reader, is what notices the
        // limit and writes the marker explaining it.
        return new self(maxRow: $limits->maxRowsPerSheet + 1);
    }

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        if ($row > $this->maxRow) {
            return false;
        }

        return self::columnIndex($columnAddress) <= $this->maxColumnIndex;
    }

    /**
     * Base-26 column label (A, Z, AA, XFD) to its 1-based index.
     *
     * Anything that is not a column label cannot be shown to be within the
     * bound, so it is reported as beyond it.
     */
    private static function columnIndex(string $columnAddress): int
    {
        if ($columnAddress === '') {
            return PHP_INT_MAX;
        }

        $index = 0;
        foreach (str_split(strtoupper($columnAddress)) as $letter) {
            $ordinal = ord($letter) - 64;
            if ($ordinal < 1 || $ordinal > 26) {
                return PHP_INT_MAX;
            }
            $index = $index * 26 + $ordinal;
        }

        return $index;
    }
}
