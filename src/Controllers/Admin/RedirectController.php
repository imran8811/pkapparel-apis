<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\MissingPage;
use App\Models\Redirect;
use App\Services\Redirects;

/** /v1/admin/redirects and /v1/admin/missing-pages (editors and admins). */
final class RedirectController extends AdminController
{
    public function __construct(private readonly Redirect $redirects, private readonly MissingPage $missing, private readonly Redirects $service)
    {
    }

    /** GET /v1/admin/redirects?q=&page= */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');
        [$page, $perPage] = $this->pagination($request, 50);
        $search = trim((string) ($request->allQuery()['q'] ?? '')) ?: null;
        $result = $this->redirects->adminList($search, $page, $perPage);

        return Response::data($result['items'], self::paginationMeta($page, $perPage, $result['total']) + $this->counts());
    }

    /** POST /v1/admin/redirects {from_path, to_path} */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $data = $this->input($request);

        return Response::data($this->service->save(null, $data['from_path'], $data['to_path'], $this->staff($request)), [], 201);
    }

    /** PUT /v1/admin/redirects/{id} {from_path, to_path} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->findOrFail($request);
        $data = $this->input($request);

        return Response::data($this->service->save($id, $data['from_path'], $data['to_path'], $this->staff($request)));
    }

    /** DELETE /v1/admin/redirects/{id} */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $this->service->delete($this->findOrFail($request));

        return Response::noContent();
    }

    /** GET /v1/admin/missing-pages?status=open|ignored&page= */
    public function missing(Request $request): Response
    {
        $this->authorize($request, 'editor');
        [$page, $perPage] = $this->pagination($request, 50);
        $ignored = ($request->allQuery()['status'] ?? 'open') === 'ignored';
        $result = $this->missing->adminList($ignored, $page, $perPage);

        return Response::data($result['items'], self::paginationMeta($page, $perPage, $result['total']) + $this->counts());
    }

    /** POST /v1/admin/missing-pages/{id}/ignore */
    public function ignore(Request $request): Response
    {
        return $this->setIgnored($request, true);
    }

    /** POST /v1/admin/missing-pages/{id}/restore */
    public function restore(Request $request): Response
    {
        return $this->setIgnored($request, false);
    }

    private function setIgnored(Request $request, bool $ignored): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        if ($this->missing->find($id) === null) {
            throw HttpException::notFound('This address is no longer in the list.');
        }
        $this->missing->setIgnored($id, $ignored);

        return Response::noContent();
    }

    /** @return array{counts: array{redirects: int, missing: int, ignored: int}} */
    private function counts(): array
    {
        $missing = $this->missing->counts();

        return ['counts' => ['redirects' => $this->redirects->count(), 'missing' => $missing['open'], 'ignored' => $missing['ignored']]];
    }

    /** @return array{from_path: string, to_path: string} */
    private function input(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'from_path' => 'required|string|max:2000',
            'to_path' => 'required|string|max:2000',
        ])->validate();

        return ['from_path' => (string) $data['from_path'], 'to_path' => (string) $data['to_path']];
    }

    private function findOrFail(Request $request): int
    {
        $id = $this->id($request);
        if ($this->redirects->find($id) === null) {
            throw HttpException::notFound('This redirect does not exist.');
        }

        return $id;
    }
}
