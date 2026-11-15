<?php

namespace Bherila\GenAiLaravel\FileConversion;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Security\XmlScanner;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx\Namespaces;

/**
 * Whether a workbook holds a value that {@see BoundedReadFilter} kept out of
 * the load, reported as the ceiling the extract stopped at.
 *
 * Asked only after the filter refused something, since readers consult it for
 * blank cells and row and column attributes as well as for values: a sheet
 * whose formatting runs to row 1,048,576 is refused there without losing a
 * thing. Each answer here is a second, value-only reading of the file in the
 * format the reader loaded it as, kept to what can be done without building
 * the cells: OpenDocument is streamed one node at a time, and an Excel
 * workbook's parts are parsed the way the reader parses a worksheet.
 *
 * Every doubt resolves to "yes": a format with no reading here, a part that
 * cannot be accounted for, or a reading that fails all report a lost value,
 * so the extract is marked partial rather than passed off as whole.
 *
 * @internal
 */
final class UnreadSpreadsheetValues
{
    /** How many nodes or rows a walk takes between looks at the clock. */
    private const CLOCK_INTERVAL = 1_024;

    /** Cell attributes the ODS reader reads a value or formula from. */
    private const ODS_VALUE_ATTRIBUTES = ['formula', 'value-type', 'value', 'date-value', 'time-value', 'boolean-value', 'string-value'];

    private int $steps = 0;

    private function __construct(
        private readonly BoundedReadFilter $filter,
        private readonly float $deadline,
    ) {}

    /**
     * `RowLimit` when a value past the filter's reach is found or cannot be
     * ruled out, `TimeLimit` when the conversion's deadline passes first, and
     * null when the second reading finds nothing lost.
     *
     * @param  string  $format  The reader type the workbook was loaded with ({@see IOFactory::identify()}).
     * @param  int  $loadedSheets  How many worksheets the load produced.
     * @param  float  $deadline  microtime(true) value the conversion must finish by.
     */
    public static function find(string $path, string $format, IReader $reader, BoundedReadFilter $filter, int $loadedSheets, float $deadline): ?SpreadsheetTruncation
    {
        $walk = new self($filter, $deadline);

        try {
            $lost = match ($format) {
                IOFactory::READER_XLSX => $walk->inXlsx($path, $reader, $loadedSheets),
                IOFactory::READER_ODS => $walk->inOds($path),
                // The CSV reader asks only about a non-empty field, so its
                // refusal is a value. The rest — legacy .xls among them — also
                // ask about blank and formatting-only cells, and nothing here
                // reads them a second time.
                default => true,
            };
        } catch (DeadlinePassed) {
            return SpreadsheetTruncation::TimeLimit;
        } catch (\Throwable) {
            $lost = true;
        }

        return $lost ? SpreadsheetTruncation::RowLimit : null;
    }

    /**
     * Stops the walk once the conversion's own deadline passes, so a second
     * reading can never run a conversion past `maxSeconds`.
     *
     * @throws DeadlinePassed
     */
    private function tick(): void
    {
        if ($this->steps++ % self::CLOCK_INTERVAL === 0 && microtime(true) > $this->deadline) {
            throw new DeadlinePassed;
        }
    }

    /**
     * Every part the load inflated that parses as a worksheet is read for a
     * cell with a value past the filter's reach. Only those parts: the reader
     * records them as it opens them ({@see XlsxPartRecorder}), so an entry the
     * workbook never points at is skipped here without being inflated, and
     * the reading costs no more memory than the load did. Finding fewer
     * worksheets than the load produced means a sheet went unread here, and
     * that counts as a value, as does a reader that kept no record.
     */
    private function inXlsx(string $path, IReader $reader, int $loadedSheets): bool
    {
        if (! $reader instanceof XlsxPartRecorder) {
            return true;
        }

        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return true;
        }

        try {
            $scanner = XmlScanner::getInstance($reader);
            $worksheets = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $this->tick();
                $stat = $zip->statIndex($index);
                if ($stat === false || ! $reader->read($stat)) {
                    continue;
                }
                $contents = $zip->getFromIndex($index);
                if (! is_string($contents) || $contents === '') {
                    continue;
                }
                $worksheet = self::xlsxWorksheet($scanner, $contents);
                if ($worksheet === null) {
                    continue;
                }
                $worksheets++;
                if ($this->xlsxWorksheetHasValuePast($worksheet)) {
                    return true;
                }
            }

            return $worksheets < $loadedSheets;
        } finally {
            $zip->close();
        }
    }

    /**
     * One package part read as the reader reads a worksheet — its security
     * scanner, which also normalizes the encoding, then SimpleXML in the
     * SpreadsheetML namespace — or null when it is none.
     */
    private static function xlsxWorksheet(XmlScanner $scanner, string $contents): ?\SimpleXMLElement
    {
        try {
            $scanned = $scanner->scan($contents);
        } catch (\Throwable) {
            return null;
        }

        foreach ([Namespaces::MAIN, Namespaces::PURL_MAIN] as $namespace) {
            $document = @simplexml_load_string($scanned, \SimpleXMLElement::class, 0, $namespace);
            if ($document instanceof \SimpleXMLElement && isset($document->sheetData)) {
                return $document;
            }
        }

        return null;
    }

    /**
     * A cell counts once it has a value, an inline string or a formula. Its
     * position is taken as the reader takes it: its own reference, or its
     * place in the row and the row's place in the sheet when it has none.
     */
    private function xlsxWorksheetHasValuePast(\SimpleXMLElement $worksheet): bool
    {
        $rowNumber = 1;
        foreach ($worksheet->sheetData->row as $row) {
            $this->tick();
            $columnNumber = 1;
            foreach ($row->c as $cell) {
                $reference = (string) $cell->attributes()['r'];
                if (preg_match('/^\$?([A-Z]+)\$?(\d+)$/i', $reference, $match) === 1) {
                    $column = self::columnNumber($match[1]);
                    $cellRow = (int) $match[2];
                } else {
                    $column = $columnNumber;
                    $cellRow = $rowNumber;
                }
                if ((isset($cell->v) || isset($cell->is) || isset($cell->f))
                    && ($cellRow > $this->filter->maxRow() || $column > $this->filter->maxColumns())) {
                    return true;
                }
                $columnNumber++;
            }
            $rowNumber++;
        }

        return false;
    }

    /**
     * `content.xml` streamed one node at a time, so the walk ends when the
     * file does — `Ods::listWorksheetInfo()` never returns from a sheet whose
     * last row is an empty self-closing repeated row, which ordinary exports
     * end with.
     *
     * Names are resolved the way the reader resolves them, not by the prefix
     * a file happens to use: a sheet is an element named `table` in the
     * namespace the root binds to the `table` prefix, its rows and cells are
     * matched on their local names alone, and the repeat counts are that
     * namespace's attributes. A row or cell repeated N times advances N, so a
     * cell with content in a repeated span reaches the span's end.
     *
     * A cell counts when it has content or carries any attribute a value can
     * be read from — a formula, a value type, or one of the value attributes —
     * in whatever namespace, so a self-closing
     * `<table:table-cell table:formula="…"/>` is a value; the empty span a
     * sheet is padded out with is not. A table inside a cell would restart
     * the count, so one is taken as a value rather than walked.
     */
    private function inOds(string $path): bool
    {
        $xml = \XMLReader::open('zip://'.$path.'#content.xml', null, LIBXML_NONET | LIBXML_COMPACT);
        if (! $xml instanceof \XMLReader) {
            return true;
        }

        try {
            $tableNs = null;
            $row = 0;
            $column = 0;
            $sheetDepth = null;
            while ($xml->read()) {
                $this->tick();
                if ($xml->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                // The reader takes the namespace from the document element
                // (DOMDocument::lookupNamespaceURI('table')), so this does too.
                $tableNs ??= (string) $xml->lookupNamespace('table');

                switch ($xml->localName) {
                    case 'table':
                        if ($xml->namespaceURI !== $tableNs) {
                            break;
                        }
                        $sheetDepth ??= $xml->depth;
                        if ($xml->depth !== $sheetDepth) {
                            return true;
                        }
                        $row = 0;
                        break;
                    case 'table-row':
                        $row += max(1, self::odsRepeats($xml, 'number-rows-repeated', $tableNs));
                        $column = 0;
                        break;
                    case 'table-cell':
                    case 'covered-table-cell':
                        $column += max(1, self::odsRepeats($xml, 'number-columns-repeated', $tableNs));
                        if (($row > $this->filter->maxRow() || $column > $this->filter->maxColumns()) && self::odsCellHoldsValue($xml)) {
                            return true;
                        }
                        break;
                }
            }

            return false;
        } finally {
            $xml->close();
        }
    }

    /** A repeat count, read as the reader reads it: in the table namespace. */
    private static function odsRepeats(\XMLReader $xml, string $attribute, string $tableNs): int
    {
        return (int) ($tableNs === '' ? $xml->getAttribute($attribute) : $xml->getAttributeNs($attribute, $tableNs));
    }

    /**
     * Whether the cell the walk is on has anything the ODS reader could turn
     * into a value: child content, or an attribute a value can be read from,
     * in any namespace. Broader than the reader on purpose — a cell taken for
     * a value here is reported as cut, never passed.
     */
    private static function odsCellHoldsValue(\XMLReader $xml): bool
    {
        if (! $xml->isEmptyElement) {
            return true;
        }

        $holdsValue = false;
        if ($xml->moveToFirstAttribute()) {
            do {
                if (in_array($xml->localName, self::ODS_VALUE_ATTRIBUTES, true)) {
                    $holdsValue = true;
                    break;
                }
            } while ($xml->moveToNextAttribute());
            $xml->moveToElement();
        }

        return $holdsValue;
    }

    /** A column's letters (A, Z, AA, XFD) as its 1-based number. */
    private static function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $number = $number * 26 + (ord($letter) - 64);
        }

        return $number;
    }
}
