<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Production progress of an order, stage by stage (§5). */
final class OrderStage
{
    /** The stages in production order, with the names buyers see. */
    public const STAGES = [
        'fabric' => 'Fabric & trims',
        'cutting' => 'Cutting',
        'stitching' => 'Stitching',
        'washing' => 'Washing',
        'finishing' => 'Finishing',
        'qc' => 'Quality check',
        'packing' => 'Packing',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, array{started_on: ?string, completed_on: ?string, note: ?string}> stage => dates, for the stages that have started */
    public function forOrder(int $orderId): array
    {
        $rows = $this->db->fetchAll('SELECT stage, started_on, completed_on, note FROM order_stages WHERE order_id = ?', [$orderId]);
        $stages = [];
        foreach ($rows as $row) {
            $stages[(string) $row['stage']] = [
                'started_on' => $row['started_on'],
                'completed_on' => $row['completed_on'],
                'note' => $row['note'],
            ];
        }

        return $stages;
    }

    public function save(int $orderId, string $stage, ?string $startedOn, ?string $completedOn, ?string $note, int $staffId): void
    {
        $this->db->execute(
            'INSERT INTO order_stages (order_id, stage, started_on, completed_on, note, updated_by) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE started_on = VALUES(started_on), completed_on = VALUES(completed_on), note = VALUES(note), updated_by = VALUES(updated_by)',
            [$orderId, $stage, $startedOn, $completedOn, $note, $staffId],
        );
    }

    /**
     * The stages as a list for display, including the ones not started yet.
     *
     * @param array<string, array{started_on: ?string, completed_on: ?string, note: ?string}> $saved
     * @return list<array{key: string, label: string, status: string, started_on: ?string, completed_on: ?string, note: ?string}>
     */
    public static function timeline(array $saved): array
    {
        $timeline = [];
        foreach (self::STAGES as $key => $label) {
            $row = $saved[$key] ?? ['started_on' => null, 'completed_on' => null, 'note' => null];
            $timeline[] = [
                'key' => $key,
                'label' => $label,
                'status' => $row['completed_on'] !== null ? 'done' : ($row['started_on'] !== null ? 'in_progress' : 'not_started'),
                'started_on' => $row['started_on'],
                'completed_on' => $row['completed_on'],
                'note' => $row['note'],
            ];
        }

        return $timeline;
    }
}
