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

    /** Currencies written with a symbol before the amount. */
    private const SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];

    /**
     * 123450 → "$1,234.50" (also "€", "£"); in rupees (stock orders) 690000 → "Rs 6,900" (paisa only when there are any).
     * Other currencies: "1,234.50 AED".
     */
    public static function money(int $cents, string $currency = 'USD'): string
    {
        $sign = $cents < 0 ? '-' : '';
        if ($currency === 'PKR') {
            return $sign . 'Rs ' . number_format(abs($cents) / 100, abs($cents) % 100 === 0 ? 0 : 2, '.', ',');
        }
        $amount = number_format(abs($cents) / 100, 2, '.', ',');

        return isset(self::SYMBOLS[$currency]) ? $sign . self::SYMBOLS[$currency] . $amount : "{$sign}{$amount} {$currency}";
    }

    /**
     * An amount in words for invoices: 38550 USD → "US dollars three hundred eighty-five and fifty cents only".
     * Written out in code, so it works without the intl extension.
     */
    public static function moneyInWords(int $cents, string $currency = 'USD'): string
    {
        [$major, $minor] = match ($currency) {
            'USD' => ['US dollars', 'cents'],
            'EUR' => ['euros', 'cents'],
            'GBP' => ['pounds sterling', 'pence'],
            'PKR' => ['Pakistani rupees', 'paisa'],
            default => [$currency, 'cents'],
        };
        $cents = abs($cents);
        $words = $major . ' ' . self::numberInWords(intdiv($cents, 100));
        if ($cents % 100 > 0) {
            $words .= ' and ' . self::numberInWords($cents % 100) . ' ' . $minor;
        }

        return ucfirst($words . ' only');
    }

    /** 1385 → "one thousand three hundred eighty-five" (up to the billions). */
    public static function numberInWords(int $number): string
    {
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
            'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];
        $belowThousand = static function (int $n) use ($ones, $tens): string {
            $parts = [];
            if ($n >= 100) {
                $parts[] = $ones[intdiv($n, 100)] . ' hundred';
                $n %= 100;
            }
            if ($n >= 20) {
                $parts[] = $tens[intdiv($n, 10)] . ($n % 10 > 0 ? '-' . $ones[$n % 10] : '');
            } elseif ($n > 0) {
                $parts[] = $ones[$n];
            }

            return implode(' ', $parts);
        };

        $number = abs($number);
        if ($number === 0) {
            return 'zero';
        }
        $parts = [];
        foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'] as $size => $name) {
            if ($number >= $size) {
                $parts[] = $belowThousand(intdiv($number, $size)) . ' ' . $name;
                $number %= $size;
            }
        }
        if ($number > 0) {
            $parts[] = $belowThousand($number);
        }

        return implode(' ', $parts);
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
