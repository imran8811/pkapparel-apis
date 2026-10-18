<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\ValidationException;
use App\Models\MissingPage;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;

/**
 * Redirects managed in the admin (owner decision 2026-10-17), the automatic ones made when a style or post changes
 * its address, and the missing addresses that visitors' browsers report from the "page not found" page.
 * Redirects are stored as entered and may lead to an address that redirects again; the website follows the chain
 * and sends the visitor straight to the end of it. Circles are refused.
 */
final class Redirects
{
    /** Areas of the website that a redirect may not take over, and that are not reported as missing. */
    private const RESERVED = ['/admin', '/api', '/account', '/_next', '/uploads', '/checkout', '/cart', '/login', '/signup'];

    /** Addresses that bots probe on every website: not worth showing to staff. */
    private const JUNK = '~(\.(php\d?|aspx?|jsp|cgi|env|git|sql|bak|old|zip|gz|tar|ini|log|xml|txt|json|ya?ml|map|ico|png|jpe?g|gif|svg|css|js)$)|/wp-|/\.|cgi-bin|phpmyadmin|xmlrpc|/vendor/|/node_modules/~i';

    public function __construct(
        private readonly Redirect $redirects,
        private readonly MissingPage $missing,
        private readonly Product $products,
        private readonly Post $posts,
        private readonly WebRevalidator $revalidator,
        private readonly Config $config,
    ) {
    }

    /**
     * An address as staff type it or a browser reports it, reduced to a decoded path without a trailing slash
     * ("https://www.pkapparel.com/Old%20Page/?a=1" → "/Old Page"), or null when it is not a usable path.
     */
    public static function path(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('~^https?://~i', $value)) {
            $value = (string) (parse_url($value, PHP_URL_PATH) ?? '/');
        }
        $value = explode('?', explode('#', $value, 2)[0], 2)[0];
        $value = rawurldecode($value);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $value)) {
            return null;
        }
        $value = '/' . ltrim((string) preg_replace('~/{2,}~', '/', $value), '/');
        $value = rtrim($value, '/') ?: '/';

        return mb_strlen($value) > 255 ? null : $value;
    }

    /**
     * Adds or changes a redirect from the admin.
     *
     * @param array{id: int} $staff
     * @return array<string, mixed> the saved redirect
     */
    public function save(?int $id, string $fromInput, string $toInput, array $staff): array
    {
        $from = self::path($fromInput);
        if ($from === null || $from === '/') {
            throw new ValidationException(['from_path' => $from === '/' ? 'The home page cannot redirect.' : 'Enter the old address, e.g. /old-page.']);
        }
        if (self::reserved($from)) {
            throw new ValidationException(['from_path' => 'Addresses under /admin, /account, /checkout and the other areas of the website cannot redirect.']);
        }
        if ($this->isLivePage($from)) {
            throw new ValidationException(['from_path' => 'This address is a live page, so a redirect would never be used. Hide or move that page first.']);
        }
        $existing = $this->redirects->findFrom($from);
        if ($existing !== null && $existing['id'] !== $id) {
            throw new ValidationException(['from_path' => "This address already redirects to {$existing['to_path']}. Change that redirect instead."]);
        }

        $to = $this->target($toInput);
        if ($to === null) {
            throw new ValidationException(['to_path' => 'Enter the new address: a page of this website such as /wholesale-shop/men, or a full https:// address.']);
        }
        if (str_starts_with($to, '/') && mb_strtolower((string) self::path($to)) === mb_strtolower($from)) {
            throw new ValidationException(['to_path' => 'The new address must be different from the old one.']);
        }
        $circle = $this->circleThrough($to, $from, $id);
        if ($circle !== null) {
            throw new ValidationException(['to_path' => "This would send visitors in a circle: {$circle} already leads back to {$from}."]);
        }

        if ($id === null) {
            $id = $this->redirects->create($from, $to, 'staff', $staff['id']);
        } else {
            $this->redirects->update($id, $from, $to);
        }
        $this->missing->resolve($from);
        $this->revalidator->revalidate(['redirects']);

        return $this->redirects->find($id) ?? [];
    }

    public function delete(int $id): void
    {
        $this->redirects->delete($id);
        $this->revalidator->revalidate(['redirects']);
    }

    /**
     * A style or post moved from one address to another: the old address redirects to the new one.
     *
     * @param 'style'|'post' $source
     */
    public function moved(string $old, string $new, string $source): void
    {
        if (mb_strtolower($old) === mb_strtolower($new)) {
            return;
        }
        // The new address is a live page again (e.g. a style moved back): drop any redirect from it.
        $this->redirects->deleteFrom($new);
        $existing = $this->redirects->findFrom($old);
        if ($existing === null) {
            $this->redirects->create($old, $new, $source, null);
        } else {
            $this->redirects->update($existing['id'], $old, $new);
        }
        $this->missing->resolve($old);
        $this->revalidator->revalidate(['redirects']);
    }

    /** Counts a visit to a missing address reported by the website, unless it is a bot probe or already redirected. */
    public function recordMissing(string $pathInput, ?string $referrer): void
    {
        $path = self::path($pathInput);
        if ($path === null || $path === '/' || self::reserved($path) || preg_match(self::JUNK, $path) || $this->redirects->findFrom($path) !== null) {
            return;
        }
        $referrer = $referrer !== null && preg_match('~^https?://~i', $referrer) ? mb_substr($referrer, 0, 255) : null;
        $this->missing->record($path, $referrer);
    }

    /** A target typed by staff: a path of this website (with its query), or a full https:// address elsewhere. */
    private function target(string $value): ?string
    {
        $value = trim($value);
        $site = (string) parse_url($this->config->string('web.url'), PHP_URL_HOST);
        $host = preg_match('~^https?://~i', $value) ? (string) parse_url($value, PHP_URL_HOST) : '';
        if ($host !== '' && preg_replace('/^www\./', '', mb_strtolower($host)) === preg_replace('/^www\./', '', mb_strtolower($site))) {
            // An address on this website: keep the path and query only.
            $value = (string) preg_replace('~^https?://[^/?#]+~i', '', $value) ?: '/';
        }
        if (str_starts_with($value, '/')) {
            $value = explode('#', $value, 2)[0];
            [$path, $query] = array_pad(explode('?', $value, 2), 2, null);
            // Old addresses may contain spaces ("/men-sky blue-…"); a redirect sends them encoded.
            $path = str_replace(' ', '%20', rtrim($path, '/') ?: '/');

            return preg_match('~^/[^\s\\\\]*$~', $path) && mb_strlen($value) <= 500 && !str_starts_with($path, '//')
                ? $path . ($query !== null && $query !== '' ? '?' . $query : '')
                : null;
        }

        return preg_match('~^https://[^\s/?#]+\.[^\s/?#]+(/\S*)?$~i', $value) && mb_strlen($value) <= 500 ? $value : null;
    }

    /**
     * Follows the redirects from $to; when they lead back to $from, returns the address that closes the circle.
     * The redirect being edited ($exceptId) is left out: it is about to change.
     */
    private function circleThrough(string $to, string $from, ?int $exceptId): ?string
    {
        $path = str_starts_with($to, '/') ? self::path($to) : null;
        for ($hops = 0; $path !== null && $hops < 20; $hops++) {
            $next = $this->redirects->findFrom($path);
            if ($next === null || $next['id'] === $exceptId || !str_starts_with($next['to_path'], '/')) {
                return null;
            }
            if (mb_strtolower((string) self::path($next['to_path'])) === mb_strtolower($from)) {
                return $next['from_path'];
            }
            $path = self::path($next['to_path']);
        }

        return null;
    }

    private static function reserved(string $path): bool
    {
        $lower = mb_strtolower($path);
        foreach (self::RESERVED as $area) {
            if ($lower === $area || str_starts_with($lower, $area . '/')) {
                return true;
            }
        }

        return false;
    }

    /** Whether a style or published post lives at this address (the website shows it instead of redirecting). */
    private function isLivePage(string $path): bool
    {
        $lower = mb_strtolower($path);
        if (preg_match('~^/wholesale-shop/([^/]+)/([^/]+)/([^/]+)$~', $lower, $m)) {
            $product = $this->products->findActiveBySlug($m[3]);

            return $product !== null && $product['segment'] === $m[1] && $product['product_type'] === $m[2];
        }
        if (preg_match('~^/post/([^/]+)$~', $lower, $m)) {
            return $this->posts->findPublished($m[1]) !== null;
        }

        return false;
    }
}
