<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Models\Order;
use App\Models\OrderDocument;

/**
 * Files staff attach to an order (owner decision: staff upload their own commercial invoice and packing list).
 * They are private files; the buyer sees the ones marked public on their order page.
 */
final class OrderDocuments
{
    public function __construct(
        private readonly OrderDocument $documents,
        private readonly Order $orders,
        private readonly PrivateFiles $files,
    ) {
    }

    /**
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     * @param array<string, mixed>|null $upload
     * @return array<string, mixed> the document
     */
    public function upload(array $order, array $staff, string $type, ?string $title, bool $isPublic, ?array $upload): array
    {
        $file = $this->files->store($upload, 'documents', 'file');
        if ($file === null) {
            throw new ValidationException(['file' => 'Choose a file (PDF or photo).']);
        }
        $title = $title !== null && trim($title) !== '' ? trim($title) : OrderDocument::TYPES[$type];

        $id = $this->documents->create($order['id'], [
            'type' => $type,
            'title' => mb_substr($title, 0, 160),
            'path' => $file['path'],
            'mime' => $file['type'],
            'size_bytes' => $file['size'],
            'is_public' => $isPublic ? 1 : 0,
            'uploaded_by' => $staff['id'],
        ]);
        $this->orders->addEvent($order['id'], 'document', null, null, "{$title} added", 'staff', $staff['id'], $isPublic);

        return (array) $this->documents->find($id);
    }

    /** @param array<string, mixed> $document */
    public function delete(array $document): void
    {
        $this->documents->delete($document['id']);
        $this->files->delete($document['path']);
    }

    /** @param array<string, mixed> $document */
    public function content(array $document): ?string
    {
        return $this->files->read($document['path']);
    }

    /** "Commercial invoice PK-261016-482.pdf" */
    public static function filename(array $document, string $orderNo): string
    {
        $extension = pathinfo((string) $document['path'], PATHINFO_EXTENSION);

        return trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '', $document['title'])) . ' ' . $orderNo . '.' . $extension;
    }
}
