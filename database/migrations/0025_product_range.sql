-- Phase 7 (owner decisions 2026-10-20): Men and Women only; T-shirts, hoodies and polo shirts for both;
-- a size system per style and size chart; details (specs) that fit each product type; add-ons per product type.
-- PK Apparel is now described as an apparel manufacturer (no longer "denim manufacturer").

-- How a style's sizes are named: waist (inches), letter (S, M, L…) or other (the style's own list).
-- Specs beyond the shared columns (rise, inseam, neckline, sleeve, collar…) go in `attributes`; NULL means none.
ALTER TABLE products
    ADD COLUMN size_system VARCHAR(20) NOT NULL DEFAULT 'waist' AFTER features,
    ADD COLUMN attributes JSON NULL AFTER closure;

ALTER TABLE size_charts
    ADD COLUMN size_system VARCHAR(20) NOT NULL DEFAULT 'waist' AFTER name;

-- NULL: offered with every product type; otherwise a JSON list of product types.
ALTER TABLE customization_options
    ADD COLUMN product_types JSON NULL AFTER price_cents;

-- ----- Girls' styles become women's styles, with women's waist sizes 26–36 -----

-- Their old addresses on this site redirect to the new ones.
INSERT INTO redirects (from_path, to_path, source, created_at, updated_at)
SELECT CONCAT('/wholesale-shop/girls/', product_type, '/', slug),
       CONCAT('/wholesale-shop/women/', product_type, '/', IF(slug LIKE 'girls-%', CONCAT('womens-', SUBSTRING(slug, 7)), slug)),
       'style', UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM products WHERE segment = 'girls'
ON DUPLICATE KEY UPDATE to_path = VALUES(to_path), updated_at = VALUES(updated_at);

UPDATE product_images i JOIN products p ON p.id = i.product_id
SET i.alt = REPLACE(i.alt, 'Girls'' ', 'Women''s ')
WHERE p.segment = 'girls';

UPDATE products SET
    slug = IF(slug LIKE 'girls-%', CONCAT('womens-', SUBSTRING(slug, 7)), slug),
    name = REPLACE(name, 'Girls'' ', 'Women''s '),
    description_html = REPLACE(description_html, ' for girls', ' for women'),
    seo_title = REPLACE(seo_title, 'Girls''', 'Women''s'),
    seo_description = REPLACE(seo_description, 'girls', 'women'),
    size_system = 'waist',
    sizes = '["26","28","30","32","34","36"]',
    segment = 'women'
WHERE segment = 'girls';

-- Boys has no styles; any made later in the admin would be men's.
UPDATE products SET segment = 'men' WHERE segment = 'boys';

-- ----- Shop category pages -----

DELETE FROM categories WHERE segment IN ('boys', 'girls');

UPDATE categories SET
    title = 'Men''s clothing',
    intro_html = '<p>Wholesale jeans, chinos, cargo trousers, denim jackets, shorts, T-shirts, hoodies and polo shirts for men, made to order in our factory in Lahore. Choose a style, pick colors and sizes, and order from 100 pieces per color.</p><p>Every order is cut, sewn, finished and checked in-house, and is ready about 30 days after your deposit. We ship by air or sea, or hand the goods to your own forwarder.</p>'
WHERE segment = 'men' AND product_type = '' AND title = 'Men''s denim';

UPDATE categories SET
    title = 'Women''s clothing',
    intro_html = '<p>Wholesale jeans, denim skirts and jackets, shorts, T-shirts, hoodies and polo shirts for women, made to order in Lahore: wide-leg, flare, straight and stretch jeans in light, mid and dark washes, and tops in your colors. Order from 100 pieces per color, split across the size range.</p><p>Production takes about 30 days after the deposit. Add your own labels and packaging for a private-label range.</p>'
WHERE segment = 'women' AND product_type = '' AND title = 'Women''s denim';

-- The former girls' styles (flare and wide-leg, printed and patched) are now women's jeans.
UPDATE categories SET
    intro_html = '<p>Wide-leg, flare and stretch jeans for women in light, mid and dark washes, plain or with prints and patches. 10 to 12 oz denim, rigid or stretch, sizes 26 to 36.</p><p>Made to order from 100 pieces per color. We can also make your own designs: send a tech pack or a reference sample.</p>'
WHERE segment = 'women' AND product_type = 'jeans-pant'
  AND intro_html = '<p>Wide-leg and stretch jeans for women in light, mid and dark washes. 11 to 12 oz denim, rigid or stretch, sizes 26 to 36.</p><p>Made to order from 100 pieces per color. We can also make your own designs: send a tech pack or a reference sample.</p>';

INSERT INTO categories (segment, product_type, title, intro_html, faq, sort_order) VALUES
('men', 't-shirt', 'Men''s T-shirts', '<p>Men''s T-shirts, made to order in our factory in Lahore: crew-neck, V-neck and oversized cuts in cotton or cotton-polyester jersey, in your colors and with your labels. There are no styles in the online shop yet: send us a design, a photo or a sample and we will quote it, from 100 pieces per color.</p>', '[{"question":"How do I order a style that is not in the shop?","answer":"Send us a design, a tech pack, a photo or a sample through the quote form or on WhatsApp. We reply with a price, and you can order a sample first."}]', 62),
('men', 'hoodie', 'Men''s hoodies', '<p>Men''s hoodies, made to order in our factory in Lahore: pullover and zip styles in fleece or French terry, in your colors and with your labels. There are no styles in the online shop yet: send us a design, a photo or a sample and we will quote it, from 100 pieces per color.</p>', '[{"question":"How do I order a style that is not in the shop?","answer":"Send us a design, a tech pack, a photo or a sample through the quote form or on WhatsApp. We reply with a price, and you can order a sample first."}]', 64),
('men', 'polo-shirt', 'Men''s polo shirts', '<p>Men''s polo shirts, made to order in our factory in Lahore: pique or jersey, with a rib-knit or self-fabric collar, in your colors and with your labels. There are no styles in the online shop yet: send us a design, a photo or a sample and we will quote it, from 100 pieces per color.</p>', '[{"question":"How do I order a style that is not in the shop?","answer":"Send us a design, a tech pack, a photo or a sample through the quote form or on WhatsApp. We reply with a price, and you can order a sample first."}]', 66),
('women', 't-shirt', 'Women''s T-shirts', '<p>Women''s T-shirts, made to order in our factory in Lahore: regular, slim and oversized cuts in cotton or cotton-polyester jersey, in your colors and with your labels. There are no styles in the online shop yet: send us a design, a photo or a sample and we will quote it, from 100 pieces per color.</p>', '[{"question":"How do I order a style that is not in the shop?","answer":"Send us a design, a tech pack, a photo or a sample through the quote form or on WhatsApp. We reply with a price, and you can order a sample first."}]', 132),
('women', 'hoodie', 'Women''s hoodies', '<p>Women''s hoodies, made to order in our factory in Lahore: pullover and zip styles in fleece or French terry, in your colors and with your labels. There are no styles in the online shop yet: send us a design, a photo or a sample and we will quote it, from 100 pieces per color.</p>', '[{"question":"How do I order a style that is not in the shop?","answer":"Send us a design, a tech pack, a photo or a sample through the quote form or on WhatsApp. We reply with a price, and you can order a sample first."}]', 134),
('women', 'polo-shirt', 'Women''s polo shirts', '<p>Women''s polo shirts, made to order in our factory in Lahore: pique or jersey, regular or slim fit, in your colors and with your labels. There are no styles in the online shop yet: send us a design, a photo or a sample and we will quote it, from 100 pieces per color.</p>', '[{"question":"How do I order a style that is not in the shop?","answer":"Send us a design, a tech pack, a photo or a sample through the quote form or on WhatsApp. We reply with a price, and you can order a sample first."}]', 136);

-- ----- Add-ons per product type -----

UPDATE customization_options
SET product_types = '["jeans-pant","denim-jacket","jeans-short","cargo-trouser","chino-pant","denim-skirt"]'
WHERE name IN ('Leather or PU back patch', 'Branded buttons and rivets');

-- For tops, switched off until staff turn them on (Admin → Add-ons).
INSERT INTO customization_options (name, description, pricing, price_cents, product_types, is_active, sort_order) VALUES
('Screen printing', 'Your logo or artwork screen printed on each piece. Priced with your design.', 'on_request', NULL, '["t-shirt","hoodie","polo-shirt"]', 0, 60),
('Embroidery', 'Your logo embroidered on each piece. Priced with your design.', 'on_request', NULL, '["t-shirt","hoodie","polo-shirt"]', 0, 70);

-- ----- Company: an apparel manufacturer, and the factory makes tops too -----

UPDATE settings SET value = JSON_SET(value, '$.tagline', 'Apparel manufacturer & exporter')
WHERE name = 'company' AND JSON_UNQUOTE(JSON_EXTRACT(value, '$.tagline')) = 'Denim manufacturer & exporter';

UPDATE factory_departments
SET summary = 'Denim, twill and canvas from Pakistani mills, and knit fabrics for T-shirts, hoodies and polo shirts, are checked for shade, weight and width before they reach the cutting table.'
WHERE slug = 'fabric-store' AND summary = 'Denim, twill and canvas from Pakistani mills are checked for shade, weight and width before they reach the cutting table.';

UPDATE factory_departments
SET summary = 'Dedicated lines for jeans, jackets, shorts, cargo and chino pants, with bar-tacks, rivets and buttons fitted in-house. T-shirts, hoodies and polo shirts are sewn in-house too.'
WHERE slug = 'stitching' AND summary = 'Dedicated lines for jeans, jackets, shorts, cargo and chino pants, with bar-tacks, rivets and buttons fitted in-house.';

UPDATE factory_departments
SET summary = 'Our own laundry washes and finishes every denim lot in-house, and each shade is checked against the approved standard.'
WHERE slug = 'washing' AND summary = 'Our own laundry washes and finishes every lot in-house, and each shade is checked against the approved standard.';
