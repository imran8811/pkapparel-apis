<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\ValidationException;
use App\Models\Certification;
use App\Services\ImageStore;
use App\Services\WebRevalidator;

/** /v1/admin/certifications */
final class CertificationController extends ListContentController
{
    public function __construct(Certification $certifications, WebRevalidator $revalidator, private readonly ImageStore $images)
    {
        parent::__construct($certifications, $revalidator);
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:160',
            'issuer' => 'nullable|string|max:160',
            'certificate_no' => 'nullable|string|max:80',
            'member_since' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'description' => 'nullable|string|max:500',
            'image_path' => 'nullable|string|max:255',
        ];
    }

    protected function prepare(array $data): array
    {
        if (isset($data['image_path']) && !$this->images->exists($data['image_path'])) {
            throw new ValidationException(['image_path' => 'Upload the certificate image again.']);
        }
        if (isset($data['member_since'], $data['valid_until']) && $data['valid_until'] < $data['member_since']) {
            throw new ValidationException(['valid_until' => 'Must be after the "member since" date.']);
        }

        return $data;
    }

    protected function cacheTag(): string
    {
        return 'certifications';
    }

    protected function label(): string
    {
        return 'certificate';
    }
}
