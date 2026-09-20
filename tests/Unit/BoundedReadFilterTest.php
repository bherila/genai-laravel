<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\FileConversion\BoundedReadFilter;
use Bherila\GenAiLaravel\FileConversion\ConversionLimits;
use Orchestra\Testbench\TestCase;

/**
 * The row and cell ceilings used to bound only what was rendered: load() had
 * already built every cell in memory by the time the renderer started
 * counting, so a sheet with one value at XFD1048576 cost its full width first.
 * This filter is consulted per cell during load, which is what moves the
 * ceiling from the render to the read.
 */
class BoundedReadFilterTest extends TestCase
{
    public function test_rows_past_the_ceiling_are_not_read(): void
    {
        $filter = new BoundedReadFilter(maxRow: 100);

        $this->assertTrue($filter->readCell('A', 1));
        $this->assertTrue($filter->readCell('A', 100));
        $this->assertFalse($filter->readCell('A', 101));
        $this->assertFalse($filter->readCell('A', 1_048_576));
    }

    public function test_columns_past_the_ceiling_are_not_read(): void
    {
        $filter = new BoundedReadFilter(maxRow: 100, maxColumns: 3);

        $this->assertTrue($filter->readCell('A', 1));
        $this->assertTrue($filter->readCell('C', 1));
        $this->assertFalse($filter->readCell('D', 1));
        $this->assertFalse($filter->readCell('XFD', 1));
    }

    /** The far corner of a worksheet is the cheapest way to make a sheet enormous. */
    public function test_the_far_corner_of_a_worksheet_is_excluded(): void
    {
        $filter = BoundedReadFilter::for(new ConversionLimits(maxRowsPerSheet: 1_000));

        $this->assertTrue($filter->readCell('A', 1));
        $this->assertFalse($filter->readCell('XFD', 1_048_576));
    }

    public function test_column_labels_are_decoded_as_base_26(): void
    {
        $filter = new BoundedReadFilter(maxRow: 10, maxColumns: 16_384);

        // A=1, Z=26, AA=27, XFD=16384 — the last column Excel has.
        $this->assertTrue($filter->readCell('A', 1));
        $this->assertTrue($filter->readCell('Z', 1));
        $this->assertTrue($filter->readCell('AA', 1));
        $this->assertTrue($filter->readCell('XFD', 1));

        $narrow = new BoundedReadFilter(maxRow: 10, maxColumns: 26);
        $this->assertTrue($narrow->readCell('Z', 1));
        $this->assertFalse($narrow->readCell('AA', 1));
    }

    /** A label that is not a column cannot be proven safe, so it is excluded. */
    public function test_an_unparseable_column_label_is_excluded(): void
    {
        $filter = new BoundedReadFilter(maxRow: 10, maxColumns: 16_384);

        $this->assertFalse($filter->readCell('A1', 1));
        $this->assertFalse($filter->readCell('', 1));
    }

    public function test_the_filter_leaves_the_renderer_a_row_to_notice_the_limit(): void
    {
        // The renderer writes the truncation marker, so the reader must not cut
        // first or the extract would end without saying why.
        $filter = BoundedReadFilter::for(new ConversionLimits(maxRowsPerSheet: 50));

        $this->assertTrue($filter->readCell('A', 51));
        $this->assertFalse($filter->readCell('A', 52));
    }
}
