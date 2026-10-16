<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\SizeChart;
use App\Services\WebRevalidator;

/** Size charts shown on product pages (admin and editor). */
final class SizeChartController extends AdminController
{
    private const MAX_MEASUREMENTS = 10;

    private const MAX_SIZES = 30;

    public function __construct(private readonly SizeChart $charts, private readonly WebRevalidator $revalidator)
    {
    }

    /** GET /v1/admin/size-charts */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->charts->all());
    }

    /** POST /v1/admin/size-charts */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->charts->create($this->validated($request));

        return Response::data($this->charts->find($id), [], 201);
    }

    /** PUT /v1/admin/size-charts/{id} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->findOrFail($request);
        $this->charts->update($id, $this->validated($request));
        $this->revalidator->revalidate(['products']);

        return Response::data($this->charts->find($id));
    }

    /** DELETE /v1/admin/size-charts/{id}: styles that used it no longer show a chart. */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $this->charts->delete($this->findOrFail($request));
        $this->revalidator->revalidate(['products']);

        return Response::noContent();
    }

    private function findOrFail(Request $request): int
    {
        $id = $this->id($request);
        if ($this->charts->find($id) === null) {
            throw HttpException::notFound('This size chart does not exist.');
        }

        return $id;
    }

    /** @return array{name: string, unit: string, measurements: list<string>, size_rows: list<array{size: string, values: list<string>}>, note: ?string} */
    private function validated(Request $request): array
    {
        $input = $request->all();
        $data = Validator::make($input, [
            'name' => 'required|string|max:120',
            'unit' => 'required|in:' . implode(',', SizeChart::UNITS),
            'note' => 'nullable|string|max:500',
        ])->validate();

        $measurements = $input['measurements'] ?? null;
        if (!is_array($measurements) || !array_is_list($measurements) || $measurements === [] || count($measurements) > self::MAX_MEASUREMENTS) {
            throw new ValidationException(['measurements' => 'Add between 1 and ' . self::MAX_MEASUREMENTS . ' measurements.']);
        }
        $names = [];
        foreach ($measurements as $index => $name) {
            $name = is_string($name) ? trim($name) : '';
            if ($name === '' || mb_strlen($name) > 40) {
                throw new ValidationException(["measurements.{$index}" => 'Give each measurement a name (at most 40 characters).']);
            }
            $names[] = $name;
        }

        $rows = $input['size_rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > self::MAX_SIZES) {
            throw new ValidationException(['size_rows' => 'Add between 1 and ' . self::MAX_SIZES . ' sizes.']);
        }
        $sizes = [];
        foreach ($rows as $index => $row) {
            $size = is_array($row) && is_scalar($row['size'] ?? null) ? trim((string) $row['size']) : '';
            $values = is_array($row) && is_array($row['values'] ?? null) ? array_values($row['values']) : null;
            if ($size === '' || mb_strlen($size) > 12) {
                throw new ValidationException(["size_rows.{$index}.size" => 'Give each row a size (at most 12 characters).']);
            }
            if ($values === null || count($values) !== count($names)) {
                throw new ValidationException(["size_rows.{$index}.values" => 'Fill in one value per measurement.']);
            }
            $clean = [];
            foreach ($values as $value) {
                $value = is_scalar($value) ? trim((string) $value) : '';
                if (mb_strlen($value) > 12) {
                    throw new ValidationException(["size_rows.{$index}.values" => 'Values can be at most 12 characters.']);
                }
                $clean[] = $value;
            }
            $sizes[] = ['size' => $size, 'values' => $clean];
        }

        return [
            'name' => $data['name'],
            'unit' => $data['unit'],
            'measurements' => $names,
            'size_rows' => $sizes,
            'note' => $data['note'] ?? null,
        ];
    }
}
