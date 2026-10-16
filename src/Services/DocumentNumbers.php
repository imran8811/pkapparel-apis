<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Format;

/**
 * Running document numbers per type and year: RCPT-2026-00001, INV-2026-00001.
 * The counter row is locked while the next number is taken, so two staff members can never get the same number.
 */
final class DocumentNumbers
{
    public const RECEIPT = 'RCPT';

    public const INVOICE = 'INV';

    public function __construct(private readonly Database $db)
    {
    }

    public function next(string $prefix): string
    {
        $year = (int) Format::local()->format('Y');

        return $this->db->transaction(function () use ($prefix, $year): string {
            $this->db->execute(
                'INSERT INTO document_sequences (name, year, last_value) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE name = name',
                [$prefix, $year],
            );
            $current = (int) $this->db->fetchValue('SELECT last_value FROM document_sequences WHERE name = ? AND year = ? FOR UPDATE', [$prefix, $year]);
            $next = $current + 1;
            $this->db->execute('UPDATE document_sequences SET last_value = ? WHERE name = ? AND year = ?', [$next, $prefix, $year]);

            return sprintf('%s-%d-%05d', $prefix, $year, $next);
        });
    }
}
