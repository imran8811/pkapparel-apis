<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Stock departments: Men, Women, Boys… A department's listings are at /stock/{slug} (its slug is kept in
 * stock_styles.segment); the men's are at /stock itself.
 */
final class StockDepartment extends StockSection
{
    /** The department listed at /stock itself (owner decision 2026-11-09: the stock pages open on the men's jeans). */
    public const HOME = 'men';

    /** The stock pages' own addresses: a department at /stock/{slug} would never be reached. */
    public const RESERVED = [
        'account', 'cart', 'checkout', 'contact', 'forgot-password', 'how-to-order', 'login', 'privacy', 'reset-password',
        'signup', 'terms', 'verify-email',
    ];

    public const SLUG_MAX = 20;

    protected const TABLE = 'stock_departments';

    protected const FILLABLE = ['slug', 'name', 'sort_order'];

    protected const STYLE_COLUMN = 'segment';
}
