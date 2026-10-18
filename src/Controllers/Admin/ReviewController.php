<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Review;
use App\Services\Reviews;

/**
 * Reviews buyers wrote about delivered orders (admins and editors, like the home page reviews):
 * approve them for the product pages, hide them, or add one to the home page reviews.
 */
final class ReviewController extends AdminController
{
    public function __construct(private readonly Review $reviews, private readonly Reviews $service)
    {
    }

    /** GET /v1/admin/reviews?status=pending|approved|hidden&page= */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $status = Validator::make($request->allQuery(), ['status' => 'nullable|in:pending,approved,hidden'])->validate()['status'] ?? 'pending';
        [$page, $perPage] = $this->pagination($request, 25);
        $result = $this->reviews->adminList($status, $page, $perPage);

        return Response::data(
            array_map(static fn (array $review): array => [
                'id' => $review['id'],
                'order_no' => $review['order_no'],
                'company_name' => $review['customer']['company_name'] ?? '',
                'display_name' => Review::displayName($review),
                'country_code' => $review['country_code'],
                'total_pcs' => $review['total_pcs'],
                'styles' => array_column($review['styles'], 'name'),
                'rating' => $review['rating'],
                'body' => $review['body'],
                'show_as' => $review['show_as'],
                'status' => $review['status'],
                'submitted_at' => $review['submitted_at'],
                'featured' => $review['testimonial_id'] !== null,
            ], $result['items']),
            self::paginationMeta($page, $perPage, $result['total']) + ['counts' => $this->reviews->counts()],
        );
    }

    /** POST /v1/admin/reviews/{id}/approve */
    public function approve(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $this->service->approve($this->id($request), $this->staff($request));

        return Response::noContent();
    }

    /** POST /v1/admin/reviews/{id}/hide */
    public function hide(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $this->service->hide($this->id($request), $this->staff($request));

        return Response::noContent();
    }

    /** POST /v1/admin/reviews/{id}/feature: add it to the home page reviews. */
    public function feature(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data(['testimonial_id' => $this->service->feature($this->id($request))], [], 201);
    }
}
