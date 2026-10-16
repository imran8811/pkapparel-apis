<?php

declare(strict_types=1);

namespace App\Models;

final class Faq extends Repository
{
    public const CATEGORIES = ['ordering', 'production', 'payment', 'shipping', 'samples', 'quality'];

    protected const TABLE = 'faqs';

    protected const FILLABLE = ['category', 'question', 'answer', 'sort_order', 'is_published'];

    /** @return list<array{id: int, category: string, question: string, answer: string}> */
    public function published(?string $category = null): array
    {
        $sql = 'SELECT id, category, question, answer FROM faqs WHERE is_published = 1';
        $params = [];
        if ($category !== null) {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }

        $rows = $this->db->fetchAll($sql . ' ORDER BY sort_order, id', $params);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'category' => (string) $row['category'],
            'question' => (string) $row['question'],
            'answer' => (string) $row['answer'],
        ], $rows);
    }
}
