-- Carts, freight rates and customization add-ons (Phase 3).

-- One cart per buyer, or per guest browser (token_hash is the SHA-256 of the guest's cart cookie).
-- A guest cart is merged into the buyer's cart at login.
CREATE TABLE carts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT UNSIGNED NULL,
    token_hash  CHAR(64)     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY carts_customer (customer_id),
    UNIQUE KEY carts_token (token_hash),
    KEY carts_updated (updated_at),
    CONSTRAINT carts_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One line per color of a style, with the quantity per size, e.g. {"30": 20, "32": 20}.
-- Prices are not stored: the cart is always priced from the catalog.
CREATE TABLE cart_lines (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id    INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    color_id   INT UNSIGNED NOT NULL,
    size_qty   JSON         NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY cart_lines_cart_color (cart_id, color_id),
    KEY cart_lines_product (product_id),
    CONSTRAINT cart_lines_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
    CONSTRAINT cart_lines_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT cart_lines_color FOREIGN KEY (color_id) REFERENCES product_colors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Freight estimate: countries are grouped into zones; each zone has air and sea rates.
CREATE TABLE shipping_zones (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(120) NOT NULL,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A country belongs to at most one zone.
CREATE TABLE shipping_zone_countries (
    country_code CHAR(2)      NOT NULL,
    zone_id      INT UNSIGNED NOT NULL,
    PRIMARY KEY (country_code),
    KEY shipping_zone_countries_zone (zone_id),
    CONSTRAINT shipping_zone_countries_zone FOREIGN KEY (zone_id) REFERENCES shipping_zones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate bands. Air: price per chargeable kg from from_qty kg. Sea (LCL): price per CBM from from_qty CBM.
-- The band with the highest from_qty that the shipment reaches applies; fixed_cents is added once per shipment.
CREATE TABLE shipping_rates (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    zone_id          INT UNSIGNED  NOT NULL,
    mode             VARCHAR(10)   NOT NULL,
    from_qty         DECIMAL(10,3) NOT NULL DEFAULT 0,
    rate_cents       INT UNSIGNED  NOT NULL,
    fixed_cents      INT UNSIGNED  NOT NULL DEFAULT 0,
    transit_min_days SMALLINT UNSIGNED NULL,
    transit_max_days SMALLINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY shipping_rates_band (zone_id, mode, from_qty),
    CONSTRAINT shipping_rates_zone FOREIGN KEY (zone_id) REFERENCES shipping_zones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Private-label extras a buyer can add at checkout.
-- pricing: per_piece (price × all pieces of the order), per_order (once), on_request (quoted, added to the balance).
CREATE TABLE customization_options (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    pricing     VARCHAR(20)  NOT NULL DEFAULT 'on_request',
    price_cents INT UNSIGNED NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The services listed on the private label page, priced on request until PK Apparel sets prices.
INSERT INTO customization_options (name, description, pricing, price_cents, sort_order) VALUES
('Your woven brand labels', 'Your logo on woven main labels, with care and size labels. Send the artwork after ordering.', 'on_request', NULL, 10),
('Printed hang tags', 'Card hang tags printed with your design, attached to every piece.', 'on_request', NULL, 20),
('Leather or PU back patch', 'Your logo on a leather or PU patch on the waistband.', 'on_request', NULL, 30),
('Branded buttons and rivets', 'Metal buttons and rivets engraved with your logo.', 'on_request', NULL, 40),
('Your polybags and carton marks', 'Folded and packed in your printed polybags, with your own carton marks.', 'on_request', NULL, 50);

-- Bank account for transfers: private (never in /v1/site). Shown only to the owner of an order that has an amount due.
INSERT INTO settings (name, value, is_public) VALUES
('bank', '{"beneficiary":null,"bank_name":null,"branch":null,"bank_address":null,"account_number":null,"iban":null,"swift":null,"notes":null}', 0);
