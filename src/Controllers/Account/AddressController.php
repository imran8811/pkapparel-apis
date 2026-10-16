<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\CustomerAddress;

/** The buyer's delivery addresses (address book). */
final class AddressController
{
    public function __construct(private readonly CustomerAddress $addresses)
    {
    }

    /** GET /v1/account/addresses */
    public function index(Request $request): Response
    {
        return Response::data($this->addresses->forCustomer((int) AuthController::customer($request)['id']));
    }

    /** POST /v1/account/addresses */
    public function store(Request $request): Response
    {
        $customerId = (int) AuthController::customer($request)['id'];
        $data = self::validated($request);
        if ($this->addresses->count($customerId) >= CustomerAddress::MAX_PER_CUSTOMER) {
            throw new ValidationException(['line1' => sprintf('You can keep up to %d addresses. Delete one you no longer use.', CustomerAddress::MAX_PER_CUSTOMER)]);
        }

        $id = $this->addresses->create($customerId, $data, (bool) ($data['is_default'] ?? false));

        return Response::data($this->addresses->find($customerId, $id), [], 201);
    }

    /** PUT /v1/account/addresses/{id} */
    public function update(Request $request): Response
    {
        $customerId = (int) AuthController::customer($request)['id'];
        $id = (int) $request->param('id');
        if ($this->addresses->find($customerId, $id) === null) {
            throw HttpException::notFound('This address does not exist.');
        }
        $data = self::validated($request);
        $this->addresses->update($customerId, $id, $data, (bool) ($data['is_default'] ?? false));

        return Response::data($this->addresses->find($customerId, $id));
    }

    /** DELETE /v1/account/addresses/{id}: orders keep their own copy of the address. */
    public function destroy(Request $request): Response
    {
        $customerId = (int) AuthController::customer($request)['id'];
        $id = (int) $request->param('id');
        if ($this->addresses->find($customerId, $id) === null) {
            throw HttpException::notFound('This address does not exist.');
        }
        $this->addresses->delete($customerId, $id);

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private static function validated(Request $request): array
    {
        return Validator::make($request->all(), [
            'company' => 'required|string|min:2|max:160',
            'contact_name' => 'required|string|min:2|max:120',
            'phone' => 'required|phone',
            'email' => 'nullable|email',
            'line1' => 'required|string|min:3|max:190',
            'line2' => 'nullable|string|max:190',
            'city' => 'required|string|min:2|max:120',
            'state' => 'nullable|string|max:120',
            'postal_code' => 'nullable|string|max:30',
            'country_code' => 'required|country',
            'tax_id' => 'nullable|string|max:60',
            'is_default' => 'nullable|bool',
        ])->validate();
    }
}
