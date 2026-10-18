<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Inquiry;
use App\Models\InquiryFile;
use App\Services\InquiryAttachments;

/** Website inquiries as a sales pipeline (admin and sales). */
final class InquiryController extends AdminController
{
    public function __construct(
        private readonly Inquiry $inquiries,
        private readonly InquiryFile $files,
        private readonly InquiryAttachments $attachments,
    ) {
    }

    /** GET /v1/admin/inquiries?status=open|new|…&type=quote&q=…&page=1 */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'sales');
        [$page, $perPage] = $this->pagination($request, 25);
        $filters = Validator::make($request->allQuery(), [
            'status' => 'nullable|in:open,' . implode(',', array_keys(Inquiry::STATUSES)),
            'type' => 'nullable|in:' . implode(',', array_keys(Inquiry::TYPES)),
            'q' => 'nullable|string|max:120',
        ])->validate();

        $result = $this->inquiries->adminList($filters, $page, $perPage);

        return Response::data(
            $result['items'],
            self::paginationMeta($page, $perPage, $result['total']) + ['counts' => $this->inquiries->countByStatus()],
        );
    }

    /** GET /v1/admin/inquiries/{id} */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->findOrFail($this->id($request)));
    }

    /** PATCH /v1/admin/inquiries/{id} {status, notes} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $id = $this->id($request);
        $this->findOrFail($id);

        $data = Validator::make($request->all(), [
            'status' => 'required|in:' . implode(',', array_keys(Inquiry::STATUSES)),
            'notes' => 'nullable|string|max:5000',
        ])->validate();

        $this->inquiries->updateFollowUp($id, $data['status'], $data['notes'] ?? null, $this->staff($request)['id']);

        return Response::data($this->findOrFail($id));
    }

    /** GET /v1/admin/inquiry-files/{id}: a file the sender attached. */
    public function file(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $file = $this->files->find($this->id($request));
        $content = $file === null || $file['inquiry_id'] === null ? null : $this->attachments->content($file);
        if ($file === null || $content === null) {
            throw HttpException::notFound('This file is missing.');
        }

        return Response::file($content, $file['mime'], $file['name']);
    }

    /** @return array<string, mixed> the inquiry with its attached files */
    private function findOrFail(int $id): array
    {
        $inquiry = $this->inquiries->find($id) ?? throw HttpException::notFound('This inquiry does not exist.');

        return $inquiry + ['files' => array_map(
            static fn (array $file): array => ['id' => $file['id'], 'name' => $file['name'], 'mime' => $file['mime'], 'size_bytes' => $file['size_bytes']],
            $this->files->forInquiry($id),
        )];
    }
}
