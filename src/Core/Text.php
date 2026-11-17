<?php

declare(strict_types=1);

namespace App\Core;

/** Small text helpers shared by the admin and import scripts. */
final class Text
{
    /** "Jeans – Men's Guide 2026" → "jeans-men-s-guide-2026" */
    public static function slug(string $text, int $maxLength = 190): string
    {
        if (function_exists('transliterator_transliterate')) {
            $text = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');

        return rtrim(substr($slug, 0, $maxLength), '-');
    }

    /** The first paragraph of some HTML as plain text, cut at a word boundary. */
    public static function excerpt(string $html, int $maxLength = 220): string
    {
        preg_match('/<p>(.*?)<\/p>/s', $html, $m);
        $text = html_entity_decode(strip_tags($m[1] ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        $cut = mb_substr($text, 0, $maxLength);

        return rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')), ' ,.;:') . '…';
    }
}
