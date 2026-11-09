-- Stock prices are per piece (owner decision 2026-11-09): staff enter the price of one piece, and a set costs its
-- pieces times that, e.g. 12 pieces × Rs 980 = Rs 11,760. piece_price_cents is what staff enter; set_price_cents stays,
-- kept by the API as pieces × piece price, for the cart, orders and reports. A listing saved before this has 0 here
-- until it is saved again: the API meanwhile spreads its set price over its pieces.
--
-- Safe to import again: the column is added only when it is missing.

SET @step := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE stock_styles ADD COLUMN piece_price_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER set_sizes', 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_styles' AND COLUMN_NAME = 'piece_price_cents');
PREPARE migration_step FROM @step;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;
