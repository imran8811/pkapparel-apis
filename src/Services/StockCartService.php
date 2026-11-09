<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\Cart;
use App\Models\StockCart;
use App\Models\StockColor;
use App\Models\StockStyle;

/**
 * The stock part of a cart: sets of stock colors, priced per set from the stock styles. It uses the same cart as the
 * wholesale lines (CartService finds or creates it for the buyer or guest), and is checked against the stock: a color
 * can never be added beyond its sets in stock. The stock is not held for a cart or an order; it is taken out when an
 * order's payment is confirmed (StockOrders::deduct).
 */
final class StockCartService
{
    public const MAX_LINES = 100;

    public const MAX_SETS = 10_000;

    public function __construct(
        private readonly CartService $carts,
        private readonly Cart $cartRows,
        private readonly StockCart $lines,
        private readonly StockStyle $styles,
        private readonly StockColor $colors,
        private readonly StockSettings $settings,
    ) {
    }

    /** The cart to read, or null when the visitor has none yet. */
    public function find(?int $customerId, ?string $guestToken): ?int
    {
        return $this->carts->find($customerId, $guestToken);
    }

    /** @return array{id: int, token: ?string} The cart to write to (token only for a new guest cart). */
    public function findOrCreate(?int $customerId, ?string $guestToken): array
    {
        return $this->carts->findOrCreate($customerId, $guestToken);
    }

    /**
     * The priced stock cart: one line per color, with what blocks the checkout.
     *
     * @return array<string, mixed>
     */
    public function summary(?int $cartId): array
    {
        $rows = $cartId === null ? [] : $this->lines->lines($cartId);
        $colors = [];
        $styles = [];
        foreach ($rows as $row) {
            $color = $this->colors->find($row['color_id']);
            if ($color !== null) {
                $colors[$row['color_id']] = $color;
                $styles[$color['style_id']] ??= $this->styles->find($color['style_id']);
            }
        }
        $images = [];
        foreach ($this->colors->forStyles(array_keys(array_filter($styles)), false, 1) as $styleColors) {
            foreach ($styleColors as $color) {
                $images[$color['id']] = $color['images'][0] ?? null;
            }
        }

        $lines = [];
        $issues = [];
        foreach ($rows as $row) {
            $color = $colors[$row['color_id']] ?? null;
            $style = $color === null ? null : ($styles[$color['style_id']] ?? null);
            if ($color === null || $style === null) {
                continue;
            }
            $available = $style['status'] === 'active' && $color['is_active'] && $color['sets_in_stock'] > 0;
            $short = $available && $row['sets'] > $color['sets_in_stock'];
            $label = "{$style['name']}, {$color['name']}";
            if (!$available) {
                $issues[] = "{$label} is sold out. Please remove it.";
            } elseif ($short) {
                $issues[] = sprintf('Only %d %s of %s %s in stock. Please lower the sets.', $color['sets_in_stock'], $color['sets_in_stock'] === 1 ? 'set' : 'sets', $label, $color['sets_in_stock'] === 1 ? 'is' : 'are');
            }
            $lines[] = [
                'color_id' => $color['id'],
                'code' => $color['code'],
                'name' => $color['name'],
                'slug' => $color['slug'],
                'hex' => $color['hex'],
                'image' => $images[$color['id']] ?? null,
                'style' => ['slug' => $style['slug'], 'name' => $style['name'], 'style_no' => $style['style_no'], 'segment' => $style['segment']],
                'set' => ['sizes' => $style['set_sizes'], 'pieces' => $style['set_pieces']],
                'set_price_cents' => $style['set_price_cents'],
                'sets' => $row['sets'],
                'pieces' => $row['sets'] * $style['set_pieces'],
                'line_cents' => $row['sets'] * $style['set_price_cents'],
                'sets_in_stock' => $color['sets_in_stock'],
                'available' => $available,
                'short' => $short,
            ];
        }

        $sets = array_sum(array_column($lines, 'sets'));
        $minimum = $this->settings->minSetsPerOrder();
        if ($lines !== [] && $sets < $minimum) {
            $issues[] = sprintf('The smallest order is %d sets. Please add %d more.', $minimum, $minimum - $sets);
        }

        return [
            'lines' => $lines,
            'line_count' => count($lines),
            'total_sets' => $sets,
            'total_pieces' => array_sum(array_column($lines, 'pieces')),
            'total_cents' => array_sum(array_column($lines, 'line_cents')),
            'currency' => StockSettings::CURRENCY,
            'min_sets_per_order' => $minimum,
            'issues' => $issues,
            'can_checkout' => $lines !== [] && $issues === [],
        ];
    }

    /** Adds sets of a color (add) or sets its number of sets (replace; 0 removes it). */
    public function change(int $cartId, int $colorId, int $sets, bool $replace): void
    {
        $color = $this->colors->find($colorId);
        $style = $color === null ? null : $this->styles->find($color['style_id']);
        if ($color === null || $style === null) {
            throw HttpException::notFound('This color does not exist.');
        }
        if (!$replace && $sets < 1) {
            throw new ValidationException(['sets' => 'Choose at least one set.']);
        }
        $current = $this->lines->sets($cartId, $colorId);
        $new = $replace ? $sets : ($current ?? 0) + $sets;

        if ($new > 0) {
            if ($style['status'] !== 'active' || !$color['is_active'] || $color['sets_in_stock'] === 0) {
                throw new ValidationException(['sets' => 'This color is sold out.']);
            }
            if ($new > $color['sets_in_stock']) {
                $inCart = $current !== null && !$replace ? sprintf(' You already have %d in your cart.', $current) : '';
                throw new ValidationException(['sets' => sprintf('Only %d %s in stock.%s', $color['sets_in_stock'], $color['sets_in_stock'] === 1 ? 'set is' : 'sets are', $inCart)]);
            }
            if ($current === null && $this->lines->lineCount($cartId) >= self::MAX_LINES) {
                throw new ValidationException(['sets' => sprintf('A cart can hold up to %d colors. Place this order first.', self::MAX_LINES)]);
            }
        }
        $this->lines->setLine($cartId, $colorId, min($new, self::MAX_SETS));
    }

    public function remove(int $cartId, int $colorId): void
    {
        $this->lines->removeLine($cartId, $colorId);
    }

    public function clear(int $cartId): void
    {
        $this->lines->clear($cartId);
    }

    /**
     * Moves a guest's stock lines into the buyer's cart (sets of the same color are added up). Call it before
     * CartService::mergeGuest, which deletes the guest's cart.
     */
    public function mergeGuest(string $guestToken, int $customerId): void
    {
        $guestId = $this->cartRows->idForToken($guestToken);
        $lines = $guestId === null ? [] : $this->lines->lines($guestId);
        if ($guestId === null || $lines === []) {
            return;
        }
        $cartId = $this->cartRows->idForCustomer($customerId) ?? $this->cartRows->createForCustomer($customerId);
        foreach ($lines as $line) {
            $current = $this->lines->sets($cartId, $line['color_id']) ?? 0;
            $this->lines->setLine($cartId, $line['color_id'], min(self::MAX_SETS, $current + $line['sets']));
        }
        $this->lines->clear($guestId);
    }
}
