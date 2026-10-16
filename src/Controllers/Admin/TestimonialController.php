<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Testimonial;
use App\Services\WebRevalidator;

/** /v1/admin/testimonials (buyer reviews) */
final class TestimonialController extends ListContentController
{
    public function __construct(Testimonial $testimonials, WebRevalidator $revalidator)
    {
        parent::__construct($testimonials, $revalidator);
    }

    protected function rules(): array
    {
        return [
            'author_name' => 'nullable|string|max:120',
            'author_role' => 'required|string|max:120',
            'company' => 'nullable|string|max:160',
            'country_code' => 'nullable|country',
            'quote' => 'required|string|min:10|max:2000',
            'rating' => 'nullable|int|min:1|max:5',
        ];
    }

    protected function cacheTag(): string
    {
        return 'testimonials';
    }

    protected function label(): string
    {
        return 'review';
    }
}
