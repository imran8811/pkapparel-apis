-- The wholesale shop no longer sells by set (owner request 2026-11-21): buyers enter the pieces of each size, and can add
-- sizes a style does not list (e.g. 38 for jeans listed in 26–36). The pieces per size of a set go: a style's own
-- (products.set_pcs_per_size) and the business rule (commerce.set_pcs_per_size). Stock listings keep their sets.
-- Upload the new API files first: the old code still saves products.set_pcs_per_size.
-- Safe to import again: each step runs only when it is still needed.

SET @step := (SELECT IF(COUNT(*) = 1, 'ALTER TABLE products DROP COLUMN set_pcs_per_size', 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'set_pcs_per_size');
PREPARE migration_step FROM @step;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;

UPDATE settings
SET value = JSON_REMOVE(value, '$.set_pcs_per_size')
WHERE name = 'commerce' AND JSON_CONTAINS_PATH(value, 'one', '$.set_pcs_per_size');

-- The MOQ answer no longer offers sets. Only the texts of migrations 0004 and 0020 are replaced, so an answer staff have
-- edited in the admin stays as it is.
UPDATE faqs
SET answer = 'MOQ is 100 pieces per style per color, with at least 20 pieces in each size you order: 100 pieces cover up to 5 sizes, and more sizes need more pieces. Enter the pieces you need of each size. A size you need that is not listed (for example 38) can be added on the product page.'
WHERE answer IN (
    'MOQ is 100 pieces per style per color, with at least 20 pieces in each size you order: 100 pieces cover up to 5 sizes, and more sizes need more pieces. Enter a quantity for each size, or add ready-made sets (1 set = 2 pieces of every size).',
    'MOQ is 100 pieces per style per color. Within a color you can mix sizes freely: enter a quantity for each size, or add ready-made sets (1 set = 2 pieces of every size).'
);
