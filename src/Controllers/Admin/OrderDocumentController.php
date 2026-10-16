<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Services\OrderDocuments;

/** Files staff attach to an order: commercial invoice, packing list, B/L or AWB, QC report, other. */
final class OrderDocumentController extends AdminController
{
    public function __construct(
        private readonly Order $orders,
        private readonly OrderDocument $documents,
        private readonly OrderDocuments $service,
    ) {
    }

    /** POST /v1/admin/orders/{id}/documents (multipart: file, type, title, is_public) */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $data = Validator::make($request->all(), [
            'type' => 'required|in:' . implode(',', array_keys(OrderDocument::TYPES)),
            'title' => 'nullable|string|max:160',
            'is_public' => 'nullable|bool',
        ])->validate();
        $order = $this->orders->find($this->id($request));
        if ($order === null) {
            throw HttpException::notFound('This order does not exist.');
        }
        $upload = $request->files()['file'] ?? null;

        $document = $this->service->upload($order, $this->staff($request), $data['type'], $data['title'] ?? null, (bool) ($data['is_public'] ?? true), is_array($upload) ? $upload : null);

        return Response::data(['id' => $document['id'], 'title' => $document['title']], [], 201);
    }

    /** GET /v1/admin/order-documents/{id} */
    public function file(Request $request): Response
    {
        $this->authorize($request, 'sales', 'production');
        $document = $this->document($request);
        $content = $this->service->content($document);
        $order = $this->orders->find($document['order_id']);
        if ($content === null || $order === null) {
            throw HttpException::notFound('This file is missing.');
        }

        return Response::file($content, $document['mime'], OrderDocuments::filename($document, $order['order_no']));
    }

    /** DELETE /v1/admin/order-documents/{id} */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $this->service->delete($this->document($request));

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function document(Request $request): array
    {
        $document = $this->documents->find($this->id($request));
        if ($document === null) {
            throw HttpException::notFound('This document does not exist.');
        }

        return $document;
    }
}
