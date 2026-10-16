<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Text for the shop's segment pages (/wholesale-shop/men, product_type '') and
 * category pages (/wholesale-shop/men/jeans-pant): title, intro, FAQ and SEO.
 */
final class Category
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map([self::class, 'present'], $this->db->fetchAll('SELECT * FROM categories ORDER BY sort_order, id'));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM categories WHERE id = ?', [$id]);

        return $row === null ? null : self::present($row);
    }

    /** @param array{title: string, intro_html: string, faq: list<array{question: string, answer: string}>, seo_title: ?string, seo_description: ?string} $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('categories', [
            'title' => $data['title'],
            'intro_html' => $data['intro_html'],
            'faq' => json_encode(array_values($data['faq']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'seo_title' => $data['seo_title'],
            'seo_description' => $data['seo_description'],
        ], ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'segment' => (string) $row['segment'],
            'product_type' => (string) $row['product_type'],
            'title' => (string) $row['title'],
            'intro_html' => (string) $row['intro_html'],
            'faq' => json_decode((string) $row['faq'], true) ?: [],
            'seo_title' => $row['seo_title'],
            'seo_description' => $row['seo_description'],
            'updated_at' => gmdate(DATE_ATOM, (int) strtotime($row['updated_at'] . ' UTC')),
        ];
    }
}
