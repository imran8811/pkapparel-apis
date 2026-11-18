-- Shipping per piece on each quotation style (owner request 2026-11-18): in rupees like the other costs, added to the
-- price after the profit (no profit is charged on it): price = cost + profit + shipping.
-- It replaces a first version that had one shipping charge for the whole quotation (quotations.shipping_cents): that
-- column is removed if it was imported.
-- Safe to import again: each step runs only when it is still needed.

SET @step := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE quotation_styles ADD COLUMN shipping_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER extra_label', 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotation_styles' AND COLUMN_NAME = 'shipping_cents');
PREPARE migration_step FROM @step;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;

SET @step := (SELECT IF(COUNT(*) = 1, 'ALTER TABLE quotations DROP COLUMN shipping_cents', 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND COLUMN_NAME = 'shipping_cents');
PREPARE migration_step FROM @step;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;
