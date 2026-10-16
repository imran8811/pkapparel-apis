<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Certification;
use App\Models\FactoryDepartment;
use App\Models\Faq;
use App\Models\Settings;
use App\Models\Testimonial;

/** Read-only marketing content for the public website. */
final class ContentController
{
    public function __construct(
        private readonly Faq $faqs,
        private readonly Testimonial $testimonials,
        private readonly Certification $certifications,
        private readonly FactoryDepartment $departments,
        private readonly Settings $settings,
    ) {
    }

    /** GET /v1/faqs?category=shipping */
    public function faqs(Request $request): Response
    {
        $filters = Validator::make($request->allQuery(), ['category' => 'nullable|string|max:40'])->validate();

        return Response::data($this->faqs->published($filters['category'] ?? null));
    }

    /** GET /v1/testimonials */
    public function testimonials(Request $request): Response
    {
        return Response::data($this->testimonials->published());
    }

    /** GET /v1/certifications */
    public function certifications(Request $request): Response
    {
        return Response::data($this->certifications->published());
    }

    /** GET /v1/factory: departments with photos, plus headline factory facts. */
    public function factory(Request $request): Response
    {
        return Response::data([
            'facts' => $this->settings->get('factory_facts', []),
            'departments' => $this->departments->published(),
        ]);
    }
}
