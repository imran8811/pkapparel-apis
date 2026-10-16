<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * Tells the Next.js website that cached content changed, e.g. after an admin
 * edits a product: revalidate(['products', 'product:pk-1058']).
 * A failure is logged but never breaks the admin action that triggered it.
 */
final class WebRevalidator
{
    public function __construct(private readonly Config $config, private readonly Logger $logger)
    {
    }

    /** @param list<string> $tags */
    public function revalidate(array $tags): bool
    {
        $url = rtrim($this->config->string('web.url'), '/') . '/api/revalidate';
        $secret = $this->config->string('web.revalidate_secret');
        if ($tags === [] || $secret === '' || !$this->config->bool('web.revalidate', true)) {
            return false;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['tags' => array_values($tags)], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Revalidate-Secret: ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);

        if ($status !== 200) {
            $this->logger->warning('Website revalidation failed', [
                'tags' => $tags,
                'status' => $status,
                'error' => $error,
                'body' => is_string($body) ? mb_substr($body, 0, 300) : null,
            ]);

            return false;
        }

        return true;
    }
}
