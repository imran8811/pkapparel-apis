<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Format;
use App\Core\HttpException;
use App\Core\Logger;
use App\Models\Order;
use App\Models\OrderPhoto;
use App\Models\OrderStage;
use Throwable;

/**
 * Production tracking: stages with dates (§5), and private photos the buyer sees on their order page.
 * Starting the first stage moves a confirmed order to "In production".
 */
final class Production
{
    /** Orders whose production can be updated. */
    private const WORKING = ['confirmed', 'in_production', 'on_hold', 'ready_to_ship'];

    public function __construct(
        private readonly Database $db,
        private readonly Order $orders,
        private readonly OrderStage $stages,
        private readonly OrderPhoto $photos,
        private readonly PrivateFiles $files,
        private readonly OrderService $service,
        private readonly OrderMailer $mailer,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Starts or finishes a stage ("reset" clears it, to undo a mistake).
     *
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     */
    public function updateStage(array $order, array $staff, string $stage, string $action, string $date, ?string $note, bool $notify): void
    {
        if (!isset(OrderStage::STAGES[$stage])) {
            throw HttpException::notFound('Unknown production stage.');
        }
        if (!in_array($order['status'], self::WORKING, true)) {
            throw new HttpException(409, 'wrong_status', 'Production can be updated once the deposit is confirmed.');
        }
        $current = $this->stages->forOrder($order['id'])[$stage] ?? ['started_on' => null, 'completed_on' => null, 'note' => null];
        $label = OrderStage::STAGES[$stage];

        [$started, $completed, $event] = match ($action) {
            'start' => [$date, null, "{$label} started"],
            'done' => [$current['started_on'] ?? $date, $date, "{$label} done"],
            default => [null, null, "{$label} reset"],
        };
        if ($completed !== null && $started !== null && $completed < $started) {
            throw new HttpException(422, 'validation_failed', 'A stage cannot be done before it started.');
        }

        $this->db->transaction(function () use ($order, $staff, $stage, $started, $completed, $note, $current, $event, $action): void {
            $this->stages->save($order['id'], $stage, $started, $completed, $note ?? $current['note'], $staff['id']);
            $this->orders->addEvent($order['id'], 'stage', null, null, $note !== null && $note !== '' ? "{$event}: {$note}" : $event, 'staff', $staff['id'], $action !== 'reset');
            if ($order['status'] === 'confirmed' && $action !== 'reset') {
                $this->orders->changeStatus($order['id'], 'confirmed', 'in_production', 'staff', $staff['id']);
            }
        });

        if ($notify && $action !== 'reset') {
            $photos = count(array_filter($this->photos->forOrder($order['id']), static fn (array $p): bool => $p['stage'] === $stage));
            $this->notifyBuyer($order['id'], fn (array $view) => $this->mailer->productionUpdate($view, $label, $action === 'done', $photos, $note));
        }
    }

    /**
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     * @param array<string, mixed>|null $upload
     * @return array<string, mixed> the photo
     */
    public function addPhoto(array $order, array $staff, ?array $upload, ?string $stage, ?string $caption): array
    {
        if (!in_array($order['status'], [...self::WORKING, 'shipped'], true)) {
            throw new HttpException(409, 'wrong_status', 'Photos can be added once the deposit is confirmed.');
        }
        if ($stage !== null && !isset(OrderStage::STAGES[$stage])) {
            throw HttpException::notFound('Unknown production stage.');
        }
        $stored = $this->files->storePhoto($upload, 'production', 'photo');
        $id = $this->photos->create($order['id'], $stored + ['stage' => $stage, 'caption' => $caption, 'uploaded_by' => $staff['id']]);

        return OrderPhoto::present((array) $this->photos->find($id));
    }

    public function deletePhoto(array $photo): void
    {
        $this->photos->delete($photo['id']);
        $this->files->delete($photo['path']);
        $this->files->delete($photo['preview_path']);
    }

    /** @return string|null the image, or null when the file is missing */
    public function photoFile(array $photo, bool $preview): ?string
    {
        return $this->files->read($preview ? $photo['preview_path'] : $photo['path']);
    }

    public static function today(): string
    {
        return Format::local()->format('Y-m-d');
    }

    private function notifyBuyer(int $orderId, callable $send): void
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return;
        }
        try {
            $send($this->service->present($order, false));
        } catch (Throwable $e) {
            $this->logger->error('Production email could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
        }
    }
}
