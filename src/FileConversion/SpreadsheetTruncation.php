<?php

namespace Bherila\GenAiLaravel\FileConversion;

use Bherila\GenAiLaravel\FileLimits;

/**
 * The ceiling a spreadsheet extraction stopped at.
 *
 * Reported as data on {@see SpreadsheetConversion} so a caller that must see
 * the whole workbook — a scan for personal data, say — can refuse a partial
 * extract without parsing the marker line out of the text.
 */
enum SpreadsheetTruncation: string
{
    /**
     * A sheet has a value past the row ceiling, whether the renderer reached
     * that row or the read filter dropped the cell while the workbook loaded.
     */
    case RowLimit = 'row_limit';

    case CellLimit = 'cell_limit';

    case OutputLimit = 'output_limit';

    case TimeLimit = 'time_limit';

    /** The ceiling in words, as the marker line states it. */
    public function describe(ConversionLimits $limits): string
    {
        return match ($this) {
            self::RowLimit => sprintf('row limit of %d per sheet', $limits->maxRowsPerSheet),
            self::CellLimit => sprintf('cell limit of %d', $limits->maxCells),
            self::OutputLimit => sprintf('output limit of %s', FileLimits::humanBytes($limits->maxOutputBytes)),
            self::TimeLimit => sprintf('time limit of %.0fs', $limits->maxSeconds),
        };
    }
}
