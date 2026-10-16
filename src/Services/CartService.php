<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductColor;

/**
 * The cart of the current buyer (by account) or guest (by the token in their cart cookie).
 * Guests' carts are merged into the account cart when they log in or sign up.
 */
final class CartService
{
    public const MAX_PER_SIZE = 100_000;

    public const MAX_LINES = 100;

    public function __construct(
        private readonly Cart $carts,
        private readonly Product $products,
        private readonly ProductColor $colors,
        private readonly CartPricing $pricing,
    ) {
    }

    /** The cart to read, or null when the visitor has none yet. */
    public function find(?int $customerId, ?string $guestToken): ?int
    {
        if ($customerId !== null) {
            return $this->carts->idForCustomer($customerId);
        }

        return $guestToken === null ? null : $this->carts->idForToken($guestToken);
    }

    /**
     * The cart to write to, created when needed.
     *
     * @return array{id: int, token: ?string} token is set only for a new guest cart (for the cookie)
     */
    public function findOrCreate(?int $customerId, ?string $guestToken): array
    {
        if ($customerId !== null) {
            return ['id' => $this->carts->idForCustomer($customerId) ?? $this->carts->createForCustomer($customerId), 'token' => null];
        }
        $id = $guestToken === null ? null : $this->carts->idForToken($guestToken);
        if ($id !== null) {
            return ['id' => $id, 'token' => null];
        }
        $guest = $this->carts->createGuest();

        return ['id' => $guest['id'], 'token' => $guest['token']];
    }

    /** @return array<string, mixed> The priced cart (an empty one when $cartId is null). */
    public function summary(?int $cartId): array
    {
        return $this->pricing->evaluate($cartId === null ? [] : $this->carts->lines($cartId));
    }

    /**
     * Adds pieces to a color (add) or replaces its quantities (set; all zero removes it).
     *
     * @param array<mixed> $sizeQty size => pieces
     */
    public function change(int $cartId, int $colorId, array $sizeQty, bool $replace): void
    {
        $color = $this->colors->find($colorId);
        $product = $color === null ? null : $this->products->find($color['product_id']);
        if ($color === null || $product === null) {
            throw HttpException::notFound('This color does not exist.');
        }

        $quantities = self::quantities($sizeQty, array_map('strval', $product['sizes']));
        $current = $this->carts->line($cartId, $colorId);

        if (!$replace || array_sum($quantities) > 0) {
            // New pieces can only be added for colors that are on sale.
            if ($product['status'] !== 'active' || !$color['is_active']) {
                throw new ValidationException(['color_id' => 'This color is no longer available.']);
            }
        }
        if ($current === null && array_sum($quantities) > 0 && $this->carts->lineCount($cartId) >= self::MAX_LINES) {
            throw new ValidationException(['color_id' => sprintf('A cart can hold up to %d colors. Place this order first.', self::MAX_LINES)]);
        }
        if (!$replace) {
            if (array_sum($quantities) === 0) {
                throw new ValidationException(['size_qty' => 'Enter at least one piece.']);
            }
            foreach ($current ?? [] as $size => $qty) {
                $quantities[(string) $size] = min(self::MAX_PER_SIZE, ($quantities[(string) $size] ?? 0) + $qty);
            }
        }

        $this->carts->setLine($cartId, $product['id'], $colorId, $quantities);
    }

    public function remove(int $cartId, int $colorId): void
    {
        $this->carts->removeLine($cartId, $colorId);
    }

    public function clear(int $cartId): void
    {
        $this->carts->clear($cartId);
    }

    /** Moves a guest's cart into the buyer's cart (quantities of the same color are added up). */
    public function mergeGuest(string $guestToken, int $customerId): void
    {
        $guestId = $this->carts->idForToken($guestToken);
        if ($guestId === null) {
            return;
        }
        $lines = $this->carts->lines($guestId);
        if ($lines !== []) {
            $cartId = $this->carts->idForCustomer($customerId) ?? $this->carts->createForCustomer($customerId);
            foreach ($lines as $line) {
                $current = $this->carts->line($cartId, $line['color_id']) ?? [];
                foreach ($line['size_qty'] as $size => $qty) {
                    $current[(string) $size] = min(self::MAX_PER_SIZE, ($current[(string) $size] ?? 0) + $qty);
                }
                $this->carts->setLine($cartId, $line['product_id'], $line['color_id'], $current);
            }
        }
        $this->carts->delete($guestId);
    }

    /**
     * Validates size => pieces for a style's sizes.
     *
     * @param array<mixed> $input
     * @param list<string> $sizes
     * @return array<string, int>
     */
    private static function quantities(array $input, array $sizes): array
    {
        $quantities = [];
        foreach ($input as $size => $qty) {
            $size = (string) $size;
            if (!in_array($size, $sizes, true)) {
                throw new ValidationException(['size_qty' => "Size {$size} is not offered for this style."]);
            }
            $value = filter_var($qty, FILTER_VALIDATE_INT);
            if ($value === false || is_bool($qty) || $value < 0 || $value > self::MAX_PER_SIZE) {
                throw new ValidationException(['size_qty' => sprintf('Enter whole numbers from 0 to %s per size.', number_format(self::MAX_PER_SIZE))]);
            }
            $quantities[$size] = $value;
        }

        return $quantities;
    }
}
