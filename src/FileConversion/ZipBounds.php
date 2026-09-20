<?php

namespace Bherila\GenAiLaravel\FileConversion;

/**
 * What a ZIP container claims about itself, read from its central directory
 * without extracting anything.
 *
 * XLSX and DOCX are ZIP archives, and both PhpSpreadsheet and PhpWord
 * materialise an archive's contents in-process before any row, cell, output or
 * time ceiling can run. A decompression bomb sized just under the input limit
 * therefore exhausts memory before the first traversal check — which is why
 * ConversionLimits says plainly that it is not a sandbox.
 *
 * The central directory sits at the end of the file and records, for every
 * entry, its name and both its compressed and uncompressed sizes. Reading it
 * costs a few kilobytes and tells you what the archive will cost to open, so a
 * bomb can be refused before a parser ever sees it.
 *
 * These are the archive's own claims, not measured truth: a file may lie about
 * its uncompressed size. A liar can only understate, though — the real cost
 * shows up against the memory cap of an isolated run — so treating the claim
 * as a floor is sound.
 *
 * Deliberately implemented against the raw bytes rather than ext-zip, because
 * the preflight has to run wherever the package does, including installs where
 * ext-zip is missing.
 */
final readonly class ZipBounds
{
    /**
     * @param  int  $entries  Files recorded in the central directory.
     * @param  int  $uncompressedBytes  Their declared total once expanded.
     * @param  int  $largestEntryBytes  The largest single declared entry.
     * @param  list<string>  $unsafeNames  Entry names that escape the archive root.
     * @param  list<string>  $duplicateNames  Names recorded more than once.
     */
    public function __construct(
        public int $entries,
        public int $compressedBytes,
        public int $uncompressedBytes,
        public int $largestEntryBytes,
        public array $unsafeNames,
        public array $duplicateNames,
    ) {}

    /**
     * How far the archive expands. A ratio a real Office document never
     * approaches is the clearest single signal of a bomb.
     */
    public function compressionRatio(): float
    {
        return $this->compressedBytes > 0
            ? $this->uncompressedBytes / $this->compressedBytes
            : 0.0;
    }

    /**
     * Read the central directory of a ZIP archive held in memory.
     *
     * Returns null when the bytes are not a ZIP this preflight understands —
     * a plain CSV, a ZIP64 archive, or a damaged container. A caller that
     * cannot read the bounds has learned nothing, which is different from
     * having learned the archive is safe, and callers must treat it that way.
     */
    public static function read(string $bytes): ?self
    {
        $directory = self::locateCentralDirectory($bytes);
        if ($directory === null) {
            return null;
        }
        [$offset, $expectedEntries] = $directory;

        $entries = 0;
        $compressed = 0;
        $uncompressed = 0;
        $largest = 0;
        $unsafe = [];
        $seen = [];
        $duplicates = [];

        while ($entries < $expectedEntries) {
            // Central directory file header: signature, then fixed fields up to
            // the variable-length name, extra field and comment.
            if (substr($bytes, $offset, 4) !== "PK\x01\x02") {
                return null;
            }
            $header = @unpack('Vcompressed/Vuncompressed/vname/vextra/vcomment', substr($bytes, $offset + 20, 14));
            if ($header === false) {
                return null;
            }
            // 0xFFFFFFFF is the ZIP64 sentinel: the real size lives in an extra
            // field this reader does not parse, so the bounds would be a lie.
            if ($header['compressed'] === 0xFFFFFFFF || $header['uncompressed'] === 0xFFFFFFFF) {
                return null;
            }

            $name = substr($bytes, $offset + 46, $header['name']);
            if (self::escapesRoot($name)) {
                $unsafe[] = $name;
            }
            if (isset($seen[$name])) {
                $duplicates[$name] = true;
            }
            $seen[$name] = true;

            $entries++;
            $compressed += $header['compressed'];
            $uncompressed += $header['uncompressed'];
            $largest = max($largest, $header['uncompressed']);

            $offset += 46 + $header['name'] + $header['extra'] + $header['comment'];
            if ($offset > strlen($bytes)) {
                return null;
            }
        }

        return new self(
            entries: $entries,
            compressedBytes: $compressed,
            uncompressedBytes: $uncompressed,
            largestEntryBytes: $largest,
            unsafeNames: $unsafe,
            duplicateNames: array_keys($duplicates),
        );
    }

    /**
     * The end-of-central-directory record is last in the file, but a trailing
     * comment can follow it, so it is found by scanning backwards.
     *
     * @return array{int, int}|null Offset of the directory and the entry count.
     */
    private static function locateCentralDirectory(string $bytes): ?array
    {
        $length = strlen($bytes);
        // 22 bytes of record plus at most 65535 of comment.
        $earliest = max(0, $length - 22 - 65535);
        for ($start = $length - 22; $start >= $earliest; $start--) {
            if (substr($bytes, $start, 4) !== "PK\x05\x06") {
                continue;
            }
            $record = @unpack('ventries/Vsize/Voffset', substr($bytes, $start + 10, 10));
            if ($record === false || $record['offset'] === 0xFFFFFFFF) {
                return null;
            }
            if ($length < $record['offset'] + $record['size']) {
                return null;
            }

            return [$record['offset'], $record['entries']];
        }

        return null;
    }

    /** Absolute paths, drive letters and `..` segments all name a file outside the archive. */
    private static function escapesRoot(string $name): bool
    {
        $normalised = str_replace('\\', '/', $name);

        return $normalised === ''
            || str_starts_with($normalised, '/')
            || preg_match('#^[A-Za-z]:#', $normalised) === 1
            || $normalised === '..'
            || str_starts_with($normalised, '../')
            || str_contains($normalised, '/../')
            || str_ends_with($normalised, '/..');
    }
}
