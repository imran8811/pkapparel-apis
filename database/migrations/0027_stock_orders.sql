-- Stock orders (owner decisions 2026-11-09): buyers order stock styles online, by set, in rupees.
-- Orders of kind "stock" are paid in full before dispatch (bank transfer, JazzCash or Easypaisa, reported with the
-- transaction ID like other payments). Sets are taken out of stock when the payment is confirmed. The order then goes
-- by cargo to the buyer's city (the buyer pays the cargo on arrival) or the buyer collects it.

-- Where the buyer opened the account ("wholesale" or "stock"): account emails link to that part of the website.
-- city: asked when opening a stock account (shop buyers are known by their city).
ALTER TABLE customers
    ADD COLUMN channel VARCHAR(20)  NOT NULL DEFAULT 'wholesale' AFTER status,
    ADD COLUMN city    VARCHAR(120) NULL AFTER country_code;

-- Sets of stock colors in a cart. They share the cart (one per buyer or guest) with the wholesale lines.
CREATE TABLE stock_cart_lines (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id    INT UNSIGNED NOT NULL,
    color_id   INT UNSIGNED NOT NULL,
    sets       INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY stock_cart_lines_color (cart_id, color_id),
    KEY stock_cart_lines_stock_color (color_id),
    CONSTRAINT stock_cart_lines_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
    CONSTRAINT stock_cart_lines_color FOREIGN KEY (color_id) REFERENCES stock_colors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A line of a stock order is a stock color and a number of sets (qty stays the pieces, unit_price_cents the set price).
ALTER TABLE order_lines
    ADD COLUMN stock_color_id INT UNSIGNED NULL AFTER color_id,
    ADD COLUMN sets           INT UNSIGNED NULL AFTER qty,
    ADD KEY order_lines_stock_color (stock_color_id),
    ADD CONSTRAINT order_lines_stock_color FOREIGN KEY (stock_color_id) REFERENCES stock_colors (id) ON DELETE SET NULL;

-- Stock settings (Admin → Settings), and the accounts stock buyers pay into. Both private: the payment details are
-- only shown to the buyer of an order with an amount due.
-- min_sets_per_order: the smallest order; dispatch_days: days to send a paid order; payment_days: an unpaid order is
-- cancelled after this many days (the stock was never held for it).
INSERT INTO settings (name, value, is_public) VALUES
('stock', '{"min_sets_per_order":1,"dispatch_days":2,"payment_days":7,"pickup_address":null,"pickup_hours":null}', 0),
('stock_payment', '{"bank_name":null,"account_title":null,"account_number":null,"iban":null,"jazzcash_name":null,"jazzcash_number":null,"easypaisa_name":null,"easypaisa_number":null,"notes":null}', 0)
ON DUPLICATE KEY UPDATE name = name;
