<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\StockColor;
use App\Models\StockMovement;

/**
 * Changes the sets in stock of a stock color, one change at a time: the color's row is locked while the new
 * count is worked out, so two changes at once never lose one, and stock never goes below zero.
 * Every change is logged in stock_movements with its reason.
 */
final class StockLevels
{
    public function __construct(
        private readonly Database $db,
        private readonly StockColor $colors,
        private readonly StockMovement $movements,
    ) {
    }

    /**
     * @param string $reason a StockMovement::REASONS key: received and returned add $sets, sold and damaged take
     *                       $sets out, correction makes $sets the new total (the number counted)
     * @return int the sets in stock after the change
     */
    public function change(int $colorId, string $reason, int $sets, ?string $note, ?int $staffId, ?int $orderId = null): int
    {
        if (!isset(StockMovement::REASONS[$reason])) {
            throw new ValidationException(['reason' => 'Choose why the stock changes.']);
        }
        if ($sets < 0 || ($sets === 0 && $reason !== 'correction')) {
            throw new ValidationException(['sets' => 'Enter a number of sets.']);
        }

        return $this->db->transaction(function () use ($colorId, $reason, $sets, $note, $staffId, $orderId): int {
            $current = $this->colors->lockSets($colorId) ?? throw HttpException::notFound('This color does not exist.');
            $after = match ($reason) {
                'received', 'returned' => $current + $sets,
                'sold', 'damaged' => $current - $sets,
                default => $sets,
            };
            if ($after < 0) {
                throw new ValidationException(['sets' => sprintf('Only %d %s in stock.', $current, $current === 1 ? 'set is' : 'sets are')]);
            }
            if ($after === $current) {
                throw new ValidationException(['sets' => 'That is already the number of sets in stock.']);
            }
            $this->colors->setSets($colorId, $after);
            $this->movements->record($colorId, $after - $current, $after, $reason, $note, $orderId, $staffId);

            return $after;
        });
    }
}
