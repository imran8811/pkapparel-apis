<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\CustomizationOption;

/** Customization add-ons offered at checkout. Orders keep their own copy, so deleting one never changes an order. */
final class AddonController extends AdminController
{
    public function __construct(private readonly CustomizationOption $options)
    {
    }

    /** GET /v1/admin/addons */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->options->all());
    }

    /** POST /v1/admin/addons */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $id = $this->options->create($this->validated($request));

        return Response::data($this->options->find($id), [], 201);
    }

    /** PUT /v1/admin/addons/{id} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $id = $this->id($request);
        if ($this->options->find($id) === null) {
            throw HttpException::notFound('This add-on does not exist.');
        }
        $this->options->updateById($id, $this->validated($request));

        return Response::data($this->options->find($id));
    }

    /** DELETE /v1/admin/addons/{id} */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'sales');
        if (!$this->options->delete($this->id($request))) {
            throw HttpException::notFound('This add-on does not exist.');
        }

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:120',
            'description' => 'nullable|string|max:500',
            'pricing' => 'required|in:' . implode(',', CustomizationOption::PRICING),
            'price_cents' => 'nullable|int|min:1|max:100000000',
            'is_active' => 'nullable|bool',
            'sort_order' => 'nullable|int|min:0|max:100000',
        ])->validate();

        if ($data['pricing'] === 'on_request') {
            $data['price_cents'] = null;
        } elseif (($data['price_cents'] ?? null) === null) {
            throw new ValidationException(['price_cents' => 'Enter the price, or choose "price on request".']);
        }
        $data['description'] ??= null;
        $data['is_active'] ??= true;
        if (($data['sort_order'] ?? null) === null) {
            unset($data['sort_order']);
        }

        return $data;
    }
}
