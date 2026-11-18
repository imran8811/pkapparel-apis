-- A quotation's shipping charge (owner request 2026-11-18): one amount for the whole order, in the quotation's
-- currency (e.g. sea freight or a courier), printed as its own line and added to the total. It is not part of the
-- prices per piece. 0: no shipping (e.g. FOB, where the buyer pays the freight).
-- Safe to import again: the column is added only when it is missing.

SET @step := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE quotations ADD COLUMN shipping_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER total_cents', 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND COLUMN_NAME = 'shipping_cents');
PREPARE migration_step FROM @step;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;
