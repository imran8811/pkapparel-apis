<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSV files for Excel: written with a byte-order mark so Excel reads the text as UTF-8, and read back whichever
 * delimiter Excel used when saving (comma, or semicolon or tab in some regions).
 */
final class Csv
{
    /**
     * @param list<string> $header
     * @param iterable<list<string|int|null>> $rows
     */
    public static function write(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @return list<list<string>> The file's lines as cells. */
    public static function read(string $csv): array
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $first = strtok($csv, "\r\n") ?: '';
        $delimiter = ',';
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($first, $candidate) > substr_count($first, $delimiter)) {
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(static fn (mixed $cell): string => (string) $cell, $cells);
        }
        fclose($stream);

        return $rows;
    }
}
