<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\StaffUser;
use App\Services\TokenService;

/**
 * Staff accounts (admins only). A new or reset password is generated and returned once, to be passed on
 * to the person; they change it under "My account". Deactivating an account ends its sessions at once.
 */
final class StaffController extends AdminController
{
    public function __construct(private readonly StaffUser $staff, private readonly TokenService $tokens)
    {
    }

    /** GET /v1/admin/staff */
    public function index(Request $request): Response
    {
        $this->authorize($request);

        return Response::data(array_map(static fn (array $row): array => StaffUser::presentForAdmin($row), $this->staff->all()));
    }

    /** POST /v1/admin/staff {name, email, role}: returns the generated password once. */
    public function store(Request $request): Response
    {
        $this->authorize($request);
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email',
            'role' => 'required|in:' . implode(',', StaffUser::ROLES),
        ])->validate();
        if ($this->staff->findByEmail($data['email']) !== null) {
            throw new ValidationException(['email' => 'This email already has an account. Reset its password instead.']);
        }

        $password = StaffUser::generatePassword();
        $id = $this->staff->create($data['name'], $data['email'], $password, $data['role']);

        return Response::data(['staff' => StaffUser::presentForAdmin((array) $this->staff->find($id)), 'password' => $password], [], 201);
    }

    /** PUT /v1/admin/staff/{id} {name, role, is_active} */
    public function update(Request $request): Response
    {
        $this->authorize($request);
        $account = $this->account($request);
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:120',
            'role' => 'required|in:' . implode(',', StaffUser::ROLES),
            'is_active' => 'required|bool',
        ])->validate();

        // An admin cannot lock themselves out by mistake.
        if ((int) $account['id'] === $this->staff($request)['id'] && ($data['role'] !== 'admin' || $data['is_active'] !== true)) {
            throw new ValidationException(['role' => 'You cannot remove your own admin access. Ask another admin.']);
        }

        $this->staff->updateAccount((int) $account['id'], $data['name'], $data['role'], $data['is_active']);
        if ($data['is_active'] !== true) {
            $this->tokens->revokeAll('staff', (int) $account['id']);
        }

        return Response::data(StaffUser::presentForAdmin((array) $this->staff->find((int) $account['id'])));
    }

    /** POST /v1/admin/staff/{id}/reset-password: returns the new password once and ends the account's sessions. */
    public function resetPassword(Request $request): Response
    {
        $this->authorize($request);
        $account = $this->account($request);
        if ((int) $account['id'] === $this->staff($request)['id']) {
            throw new HttpException(409, 'own_account', 'Change your own password under "My account".');
        }

        $password = StaffUser::generatePassword();
        $this->staff->updatePassword((int) $account['id'], $password);
        $this->tokens->revokeAll('staff', (int) $account['id']);

        return Response::data(['password' => $password]);
    }

    /** @return array<string, mixed> */
    private function account(Request $request): array
    {
        $account = $this->staff->find($this->id($request));
        if ($account === null) {
            throw HttpException::notFound('This account does not exist.');
        }

        return $account;
    }
}
