<?php

namespace Bherila\GenAiLaravel\FileConversion;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use ZipArchive;

/**
 * The Excel reader, remembering which package parts it inflated.
 *
 * Every part the reader opens goes through `getFromZipArchive()`, after the
 * reader's own resolution of the part's name — relative and `..` targets,
 * case-insensitive matches, Windows separators. Recording what came back,
 * rather than re-deriving where it came from, is what lets
 * {@see UnreadSpreadsheetValues} read the worksheets the load read and
 * nothing else: an entry no part of the workbook points at, however large, is
 * never inflated by the second reading, just as it never is by the load.
 *
 * A part is remembered by its size and CRC-32, which the archive's central
 * directory also records, so an entry can be matched to it without being
 * inflated first.
 *
 * @internal
 */
class XlsxPartRecorder extends Xlsx
{
    /** @var array<string, true> */
    private array $partsRead = [];

    protected function getFromZipArchive(ZipArchive $archive, string $fileName = ''): string
    {
        $contents = parent::getFromZipArchive($archive, $fileName);
        if ($contents !== '') {
            $this->partsRead[self::fingerprint(strlen($contents), hash('crc32b', $contents))] = true;
        }

        return $contents;
    }

    /**
     * Whether an archive entry, as its central directory describes it, is a
     * part this reader inflated.
     *
     * @param  array{size: int, crc: int}  $stat  As ZipArchive::statIndex() returns it.
     */
    public function read(array $stat): bool
    {
        return isset($this->partsRead[self::fingerprint($stat['size'], sprintf('%08x', $stat['crc']))]);
    }

    private static function fingerprint(int $size, string $crc): string
    {
        return $size.':'.$crc;
    }
}
