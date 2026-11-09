-- Stock (owner decisions 2026-11-09): ready jeans from stock on hand, separate from the made-to-order wholesale shop.
-- Men's and boys' jeans only. Each style is sold by set only: one set per style, made of its sizes with a number of
-- pieces per size, priced per set. Stock is counted in sets per color. Amounts are in paisa (rupees × 100), the same
-- minor units as every other amount in this API.
--
-- Safe to import again: the tables are created only when they are missing, and the two pages only added once.

CREATE TABLE IF NOT EXISTS stock_styles (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug             VARCHAR(190) NOT NULL,
    name             VARCHAR(200) NOT NULL,
    style_no         VARCHAR(40)  NULL,
    -- men | boys
    segment          VARCHAR(20)  NOT NULL,
    -- The jeans specs (fit, rise, inseam, fabric…): {key: value}, keys as in ProductTypes for jeans pants.
    specs            JSON         NOT NULL,
    description_html MEDIUMTEXT   NOT NULL,
    -- The set, smallest size first: [{"size": "28", "pcs": 1}, …]
    set_sizes        JSON         NOT NULL,
    set_price_cents  INT UNSIGNED NOT NULL,
    seo_title        VARCHAR(200) NULL,
    seo_description  VARCHAR(320) NULL,
    status           VARCHAR(20)  NOT NULL DEFAULT 'draft',
    is_featured      TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order       INT          NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY stock_styles_slug (slug),
    KEY stock_styles_listing (status, segment, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The colors of a stock style, each with the number of sets on hand. Only stock movements change sets_in_stock.
CREATE TABLE IF NOT EXISTS stock_colors (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    style_id      INT UNSIGNED NOT NULL,
    code          VARCHAR(40)  NOT NULL,
    name          VARCHAR(80)  NOT NULL,
    slug          VARCHAR(80)  NOT NULL,
    hex           CHAR(7)      NULL,
    sets_in_stock INT UNSIGNED NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order    INT          NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY stock_colors_code (code),
    UNIQUE KEY stock_colors_slug (style_id, slug),
    CONSTRAINT stock_colors_style FOREIGN KEY (style_id) REFERENCES stock_styles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_images (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    style_id   INT UNSIGNED NOT NULL,
    color_id   INT UNSIGNED NOT NULL,
    path       VARCHAR(255) NOT NULL,
    alt        VARCHAR(200) NOT NULL,
    width      INT UNSIGNED NOT NULL,
    height     INT UNSIGNED NOT NULL,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY stock_images_color (color_id, sort_order),
    CONSTRAINT stock_images_style FOREIGN KEY (style_id) REFERENCES stock_styles (id) ON DELETE CASCADE,
    CONSTRAINT stock_images_color FOREIGN KEY (color_id) REFERENCES stock_colors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every change to a color's stock, with who made it and why: received, correction, damaged, returned or sold.
-- change_sets is positive for sets added, negative for sets taken out; sets_after is the stock after the change.
-- order_id links sales and returns to their order (stock orders come later).
CREATE TABLE IF NOT EXISTS stock_movements (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    color_id    INT UNSIGNED    NOT NULL,
    change_sets INT             NOT NULL,
    sets_after  INT UNSIGNED    NOT NULL,
    reason      VARCHAR(20)     NOT NULL,
    note        VARCHAR(500)    NULL,
    order_id    INT UNSIGNED    NULL,
    staff_id    INT UNSIGNED    NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY stock_movements_color (color_id, created_at),
    KEY stock_movements_created (created_at),
    CONSTRAINT stock_movements_color FOREIGN KEY (color_id) REFERENCES stock_colors (id) ON DELETE CASCADE,
    CONSTRAINT stock_movements_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
    CONSTRAINT stock_movements_staff FOREIGN KEY (staff_id) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The terms and privacy pages of the stock pages, editable under Admin → Legal pages (kept as they are when this file
-- is imported again).
INSERT INTO pages (slug, title, body_html, seo_description) VALUES
(
    'stock-terms', 'Terms and returns',
    '<p>These terms apply to every order of jeans from our stock. By ordering, you agree to them.</p><h2>Sets and prices</h2><p>Stock is sold by set only. Each style has one set, made of the sizes and pieces shown on its page. Prices are per set, in rupees.</p><h2>Orders and payment</h2><p>Send us your order on WhatsApp or by phone with the style, color and number of sets. We confirm what is in stock and the total. Pay the full amount by bank transfer, JazzCash or Easypaisa and send us the payment slip.</p><p>Sets are held for you once your payment is confirmed. If a color sells out before your payment arrives, we tell you straight away and refund what you paid for it.</p><h2>Delivery</h2><p>Once your order is paid, we book the cargo to your city and send your order. You pay the cargo charges to the cargo company when the goods arrive. You can also collect your order from us yourself.</p><h2>Returns</h2><p>If there is an issue with your order, such as a fault, a wrong size or a wrong color, tell us within 7 days of receiving it and send us photos. Returned goods must be unused and still have their tags. Once we receive them and confirm the issue, we replace them or refund them.</p><h2>Questions</h2><p>Contact us by WhatsApp, phone or email about any order.</p>',
    'Terms for orders of jeans from our stock: sets and prices, payment, cargo delivery or collection, and returns within 7 days.'
),
(
    'stock-privacy', 'Privacy',
    '<p>This page explains what we do with the details you give us when you contact us or order jeans from our stock.</p><h2>What we keep</h2><p>Your name, shop name, city, phone number and email address, and the details of your orders and payments.</p><h2>How we use it</h2><p>Only to answer you, to prepare and send your orders, and to keep the records the law requires. We never sell your details. We share them only with the cargo company that delivers your order.</p><h2>Visits to this website</h2><p>We count visits to this website with Google Analytics, without showing adverts.</p><h2>Your choices</h2><p>You can ask us at any time to show, correct or delete your details. Contact us by WhatsApp, phone or email.</p>',
    'What we do with the details you give us when you contact us or order jeans from our stock.'
)
ON DUPLICATE KEY UPDATE slug = slug;
