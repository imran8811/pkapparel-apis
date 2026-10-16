<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Carts of buyers (customer_id) and guests (token_hash of the cart cookie).
 * A line is one color of a style with a quantity per size; prices come from the catalog.
 */
final class Cart
{
    /** Guest carts not touched for this many days are deleted. */
    public const GUEST_DAYS = 90;

    public function __construct(private readonly Database $db)
    {
    }

    public function idForCustomer(int $customerId): ?int
    {
        $id = $this->db->fetchValue('SELECT id FROM carts WHERE customer_id = ?', [$customerId]);

        return $id === null ? null : (int) $id;
    }

    public function idForToken(string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $id = $this->db->fetchValue(
            'SELECT id FROM carts WHERE token_hash = ? AND customer_id IS NULL AND updated_at > UTC_TIMESTAMP() - INTERVAL ' . self::GUEST_DAYS . ' DAY',
            [hash('sha256', $token)],
        );

        return $id === null ? null : (int) $id;
    }

    public function createForCustomer(int $customerId): int
    {
        $this->db->execute('INSERT IGNORE INTO carts (customer_id) VALUES (?)', [$customerId]);

        return (int) $this->idForCustomer($customerId);
    }

    /** @return array{id: int, token: string} A new guest cart and the token for its cookie. */
    public function createGuest(): array
    {
        $this->purgeGuests();
        $token = bin2hex(random_bytes(32));

        return ['id' => $this->db->insert('carts', ['token_hash' => hash('sha256', $token)]), 'token' => $token];
    }

    /** @return list<array{product_id: int, color_id: int, size_qty: array<string, int>}> In the order they were added. */
    public function lines(int $cartId): array
    {
        $rows = $this->db->fetchAll('SELECT product_id, color_id, size_qty FROM cart_lines WHERE cart_id = ? ORDER BY id', [$cartId]);

        return array_map(static fn (array $row): array => [
            'product_id' => (int) $row['product_id'],
            'color_id' => (int) $row['color_id'],
            'size_qty' => self::decodeSizes((string) $row['size_qty']),
        ], $rows);
    }

    /** @return array<string, int>|null */
    public function line(int $cartId, int $colorId): ?array
    {
        $json = $this->db->fetchValue('SELECT size_qty FROM cart_lines WHERE cart_id = ? AND color_id = ?', [$cartId, $colorId]);

        return $json === null ? null : self::decodeSizes((string) $json);
    }

    /** @param array<string, int> $sizeQty Sets the quantities of one color; all zero removes the line. */
    public function setLine(int $cartId, int $productId, int $colorId, array $sizeQty): void
    {
        $sizeQty = array_filter($sizeQty, static fn (int $qty): bool => $qty > 0);
        if ($sizeQty === []) {
            $this->removeLine($cartId, $colorId);

            return;
        }

        $this->db->execute(
            'INSERT INTO cart_lines (cart_id, product_id, color_id, size_qty) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE size_qty = VALUES(size_qty), product_id = VALUES(product_id)',
            [$cartId, $productId, $colorId, json_encode((object) $sizeQty, JSON_THROW_ON_ERROR)],
        );
        $this->touch($cartId);
    }

    public function removeLine(int $cartId, int $colorId): void
    {
        $this->db->execute('DELETE FROM cart_lines WHERE cart_id = ? AND color_id = ?', [$cartId, $colorId]);
        $this->touch($cartId);
    }

    public function clear(int $cartId): void
    {
        $this->db->execute('DELETE FROM cart_lines WHERE cart_id = ?', [$cartId]);
        $this->touch($cartId);
    }

    public function delete(int $cartId): void
    {
        $this->db->execute('DELETE FROM carts WHERE id = ?', [$cartId]);
    }

    public function lineCount(int $cartId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM cart_lines WHERE cart_id = ?', [$cartId]);
    }

    private function touch(int $cartId): void
    {
        $this->db->execute('UPDATE carts SET updated_at = UTC_TIMESTAMP() WHERE id = ?', [$cartId]);
    }

    private function purgeGuests(): void
    {
        $this->db->execute('DELETE FROM carts WHERE customer_id IS NULL AND updated_at < UTC_TIMESTAMP() - INTERVAL ' . self::GUEST_DAYS . ' DAY');
    }

    /** @return array<string, int> */
    private static function decodeSizes(string $json): array
    {
        $decoded = json_decode($json, true);
        $sizes = [];
        foreach (is_array($decoded) ? $decoded : [] as $size => $qty) {
            $sizes[(string) $size] = (int) $qty;
        }

        return $sizes;
    }
}
