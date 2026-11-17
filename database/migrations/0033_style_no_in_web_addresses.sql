-- The style no. at the end of the web address of every wholesale style and stock listing saved before addresses were
-- made that way (owner request 2026-11-17): "mens-slim-jeans" with style no. 1051 → "mens-slim-jeans-1051". New ones get
-- it from the admin forms and the API (App\Core\Text::styleSlug).
--
-- Before importing it, run the preview below in phpMyAdmin's SQL tab (copy the query between the two "preview" lines): it
-- lists every address this changes (old → new) and changes nothing; "active" ones are shown, and their old address will
-- redirect to the new one. After importing it, redeploy the website so its pages and links use the new addresses.
--
-- - The style no. goes after the current address, so a typed address and a stock listing's color stay. A stock
--   listing whose address is its name's with a number (a duplicate: "men-s-slim-jeans-2") gets the number after the
--   style no. instead. Only the letters and digits of a style no. are kept ("PK/1051" → "pk-1051").
-- - An address another style already has is numbered ("-2", "-3"), so no old address changes owner.
-- - A shown style or listing keeps its old address working: a redirect to the new one, as when staff change an address.
-- - Styles without a style no. (such as those imported from the old shop) and addresses that end with their style no.
--   already stay as they are, so importing this again changes nothing.

/* ---------- preview: copy from the next line ----------
SELECT u.shop, u.id, u.status, u.name, u.style_no,
       u.old_slug,
       u.new_slug,
       CONCAT(u.path, u.old_slug) AS from_path,
       CONCAT(u.path, u.new_slug) AS to_path
FROM (
    SELECT t.*,
           CASE
               WHEN t.place = 1 AND t.taken = 0 THEN t.wanted
               WHEN NOT (CASE WHEN t.shop = 'wholesale'
                              THEN EXISTS (SELECT 1 FROM products x WHERE x.slug = CONCAT(t.wanted, '-', t.place + t.taken))
                              ELSE EXISTS (SELECT 1 FROM stock_styles x WHERE x.slug = CONCAT(t.wanted, '-', t.place + t.taken)) END)
                   THEN CONCAT(t.wanted, '-', t.place + t.taken)
               ELSE CONCAT(t.wanted, '-', t.id)
           END AS new_slug
    FROM (
        SELECT w.*,
               ROW_NUMBER() OVER (PARTITION BY w.shop, w.wanted ORDER BY w.id) AS place,
               CASE WHEN w.shop = 'wholesale'
                    THEN EXISTS (SELECT 1 FROM products x WHERE x.slug = w.wanted)
                    ELSE EXISTS (SELECT 1 FROM stock_styles x WHERE x.slug = w.wanted) END AS taken
        FROM (
            SELECT r.*,
                   CONCAT(TRIM(TRAILING '-' FROM LEFT(
                       CASE WHEN r.shop = 'stock' AND r.name_slug <> '' AND r.old_slug REGEXP CONCAT('^', r.name_slug, '-[0-9]+$')
                            THEN r.name_slug ELSE r.old_slug END,
                       189 - CHAR_LENGTH(r.number))), '-', r.number) AS wanted
            FROM (
                SELECT 'wholesale' AS shop, p.id, p.status, p.name, p.style_no, p.slug AS old_slug,
                       CONCAT('/wholesale-shop/', p.segment, '/', p.product_type, '/') AS path,
                       TRIM(BOTH '-' FROM REGEXP_REPLACE(LOWER(p.style_no), '[^a-z0-9]+', '-')) AS number,
                       '' AS name_slug
                FROM products p
                WHERE p.style_no IS NOT NULL AND p.style_no <> ''
                UNION ALL
                SELECT 'stock', s.id, s.status, s.name, s.style_no, s.slug,
                       CONCAT('/stock/', s.segment, '/'),
                       TRIM(BOTH '-' FROM REGEXP_REPLACE(LOWER(s.style_no), '[^a-z0-9]+', '-')),
                       TRIM(BOTH '-' FROM REGEXP_REPLACE(LOWER(s.name), '[^a-z0-9]+', '-'))
                FROM stock_styles s
                WHERE s.style_no IS NOT NULL AND s.style_no <> ''
            ) r
            WHERE r.number <> '' AND r.old_slug NOT REGEXP CONCAT('(^|-)', r.number, '(-[0-9]+)?$')
        ) w
    ) t
) u
ORDER BY u.shop DESC, u.id;
---------- preview: copy up to the line above ---------- */

DROP TEMPORARY TABLE IF EXISTS style_no_addresses;

-- What changes: the same query as the preview.
CREATE TEMPORARY TABLE style_no_addresses AS
SELECT u.shop, u.id, u.status, u.name, u.style_no,
       u.old_slug,
       u.new_slug,
       CONCAT(u.path, u.old_slug) AS from_path,
       CONCAT(u.path, u.new_slug) AS to_path
FROM (
    SELECT t.*,
           CASE
               WHEN t.place = 1 AND t.taken = 0 THEN t.wanted
               WHEN NOT (CASE WHEN t.shop = 'wholesale'
                              THEN EXISTS (SELECT 1 FROM products x WHERE x.slug = CONCAT(t.wanted, '-', t.place + t.taken))
                              ELSE EXISTS (SELECT 1 FROM stock_styles x WHERE x.slug = CONCAT(t.wanted, '-', t.place + t.taken)) END)
                   THEN CONCAT(t.wanted, '-', t.place + t.taken)
               ELSE CONCAT(t.wanted, '-', t.id)
           END AS new_slug
    FROM (
        SELECT w.*,
               ROW_NUMBER() OVER (PARTITION BY w.shop, w.wanted ORDER BY w.id) AS place,
               CASE WHEN w.shop = 'wholesale'
                    THEN EXISTS (SELECT 1 FROM products x WHERE x.slug = w.wanted)
                    ELSE EXISTS (SELECT 1 FROM stock_styles x WHERE x.slug = w.wanted) END AS taken
        FROM (
            SELECT r.*,
                   CONCAT(TRIM(TRAILING '-' FROM LEFT(
                       CASE WHEN r.shop = 'stock' AND r.name_slug <> '' AND r.old_slug REGEXP CONCAT('^', r.name_slug, '-[0-9]+$')
                            THEN r.name_slug ELSE r.old_slug END,
                       189 - CHAR_LENGTH(r.number))), '-', r.number) AS wanted
            FROM (
                SELECT 'wholesale' AS shop, p.id, p.status, p.name, p.style_no, p.slug AS old_slug,
                       CONCAT('/wholesale-shop/', p.segment, '/', p.product_type, '/') AS path,
                       TRIM(BOTH '-' FROM REGEXP_REPLACE(LOWER(p.style_no), '[^a-z0-9]+', '-')) AS number,
                       '' AS name_slug
                FROM products p
                WHERE p.style_no IS NOT NULL AND p.style_no <> ''
                UNION ALL
                SELECT 'stock', s.id, s.status, s.name, s.style_no, s.slug,
                       CONCAT('/stock/', s.segment, '/'),
                       TRIM(BOTH '-' FROM REGEXP_REPLACE(LOWER(s.style_no), '[^a-z0-9]+', '-')),
                       TRIM(BOTH '-' FROM REGEXP_REPLACE(LOWER(s.name), '[^a-z0-9]+', '-'))
                FROM stock_styles s
                WHERE s.style_no IS NOT NULL AND s.style_no <> ''
            ) r
            WHERE r.number <> '' AND r.old_slug NOT REGEXP CONCAT('(^|-)', r.number, '(-[0-9]+)?$')
        ) w
    ) t
) u;

UPDATE products p JOIN style_no_addresses a ON a.shop = 'wholesale' AND a.id = p.id SET p.slug = a.new_slug;

UPDATE stock_styles s JOIN style_no_addresses a ON a.shop = 'stock' AND a.id = s.id SET s.slug = a.new_slug;

-- A redirect from a new address would hide its page (none is expected).
DELETE r FROM redirects r JOIN style_no_addresses a ON r.from_path = a.to_path;

-- Shown styles and listings: the old address leads to the new one.
INSERT INTO redirects (from_path, to_path, source, created_at, updated_at)
SELECT a.from_path, a.to_path, 'style', UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM style_no_addresses a
WHERE a.status = 'active'
ON DUPLICATE KEY UPDATE to_path = VALUES(to_path), updated_at = VALUES(updated_at);

DROP TEMPORARY TABLE IF EXISTS style_no_addresses;
