<?php

declare(strict_types=1);

namespace App\Core;

/** Small text helpers shared by the admin and import scripts. */
final class Text
{
    /**
     * The web address of something renamed, when its address was made from its old name (owner request 2026-11-17):
     * the new name's, with what followed the old name kept ($suffix, e.g. "-sky-blue" for a stock listing's color). A
     * number that told it from another one ("-2") is dropped; give the result to freeSlug(). Null when the address was
     * not made from the old name (it was typed), or the new name gives the same address.
     */
    public static function renamedSlug(string $slug, string $oldName, string $newName, string $suffix = ''): ?string
    {
        foreach (array_unique(['', $suffix]) as $extra) {
            $old = self::slug($oldName, 190 - strlen($extra));
            $new = self::slug($newName, 190 - strlen($extra));
            if ($old !== '' && $new !== '' && $old !== $new && preg_match('/^' . preg_quote($old . $extra, '/') . '(?:-\d+)?$/', $slug) === 1) {
                return $new . $extra;
            }
        }

        return null;
    }

    /**
     * "mens-slim-jeans", or "mens-slim-jeans-2", "-3"… when $taken says another one has it.
     *
     * @param callable(string): bool $taken
     */
    public static function freeSlug(string $base, callable $taken): string
    {
        $slug = $base;
        for ($n = 2; $taken($slug); $n++) {
            $slug = rtrim(substr($base, 0, 189 - strlen((string) $n)), '-') . "-{$n}";
        }

        return $slug;
    }

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
