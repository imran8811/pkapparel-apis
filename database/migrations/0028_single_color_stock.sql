-- Every stock listing is one color (owner decision 2026-11-09): a jeans color is an item of its own, with its own
-- details, and is never combined with other colors. A stock style with more than one color is split: its first color
-- (in staff order) stays, and every other color becomes a listing of its own, with a copy of the style's details, at
-- the address {style}-{color}. A new listing is shown when the style was shown and the color is shown and has a photo.
-- Orders, carts and the stock history follow the colors, which keep their ids.
--
-- Safe to import again: once every listing has one color, nothing is left to split.

-- A temporary link from each new listing to its color.
SET @step := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE stock_styles ADD COLUMN split_color_id INT UNSIGNED NULL', 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_styles' AND COLUMN_NAME = 'split_color_id');
PREPARE migration_step FROM @step;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;

-- A listing for every color that is not the first of its style (and has none yet, if an earlier import stopped).
INSERT INTO stock_styles (slug, name, style_no, segment, specs, description_html, set_sizes, set_price_cents, status, is_featured, sort_order, split_color_id)
SELECT
    IF(
        EXISTS (SELECT 1 FROM stock_styles x WHERE x.slug = LEFT(CONCAT(s.slug, '-', c.slug), 190)),
        LEFT(CONCAT(s.slug, '-', c.slug, '-', c.id), 190),
        LEFT(CONCAT(s.slug, '-', c.slug), 190)
    ),
    s.name, s.style_no, s.segment, s.specs, s.description_html, s.set_sizes, s.set_price_cents,
    IF(s.status = 'active' AND c.is_active = 1 AND EXISTS (SELECT 1 FROM stock_images i WHERE i.color_id = c.id), 'active', 'draft'),
    0, s.sort_order, c.id
FROM stock_colors c
JOIN stock_styles s ON s.id = c.style_id
WHERE EXISTS (
        SELECT 1 FROM stock_colors f
        WHERE f.style_id = c.style_id AND (f.sort_order < c.sort_order OR (f.sort_order = c.sort_order AND f.id < c.id))
    )
    AND NOT EXISTS (SELECT 1 FROM stock_styles d WHERE d.split_color_id = c.id);

-- Each of those colors, with its photos, moves to its new listing.
UPDATE stock_colors c JOIN stock_styles s ON s.split_color_id = c.id SET c.style_id = s.id;
UPDATE stock_images i JOIN stock_colors c ON c.id = i.color_id SET i.style_id = c.style_id WHERE i.style_id <> c.style_id;

SET @step := (SELECT IF(COUNT(*) = 1, 'ALTER TABLE stock_styles DROP COLUMN split_color_id', 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_styles' AND COLUMN_NAME = 'split_color_id');
PREPARE migration_step FROM @step;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;
