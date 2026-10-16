<?php

declare(strict_types=1);

namespace App\Models;

final class Certification extends Repository
{
    protected const TABLE = 'certifications';

    protected const FILLABLE = [
        'name', 'issuer', 'certificate_no', 'member_since', 'valid_until', 'description', 'image_path', 'sort_order', 'is_published',
    ];

    /** @return list<array<string, mixed>> */
    public function published(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, name, issuer, certificate_no, member_since, valid_until, description, image_path
             FROM certifications WHERE is_published = 1 ORDER BY sort_order, id'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'issuer' => $row['issuer'],
            'certificate_no' => $row['certificate_no'],
            'member_since' => $row['member_since'],
            'valid_until' => $row['valid_until'],
            'description' => $row['description'],
            'image' => empty($row['image_path']) ? null : ['path' => (string) $row['image_path'], 'alt' => (string) $row['name']],
        ], $rows);
    }
}
