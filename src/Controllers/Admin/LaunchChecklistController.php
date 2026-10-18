<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\LaunchChecklist;

/** /v1/admin/launch-checklist: what is still missing before the shop takes orders (admins only). */
final class LaunchChecklistController extends AdminController
{
    public function __construct(private readonly LaunchChecklist $checklist)
    {
    }

    /** GET /v1/admin/launch-checklist */
    public function show(Request $request): Response
    {
        $this->authorize($request);

        return Response::data($this->checklist->items());
    }

    /** PUT /v1/admin/launch-checklist/{key} {done} */
    public function tick(Request $request): Response
    {
        $this->authorize($request);
        $data = Validator::make($request->all(), ['done' => 'required|bool'])->validate();
        $this->checklist->tick((string) $request->param('key'), (bool) $data['done'], $this->staff($request));

        return Response::data($this->checklist->items());
    }
}
