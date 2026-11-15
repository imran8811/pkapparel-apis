<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Core\Validator;

/** Shared helpers for admin controllers (routes behind RequireStaff). */
abstract class AdminController
{
    /**
     * Checks an object inside the request, e.g. "color": {…}, with its problems on "color.code" etc.
     *
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    protected static function nested(mixed $input, array $rules, string $field): array
    {
        if (!is_array($input)) {
            throw new ValidationException([$field => 'Must be a set of fields.']);
        }
        $validator = Validator::make($input, $rules);
        if (!$validator->passes()) {
            $fields = [];
            foreach ($validator->errors() as $name => $message) {
                $fields["{$field}.{$name}"] = $message;
            }
            throw new ValidationException($fields);
        }

        return $validator->validate();
    }

    /** @return array{id: int, name: string, email: string, role: string} */
    protected function staff(Request $request): array
    {
        $staff = $request->attribute('staff');
        if (!is_array($staff)) {
            throw HttpException::unauthorized();
        }

        return $staff;
    }

    /** Allow admins plus the listed roles; everyone else gets 403. */
    protected function authorize(Request $request, string ...$roles): void
    {
        $role = $this->staff($request)['role'];
        if ($role !== 'admin' && !in_array($role, $roles, true)) {
            throw HttpException::forbidden('Your role does not allow this action.');
        }
    }

    protected function id(Request $request): int
    {
        $id = (int) $request->param('id');
        if ($id <= 0) {
            throw HttpException::notFound();
        }

        return $id;
    }

    /** @return array{0: int, 1: int} [page, per page] from ?page=&per_page= */
    protected function pagination(Request $request, int $defaultPerPage = 20): array
    {
        $query = Validator::make($request->allQuery(), [
            'page' => 'nullable|int|min:1|max:10000',
            'per_page' => 'nullable|int|min:1|max:100',
        ])->validate();

        return [$query['page'] ?? 1, $query['per_page'] ?? $defaultPerPage];
    }

    /** @return array{pagination: array{page: int, per_page: int, total: int, pages: int}} */
    protected static function paginationMeta(int $page, int $perPage, int $total): array
    {
        return ['pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage)]];
    }
}
