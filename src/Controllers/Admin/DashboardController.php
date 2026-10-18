<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Format;
use App\Core\Request;
use App\Core\Response;
use App\Models\Certification;
use App\Models\Customer;
use App\Models\Faq;
use App\Models\Inquiry;
use App\Models\MissingPage;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Post;
use App\Models\Review;
use App\Models\Testimonial;

/** GET /v1/admin/dashboard: what needs attention, tailored to the staff member's role. */
final class DashboardController extends AdminController
{
    public function __construct(
        private readonly Inquiry $inquiries,
        private readonly Post $posts,
        private readonly Faq $faqs,
        private readonly Testimonial $testimonials,
        private readonly Certification $certifications,
        private readonly Order $orders,
        private readonly OrderPayment $payments,
        private readonly Customer $customers,
        private readonly Review $reviews,
        private readonly MissingPage $missingPages,
    ) {
    }

    public function show(Request $request): Response
    {
        $role = $this->staff($request)['role'];
        $data = [];

        if (in_array($role, ['admin', 'sales', 'production'], true)) {
            $counts = $this->orders->countByStatus();
            $data['orders'] = [
                'samples_to_price' => $counts['awaiting_price'] ?? 0,
                'awaiting_deposit' => $counts['awaiting_deposit'] ?? 0,
                'payments_to_check' => $this->payments->countByStatus('submitted'),
                'open' => array_sum(array_intersect_key($counts, array_flip(Order::OPEN_STATUSES))),
                'late' => $this->orders->countLate(Format::local()->format('Y-m-d')),
            ];
        }

        if (in_array($role, ['admin', 'sales'], true)) {
            $data['customers'] = ['new_30_days' => $this->customers->countSince(30)];
            $counts = $this->inquiries->countByStatus();
            $data['inquiries'] = [
                'new' => $counts['new'],
                'open' => array_sum(array_intersect_key($counts, array_flip(Inquiry::OPEN))),
                'last_30_days' => $this->inquiries->countSince(30),
                'latest' => $this->inquiries->latest(6),
            ];
        }

        if (in_array($role, ['admin', 'editor'], true)) {
            $data['content'] = [
                'reviews_to_approve' => $this->reviews->counts()['pending'],
                // Addresses visitors hit that do not exist (Admin → Redirects).
                'missing_pages' => $this->missingPages->counts()['open'],
                'posts' => $this->posts->counts(),
                'faqs' => $this->faqs->count(),
                'testimonials' => $this->testimonials->count(),
                'certifications' => $this->certifications->count(),
            ];
        }

        return Response::data($data);
    }
}
