<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Display formatting for emails and PDFs: money, dates in Pakistan time, country names. */
final class Format
{
    /** PK Apparel works in Lahore: order numbers and document dates use Pakistan time. */
    public const BUSINESS_TIMEZONE = 'Asia/Karachi';

    /** 123450 → "$1,234.50" */
    public static function money(int $cents, string $currency = 'USD'): string
    {
        $sign = $cents < 0 ? '-' : '';
        $amount = number_format(abs($cents) / 100, 2, '.', ',');

        return $currency === 'USD' ? "{$sign}\${$amount}" : "{$sign}{$amount} {$currency}";
    }

    /** An ISO time or "Y-m-d" date → "16 Oct 2026" (Pakistan date). */
    public static function date(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::local($value)->format('j M Y');
    }

    /** Today's date (or the given time's date) in Pakistan, e.g. for order numbers. */
    public static function local(?string $value = null): DateTimeImmutable
    {
        $zone = new DateTimeZone(self::BUSINESS_TIMEZONE);
        if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return new DateTimeImmutable($value, $zone);
        }

        return (new DateTimeImmutable($value ?? 'now', new DateTimeZone('UTC')))->setTimezone($zone);
    }

    /** "US" → "United States" (falls back to the code when the intl extension is missing). */
    public static function country(?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . strtoupper($code), 'en');
            if ($name !== '' && strcasecmp($name, $code) !== 0) {
                return $name;
            }
        }

        return strtoupper($code);
    }

    /** {"30": 20, "32": 20} → "30×20, 32×20" */
    public static function sizes(array $sizeQty): string
    {
        $parts = [];
        foreach ($sizeQty as $size => $qty) {
            if ((int) $qty > 0) {
                $parts[] = $size . '×' . (int) $qty;
            }
        }

        return implode(', ', $parts);
    }
}
