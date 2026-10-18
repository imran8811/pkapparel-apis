<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Logger;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Review;
use App\Models\Testimonial;
use PDOException;
use Throwable;

/**
 * Reviews of delivered orders (owner decision 2026-10-17). The daily job asks the buyer 7 days after delivery
 * (one email, with a private link); the buyer can also write one from the order page. Staff approve a review
 * before it appears on the product pages of the styles in that order, and can add it to the home page reviews.
 */
final class Reviews
{
    /** Days after delivery when the buyer is asked. */
    public const REQUEST_AFTER_DAYS = 7;

    /** Orders delivered longer ago are not asked (e.g. old orders when this started). */
    private const REQUEST_WITHIN_DAYS = 60;

    /** Reviews shown on a product page. */
    private const PER_PRODUCT = 20;

    public function __construct(
        private readonly Review $reviews,
        private readonly Order $orders,
        private readonly Testimonial $testimonials,
        private readonly OrderService $service,
        private readonly OrderMailer $mailer,
        private readonly WebRevalidator $revalidator,
        private readonly Logger $logger,
    ) {
    }

    /** Daily job: emails the review request for orders delivered at least 7 days ago. Returns how many were sent. */
    public function requestDue(): int
    {
        $sent = 0;
        $before = gmdate('Y-m-d H:i:s', time() - self::REQUEST_AFTER_DAYS * 86400);
        $after = gmdate('Y-m-d H:i:s', time() - self::REQUEST_WITHIN_DAYS * 86400);
        foreach ($this->reviews->dueForRequest($after, $before) as $due) {
            $token = bin2hex(random_bytes(32));
            try {
                // One request per order (the order is unique in reviews), even if the job runs twice.
                $this->reviews->request($due['id'], hash('sha256', $token));
            } catch (PDOException) {
                continue;
            }
            $order = $this->orders->find($due['id']);
            if ($order === null) {
                continue;
            }
            try {
                $this->mailer->reviewRequest($this->service->present($order, false), $token);
                $sent++;
            } catch (Throwable $e) {
                $this->logger->error('Review request email could not be sent', ['order' => $due['order_no'], 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * The review page for an emailed link.
     *
     * @return array<string, mixed>
     */
    public function forToken(string $token): array
    {
        $review = $this->reviews->findByToken($token) ?? throw HttpException::notFound('This review link does not work anymore. You can write a review from your order page.');

        return $this->context($review['order_id'], $review);
    }

    /** @param array{rating: int, body: string, show_as: string} $data */
    public function submitByToken(string $token, array $data): void
    {
        $review = $this->reviews->findByToken($token) ?? throw HttpException::notFound('This review link does not work anymore. You can write a review from your order page.');
        $this->save($review['order_id'], $review, $data);
    }

    /**
     * The review form on the buyer's order page (delivered orders).
     *
     * @param array<string, mixed> $order database row
     * @return array<string, mixed>
     */
    public function forOrder(array $order): array
    {
        self::expectReviewable($order);

        return $this->context($order['id'], $this->reviews->findForOrder($order['id']));
    }

    /**
     * @param array<string, mixed> $order database row
     * @param array{rating: int, body: string, show_as: string} $data
     */
    public function submitForOrder(array $order, array $data): void
    {
        self::expectReviewable($order);
        $this->save($order['id'], $this->reviews->findForOrder($order['id']), $data);
    }

    /** @param array{id: int} $staff */
    public function approve(int $id, array $staff): void
    {
        $review = $this->submitted($id);
        $this->reviews->setStatus($id, 'approved', $staff['id']);
        $this->refreshPages($review);
    }

    /**
     * Hides the review from the product pages, and deletes its copy on the home page if it was added there
     * (e.g. when the buyer asks for it to be removed). Approved again, it can be added to the home page again.
     *
     * @param array{id: int} $staff
     */
    public function hide(int $id, array $staff): void
    {
        $review = $this->submitted($id);
        $this->reviews->setStatus($id, 'hidden', $staff['id']);
        $this->refreshPages($review);
        if ($review['testimonial_id'] !== null) {
            $this->testimonials->delete($review['testimonial_id']);
            $this->revalidator->revalidate(['testimonials']);
        }
    }

    /**
     * Adds an approved review to the home page reviews (a testimonial staff can edit or sort like the others).
     *
     * @return int the testimonial's id
     */
    public function feature(int $id): int
    {
        $review = $this->submitted($id);
        if ($review['status'] !== 'approved') {
            throw new HttpException(409, 'not_approved', 'Approve the review first.');
        }
        if ($review['testimonial_id'] !== null) {
            throw new HttpException(409, 'already_featured', 'This review is already on the home page.');
        }
        $order = $this->orders->find($review['order_id']) ?? throw HttpException::notFound('The order of this review is gone.');
        $testimonialId = $this->testimonials->create([
            'author_name' => null,
            'author_role' => Customer::BUSINESS_TYPES[$order['customer']['business_type'] ?? ''] ?? 'Buyer',
            'company' => $review['show_as'] === 'company' ? ($order['customer']['company_name'] ?? null) : null,
            'country_code' => $order['address']['country_code'] ?? ($order['customer']['country_code'] ?? null),
            'quote' => (string) $review['body'],
            'rating' => $review['rating'],
            'is_published' => 1,
        ]);
        $this->reviews->setTestimonial($id, $testimonialId);
        $this->revalidator->revalidate(['testimonials']);

        return $testimonialId;
    }

    /**
     * Approved reviews for a product page.
     *
     * @return array{count: int, average: ?float, items: list<array<string, mixed>>}
     */
    public static function present(array $stats, array $items): array
    {
        return $stats + ['items' => array_map(static fn (array $review): array => [
            'rating' => $review['rating'],
            'body' => (string) $review['body'],
            'name' => Review::displayName($review),
            'country_code' => $review['country_code'],
            'date' => $review['submitted_at'],
        ], $items)];
    }

    /**
     * @param array<string, mixed>|null $review
     * @param array{rating: int, body: string, show_as: string} $data
     */
    private function save(int $orderId, ?array $review, array $data): void
    {
        if ($review !== null && $review['status'] !== 'requested') {
            throw new HttpException(409, 'already_reviewed', 'Thank you, we already have your review of this order.');
        }
        $this->reviews->submit($orderId, $data['rating'], $data['body'], $data['show_as']);

        $order = $this->orders->find($orderId);
        if ($order !== null) {
            try {
                $this->mailer->reviewSubmitted($this->service->present($order, true), $data['rating'], $data['body']);
            } catch (Throwable $e) {
                $this->logger->error('Review notification could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * What the review form shows: the order, its styles and the review so far.
     *
     * @param array<string, mixed>|null $review
     * @return array<string, mixed>
     */
    private function context(int $orderId, ?array $review): array
    {
        $order = $this->orders->find($orderId) ?? throw HttpException::notFound('This order does not exist.');

        return [
            'order_no' => $order['order_no'],
            'company_name' => $order['customer']['company_name'] ?? '',
            'business_type_label' => Customer::BUSINESS_TYPES[$order['customer']['business_type'] ?? ''] ?? 'Buyer',
            'country_code' => $order['address']['country_code'] ?? null,
            'total_pcs' => $order['total_pcs'],
            'delivered_at' => $order['delivered_at'],
            'styles' => array_column($this->reviews->styles($orderId), 'name'),
            'submitted' => $review !== null && $review['status'] !== 'requested',
            'review' => $review === null || $review['status'] === 'requested' ? null : [
                'rating' => $review['rating'],
                'body' => $review['body'],
                'show_as' => $review['show_as'],
            ],
        ];
    }

    /** @return array<string, mixed> a review the buyer has written */
    private function submitted(int $id): array
    {
        $review = $this->reviews->find($id);
        if ($review === null || $review['status'] === 'requested') {
            throw HttpException::notFound('This review does not exist.');
        }

        return $review;
    }

    /** Product pages of the reviewed styles show the change at once. */
    private function refreshPages(array $review): void
    {
        $tags = array_map(static fn (array $style): string => 'product:' . $style['slug'], array_filter($this->reviews->styles($review['order_id']), static fn (array $style): bool => $style['slug'] !== ''));
        if ($tags !== []) {
            $this->revalidator->revalidate(array_values(array_unique($tags)));
        }
    }

    /** @param array<string, mixed> $order */
    private static function expectReviewable(array $order): void
    {
        if ($order['kind'] !== 'bulk' || $order['status'] !== 'delivered') {
            throw new HttpException(409, 'not_reviewable', 'You can review an order once it is delivered.');
        }
    }
}
