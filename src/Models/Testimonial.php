<?php

declare(strict_types=1);

namespace App\Models;

final class Testimonial extends Repository
{
    protected const TABLE = 'testimonials';

    protected const FILLABLE = ['author_name', 'author_role', 'company', 'country_code', 'quote', 'rating', 'sort_order', 'is_published'];

    /** @return list<array<string, mixed>> */
    public function published(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, author_name, author_role, company, country_code, quote, rating
             FROM testimonials WHERE is_published = 1 ORDER BY sort_order, id'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'author_name' => $row['author_name'],
            'author_role' => (string) $row['author_role'],
            'company' => $row['company'],
            'country_code' => $row['country_code'],
            'quote' => (string) $row['quote'],
            'rating' => $row['rating'] === null ? null : (int) $row['rating'],
        ], $rows);
    }
}
