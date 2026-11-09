<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Format;

/**
 * Stock in bulk: the stock list as a CSV file (opens in Excel), stock counts read back from such a file, and the
 * stock report (what is in stock, its value, and what sold).
 *
 * An imported file needs the columns "Color code" and "Sets in stock" (other columns are ignored), so the exported file
 * can be counted, edited in Excel and saved as CSV again. Every changed color gets a count correction, logged like a
 * change made by hand; nothing is changed when any line has a problem.
 */
final class StockCounts
{
    public const MAX_BYTES = 512 * 1024;

    public const MAX_ROWS = 5000;

    private const MAX_ERRORS = 50;

    private const HEADERS = ['Style no.', 'Style', 'For', 'Color code', 'Color', 'Sets in stock', 'Pieces per set', 'Price per set (Rs)', 'Shown'];

    public function __construct(
        private readonly Database $db,
        private readonly StockLevels $levels,
        private readonly WebRevalidator $revalidator,
    ) {
    }

    /** Every stock color, one per line, with a byte-order mark so Excel reads the text as UTF-8. */
    public function export(): string
    {
        $rows = $this->db->fetchAll(
            'SELECT s.style_no, s.name AS style_name, s.segment, s.status, s.set_sizes, s.set_price_cents, c.code, c.name, c.is_active, c.sets_in_stock
             FROM stock_colors c JOIN stock_styles s ON s.id = c.style_id
             ORDER BY s.segment, s.sort_order, s.id, c.sort_order, c.id',
        );
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADERS, ',', '"', '');
        foreach ($rows as $row) {
            $sizes = json_decode((string) $row['set_sizes'], true);
            $pieces = is_array($sizes) ? array_sum(array_map(static fn (mixed $size): int => is_array($size) ? (int) ($size['pcs'] ?? 0) : 0, $sizes)) : 0;
            fputcsv($out, [
                (string) ($row['style_no'] ?? ''),
                (string) $row['style_name'],
                $row['segment'] === 'boys' ? 'Boys' : 'Men',
                (string) $row['code'],
                (string) $row['name'],
                (int) $row['sets_in_stock'],
                $pieces,
                (int) round((int) $row['set_price_cents'] / 100),
                $row['status'] === 'active' && (int) $row['is_active'] === 1 ? 'Yes' : 'No',
            ], ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * Reads stock counts from a CSV file and, when $apply is true and no line has a problem, makes them the stock.
     *
     * @return array{changes: list<array{line: int, code: string, style: string, color: string, from: int, to: int}>, unchanged: int, errors: list<array{line: int, message: string}>, applied: bool}
     */
    public function import(string $csv, bool $apply, ?int $staffId): array
    {
        $result = ['changes' => [], 'unchanged' => 0, 'errors' => [], 'applied' => false];
        if (strlen($csv) > self::MAX_BYTES) {
            $result['errors'][] = ['line' => 0, 'message' => 'The file is too large. Import at most ' . self::MAX_ROWS . ' lines at a time.'];

            return $result;
        }
        $lines = $this->rows($csv);
        $header = array_map(static fn (string $cell): string => mb_strtolower(trim($cell)), array_shift($lines) ?? []);
        $codeColumn = self::column($header, ['color code', 'code']);
        $setsColumn = self::column($header, ['sets in stock', 'sets']);
        if ($codeColumn === null || $setsColumn === null) {
            $result['errors'][] = ['line' => 1, 'message' => 'The file needs the columns “Color code” and “Sets in stock”, like the exported stock list.'];

            return $result;
        }
        if (count($lines) > self::MAX_ROWS) {
            $result['errors'][] = ['line' => 0, 'message' => 'The file has more than ' . self::MAX_ROWS . ' lines. Import it in parts.'];

            return $result;
        }

        $colors = [];
        foreach ($this->db->fetchAll(
            'SELECT c.id, c.code, c.name, c.sets_in_stock, s.name AS style_name, s.slug FROM stock_colors c JOIN stock_styles s ON s.id = c.style_id',
        ) as $row) {
            $colors[mb_strtolower((string) $row['code'])] = $row;
        }

        $seen = [];
        foreach ($lines as $index => $cells) {
            $line = $index + 2;
            $code = trim((string) ($cells[$codeColumn] ?? ''));
            $sets = trim((string) ($cells[$setsColumn] ?? ''));
            if ($code === '' && $sets === '') {
                continue;
            }
            $color = $colors[mb_strtolower($code)] ?? null;
            $error = match (true) {
                $code === '' => 'The color code is missing.',
                $color === null => "No stock color has the code “{$code}”.",
                isset($seen[mb_strtolower($code)]) => "The code “{$code}” is also on line {$seen[mb_strtolower($code)]}.",
                preg_match('/^\d{1,6}$/', $sets) !== 1 => "“{$sets}” is not a whole number of sets.",
                default => null,
            };
            if ($error !== null) {
                if (count($result['errors']) < self::MAX_ERRORS) {
                    $result['errors'][] = ['line' => $line, 'message' => $error];
                }
                continue;
            }
            $seen[mb_strtolower($code)] = $line;
            if ((int) $sets === (int) $color['sets_in_stock']) {
                $result['unchanged']++;
                continue;
            }
            $result['changes'][] = [
                'line' => $line,
                'code' => (string) $color['code'],
                'style' => (string) $color['style_name'],
                'color' => (string) $color['name'],
                'from' => (int) $color['sets_in_stock'],
                'to' => (int) $sets,
                'color_id' => (int) $color['id'],
                'slug' => (string) $color['slug'],
            ];
        }

        if ($apply && $result['errors'] === [] && $result['changes'] !== []) {
            $this->db->transaction(function () use ($result, $staffId): void {
                foreach ($result['changes'] as $change) {
                    // The stock may have moved since the preview: correct to the counted number unless it already is.
                    if ((int) $this->db->fetchValue('SELECT sets_in_stock FROM stock_colors WHERE id = ?', [$change['color_id']]) !== $change['to']) {
                        $this->levels->change($change['color_id'], 'correction', $change['to'], 'Stock count imported', $staffId);
                    }
                }
            });
            $result['applied'] = true;
            $this->revalidator->revalidate(array_values(array_unique(['stock', ...array_map(static fn (array $c): string => 'stock:' . $c['slug'], $result['changes'])])));
        }
        $result['changes'] = array_map(static fn (array $change): array => array_diff_key($change, ['color_id' => true, 'slug' => true]), $result['changes']);

        return $result;
    }

    /**
     * Each color's stock and its value at today's set price, and what moved in the last $days days: sets sold (less
     * those of cancelled orders), received and returned.
     *
     * @return array{days: int, since: string, rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function report(int $days): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $rows = $this->db->fetchAll(
            "SELECT s.id AS style_id, s.name AS style_name, s.style_no, s.segment, s.status, s.set_price_cents,
                c.id AS color_id, c.code, c.name, c.is_active, c.sets_in_stock,
                COALESCE(SUM(CASE WHEN m.reason IN ('sold', 'cancelled') THEN -m.change_sets END), 0) AS sold_sets,
                COALESCE(SUM(CASE WHEN m.reason = 'received' THEN m.change_sets END), 0) AS received_sets,
                COALESCE(SUM(CASE WHEN m.reason = 'returned' THEN m.change_sets END), 0) AS returned_sets
             FROM stock_colors c
             JOIN stock_styles s ON s.id = c.style_id
             LEFT JOIN stock_movements m ON m.color_id = c.id AND m.created_at >= ?
             GROUP BY s.id, s.name, s.style_no, s.segment, s.status, s.set_price_cents, s.sort_order, c.id, c.code, c.name, c.is_active, c.sets_in_stock, c.sort_order
             ORDER BY s.segment, s.sort_order, s.id, c.sort_order, c.id",
            [$since],
        );

        $report = array_map(static function (array $row): array {
            $price = (int) $row['set_price_cents'];

            return [
                'style_id' => (int) $row['style_id'],
                'style_name' => (string) $row['style_name'],
                'style_no' => $row['style_no'],
                'segment' => (string) $row['segment'],
                'shown' => $row['status'] === 'active' && (int) $row['is_active'] === 1,
                'code' => (string) $row['code'],
                'color' => (string) $row['name'],
                'set_price_cents' => $price,
                'sets_in_stock' => (int) $row['sets_in_stock'],
                'value_cents' => (int) $row['sets_in_stock'] * $price,
                'sold_sets' => (int) $row['sold_sets'],
                'sold_cents' => (int) $row['sold_sets'] * $price,
                'received_sets' => (int) $row['received_sets'],
                'returned_sets' => (int) $row['returned_sets'],
            ];
        }, $rows);

        $totals = [];
        foreach (['sets_in_stock', 'value_cents', 'sold_sets', 'sold_cents', 'received_sets', 'returned_sets'] as $key) {
            $totals[$key] = array_sum(array_column($report, $key));
        }

        return ['days' => $days, 'since' => Format::date(gmdate(DATE_ATOM, time() - $days * 86400)), 'rows' => $report, 'totals' => $totals];
    }

    /**
     * @return list<list<string>> The file's lines as cells, with the delimiter Excel used (comma, semicolon or tab).
     */
    private function rows(string $csv): array
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

    /**
     * @param list<string> $header lower-case header cells
     * @param list<string> $names accepted names, lower case
     */
    private static function column(array $header, array $names): ?int
    {
        foreach ($header as $index => $cell) {
            if (in_array($cell, $names, true)) {
                return $index;
            }
        }

        return null;
    }
}
