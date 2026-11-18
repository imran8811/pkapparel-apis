-- Quotations staff make in Admin → Quotations when a buyer sends artwork, sizes and colors and asks for prices
-- (owner request 2026-11-18). Each quotation has one or more styles; each style is costed per piece in rupees: the
-- fabric (rate × consumption per piece), cutting, stitching, washing, packing, printing, embroidery, accessories,
-- overhead and an extra cost, then the profit. Its price is quoted in rupees or, at the exchange rate typed in, in
-- US dollars, euros or pounds. Numbers run per year in document_sequences: QT-2026-00001.
-- Safe to import again: it only creates what is missing.

CREATE TABLE IF NOT EXISTS quotations (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    quotation_no    VARCHAR(20)     NOT NULL,
    status          VARCHAR(20)     NOT NULL DEFAULT 'open',
    customer_id     INT UNSIGNED    NULL,
    inquiry_id      INT UNSIGNED    NULL,
    buyer           JSON            NOT NULL,
    currency        CHAR(3)         NOT NULL DEFAULT 'USD',
    -- Rupees for 1 unit of the currency (280.5000 for 1 USD); NULL for a quotation in rupees.
    exchange_rate   DECIMAL(12,4)   NULL,
    issue_date      DATE            NOT NULL,
    valid_until     DATE            NULL,
    buyer_reference VARCHAR(60)     NULL,
    incoterm        VARCHAR(10)     NULL,
    delivery_time   VARCHAR(160)    NULL,
    -- The styles with a quantity: their pieces and their amount in the quotation's currency.
    total_qty       INT UNSIGNED    NOT NULL DEFAULT 0,
    total_cents     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    notes           TEXT            NULL,
    terms           TEXT            NULL,
    staff_notes     TEXT            NULL,
    sent_at         DATETIME        NULL,
    sent_to         VARCHAR(400)    NULL,
    decided_at      DATETIME        NULL,
    created_by      INT UNSIGNED    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY quotations_no (quotation_no),
    KEY quotations_status (status, issue_date),
    KEY quotations_customer (customer_id),
    KEY quotations_inquiry (inquiry_id),
    CONSTRAINT quotations_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT quotations_inquiry FOREIGN KEY (inquiry_id) REFERENCES inquiries (id) ON DELETE SET NULL,
    CONSTRAINT quotations_staff FOREIGN KEY (created_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A quotation's styles in their printed order. Costs are per piece in paisa (rupees × 100), as typed in; the fabric
-- cost, total cost, profit and prices are stored as App\Services\Quotations::price worked them out on saving.
CREATE TABLE IF NOT EXISTS quotation_styles (
    id                 INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    quotation_id       INT UNSIGNED      NOT NULL,
    sort_order         SMALLINT UNSIGNED NOT NULL,
    article_no         VARCHAR(60)       NULL,
    description        VARCHAR(200)      NULL,
    fabric             VARCHAR(200)      NULL,
    colors             VARCHAR(200)      NULL,
    sizes              VARCHAR(200)      NULL,
    details            VARCHAR(500)      NULL,
    qty                INT UNSIGNED      NULL,
    fabric_rate_cents  INT UNSIGNED      NOT NULL DEFAULT 0,
    fabric_unit        VARCHAR(10)       NOT NULL DEFAULT 'm',
    fabric_consumption DECIMAL(8,3)      NOT NULL DEFAULT 0,
    fabric_cost_cents  INT UNSIGNED      NOT NULL DEFAULT 0,
    cutting_cents      INT UNSIGNED      NOT NULL DEFAULT 0,
    stitching_cents    INT UNSIGNED      NOT NULL DEFAULT 0,
    washing_cents      INT UNSIGNED      NOT NULL DEFAULT 0,
    packing_cents      INT UNSIGNED      NOT NULL DEFAULT 0,
    printing_cents     INT UNSIGNED      NOT NULL DEFAULT 0,
    embroidery_cents   INT UNSIGNED      NOT NULL DEFAULT 0,
    accessories_cents  INT UNSIGNED      NOT NULL DEFAULT 0,
    overhead_cents     INT UNSIGNED      NOT NULL DEFAULT 0,
    extra_cents        INT UNSIGNED      NOT NULL DEFAULT 0,
    extra_label        VARCHAR(60)       NULL,
    cost_cents         INT UNSIGNED      NOT NULL,
    -- percent: profit_percent of the cost; amount: profit_cents typed in.
    profit_type        VARCHAR(10)       NOT NULL DEFAULT 'percent',
    profit_percent     DECIMAL(6,2)      NULL,
    profit_cents       INT UNSIGNED      NOT NULL,
    -- Cost + profit, in paisa; then the price per piece in the quotation's currency, and × qty.
    price_cents        INT UNSIGNED      NOT NULL,
    unit_price_cents   INT UNSIGNED      NOT NULL,
    amount_cents       BIGINT UNSIGNED   NULL,
    PRIMARY KEY (id),
    KEY quotation_styles_quotation (quotation_id, sort_order),
    CONSTRAINT quotation_styles_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
