<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The stock lines of a cart: sets of stock colors. They live in the same cart (Cart: one per buyer or guest) as the
 * wholesale lines, so the guest cart cookie and the merge at login work the same way.
 */
final class StockCart
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array{color_id: int, sets: int}> In the order they were added. */
    public function lines(int $cartId): array
    {
        $rows = $this->db->fetchAll('SELECT color_id, sets FROM stock_cart_lines WHERE cart_id = ? ORDER BY id', [$cartId]);

        return array_map(static fn (array $row): array => ['color_id' => (int) $row['color_id'], 'sets' => (int) $row['sets']], $rows);
    }

    public function sets(int $cartId, int $colorId): ?int
    {
        $sets = $this->db->fetchValue('SELECT sets FROM stock_cart_lines WHERE cart_id = ? AND color_id = ?', [$cartId, $colorId]);

        return $sets === null ? null : (int) $sets;
    }

    public function lineCount(int $cartId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM stock_cart_lines WHERE cart_id = ?', [$cartId]);
    }

    /** Sets the sets of a color (0 removes it). Touches the cart, so a guest cart in use is not purged. */
    public function setLine(int $cartId, int $colorId, int $sets): void
    {
        if ($sets <= 0) {
            $this->removeLine($cartId, $colorId);

            return;
        }
        $this->db->execute(
            'INSERT INTO stock_cart_lines (cart_id, color_id, sets) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE sets = VALUES(sets)',
            [$cartId, $colorId, $sets],
        );
        $this->touch($cartId);
    }

    public function removeLine(int $cartId, int $colorId): void
    {
        $this->db->execute('DELETE FROM stock_cart_lines WHERE cart_id = ? AND color_id = ?', [$cartId, $colorId]);
        $this->touch($cartId);
    }

    public function clear(int $cartId): void
    {
        $this->db->execute('DELETE FROM stock_cart_lines WHERE cart_id = ?', [$cartId]);
    }

    private function touch(int $cartId): void
    {
        $this->db->execute('UPDATE carts SET updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$cartId]);
    }
}
