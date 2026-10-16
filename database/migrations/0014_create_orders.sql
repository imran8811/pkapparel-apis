-- Orders (Phase 3: placing orders and reporting payments; Phase 4 adds the staff workflow).
-- An order keeps frozen copies of what was bought, the prices and the buyer's details,
-- so later catalog or profile changes never alter it.

CREATE TABLE orders (
    id                     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    order_no               VARCHAR(20)       NOT NULL,
    customer_id            INT UNSIGNED      NOT NULL,
    status                 VARCHAR(30)       NOT NULL DEFAULT 'awaiting_deposit',
    currency               CHAR(3)           NOT NULL DEFAULT 'USD',
    total_pcs              INT UNSIGNED      NOT NULL,
    goods_cents            INT UNSIGNED      NOT NULL,
    addons_cents           INT UNSIGNED      NOT NULL DEFAULT 0,
    shipping_mode          VARCHAR(20)       NOT NULL,
    incoterm               VARCHAR(10)       NOT NULL,
    freight_estimate_cents INT UNSIGNED      NULL,
    freight_final_cents    INT UNSIGNED      NULL,
    deposit_percent        TINYINT UNSIGNED  NOT NULL,
    deposit_cents          INT UNSIGNED      NOT NULL,
    paid_cents             INT UNSIGNED      NOT NULL DEFAULT 0,
    lead_time_days         SMALLINT UNSIGNED NOT NULL,
    packing                JSON              NOT NULL,
    customer               JSON              NOT NULL,
    address                JSON              NOT NULL,
    forwarder              JSON              NULL,
    buyer_reference        VARCHAR(60)       NULL,
    notes                  TEXT              NULL,
    placed_at              DATETIME          NOT NULL,
    confirmed_at           DATETIME          NULL,
    production_due_on      DATE              NULL,
    shipped_at             DATETIME          NULL,
    delivered_at           DATETIME          NULL,
    cancelled_at           DATETIME          NULL,
    cancel_reason          VARCHAR(500)      NULL,
    created_at             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY orders_order_no (order_no),
    KEY orders_customer (customer_id, placed_at),
    KEY orders_status (status, placed_at),
    CONSTRAINT orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One line per color of a style, with the quantity per size and the price per piece at the time of ordering.
CREATE TABLE order_lines (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id         INT UNSIGNED NOT NULL,
    product_id       INT UNSIGNED NULL,
    color_id         INT UNSIGNED NULL,
    style_no         VARCHAR(40)  NULL,
    style_name       VARCHAR(200) NOT NULL,
    style_slug       VARCHAR(190) NOT NULL,
    segment          VARCHAR(20)  NOT NULL,
    product_type     VARCHAR(40)  NOT NULL,
    color_code       VARCHAR(40)  NOT NULL,
    color_name       VARCHAR(80)  NOT NULL,
    color_hex        CHAR(7)      NULL,
    image_path       VARCHAR(255) NULL,
    size_qty         JSON         NOT NULL,
    qty              INT UNSIGNED NOT NULL,
    unit_price_cents INT UNSIGNED NOT NULL,
    line_cents       INT UNSIGNED NOT NULL,
    sort_order       INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY order_lines_order (order_id),
    KEY order_lines_product (product_id),
    CONSTRAINT order_lines_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT order_lines_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
    CONSTRAINT order_lines_color FOREIGN KEY (color_id) REFERENCES product_colors (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customization add-ons chosen at checkout. line_cents is NULL while the price is "on request".
CREATE TABLE order_addons (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id         INT UNSIGNED NOT NULL,
    option_id        INT UNSIGNED NULL,
    name             VARCHAR(120) NOT NULL,
    pricing          VARCHAR(20)  NOT NULL,
    unit_price_cents INT UNSIGNED NULL,
    qty              INT UNSIGNED NOT NULL DEFAULT 1,
    line_cents       INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY order_addons_order (order_id),
    CONSTRAINT order_addons_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT order_addons_option FOREIGN KEY (option_id) REFERENCES customization_options (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timeline and audit trail. Events with is_public = 0 are only shown to staff.
CREATE TABLE order_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED    NOT NULL,
    type        VARCHAR(30)     NOT NULL,
    from_status VARCHAR(30)     NULL,
    to_status   VARCHAR(30)     NULL,
    note        TEXT            NULL,
    actor_type  VARCHAR(20)     NOT NULL,
    actor_id    INT UNSIGNED    NULL,
    is_public   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY order_events_order (order_id, created_at),
    CONSTRAINT order_events_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bank transfers reported by buyers on the Confirm payment page.
-- transaction_key is the normalised transaction ID; it is unique so an ID can be used only once,
-- and it is cleared when staff reject a payment, so the buyer can correct and resubmit it.
CREATE TABLE order_payments (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id         INT UNSIGNED NOT NULL,
    kind             VARCHAR(20)  NOT NULL,
    amount_cents     INT UNSIGNED NOT NULL,
    currency         CHAR(3)      NOT NULL DEFAULT 'USD',
    transaction_id   VARCHAR(64)  NOT NULL,
    transaction_key  VARCHAR(64)  NULL,
    transferred_on   DATE         NOT NULL,
    payer_name       VARCHAR(120) NOT NULL,
    payer_bank       VARCHAR(120) NOT NULL,
    slip_path        VARCHAR(255) NULL,
    slip_name        VARCHAR(190) NULL,
    slip_type        VARCHAR(60)  NULL,
    notes            TEXT         NULL,
    status           VARCHAR(20)  NOT NULL DEFAULT 'submitted',
    reviewed_by      INT UNSIGNED NULL,
    reviewed_at      DATETIME     NULL,
    rejection_reason VARCHAR(500) NULL,
    receipt_no       VARCHAR(30)  NULL,
    receipt_sent_at  DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY order_payments_transaction (transaction_key),
    UNIQUE KEY order_payments_receipt (receipt_no),
    KEY order_payments_order (order_id),
    KEY order_payments_status (status, created_at),
    CONSTRAINT order_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT order_payments_reviewer FOREIGN KEY (reviewed_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
