-- Invoices staff make in Admin → Invoices for buyers who order outside the website: sample invoices, proforma
-- invoices for production (the deposit) and final invoices. Not tied to an order: the buyer, lines and charges are
-- typed in (or copied from a buyer account) and kept as entered. Numbers run per type and year in document_sequences:
-- SI-2026-00001 (sample), PI-2026-00001 (proforma), INV-2026-00001 (invoice, shared with the orders' balance invoices).
CREATE TABLE invoices (
    id                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    invoice_no        VARCHAR(20)      NOT NULL,
    type              VARCHAR(20)      NOT NULL,
    status            VARCHAR(20)      NOT NULL DEFAULT 'open',
    customer_id       INT UNSIGNED     NULL,
    buyer             JSON             NOT NULL,
    consignee         JSON             NULL,
    currency          CHAR(3)          NOT NULL DEFAULT 'USD',
    issue_date        DATE             NOT NULL,
    due_date          DATE             NULL,
    buyer_reference   VARCHAR(60)      NULL,
    incoterm          VARCHAR(10)      NULL,
    shipping_method   VARCHAR(120)     NULL,
    delivery_time     VARCHAR(160)     NULL,
    subtotal_cents    INT UNSIGNED     NOT NULL,
    shipping_cents    INT UNSIGNED     NOT NULL DEFAULT 0,
    discount_cents    INT UNSIGNED     NOT NULL DEFAULT 0,
    total_cents       INT UNSIGNED     NOT NULL,
    received_cents    INT UNSIGNED     NOT NULL DEFAULT 0,
    deposit_percent   TINYINT UNSIGNED NOT NULL DEFAULT 100,
    due_now_cents     INT UNSIGNED     NOT NULL,
    notes             TEXT             NULL,
    terms             TEXT             NULL,
    staff_notes       TEXT             NULL,
    sent_at           DATETIME         NULL,
    sent_to           VARCHAR(400)     NULL,
    paid_on           DATE             NULL,
    payment_reference VARCHAR(120)     NULL,
    cancelled_at      DATETIME         NULL,
    created_by        INT UNSIGNED     NULL,
    created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY invoices_no (invoice_no),
    KEY invoices_status (status, issue_date),
    KEY invoices_customer (customer_id),
    CONSTRAINT invoices_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT invoices_staff FOREIGN KEY (created_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The invoice's lines in their printed order.
CREATE TABLE invoice_lines (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    invoice_id       INT UNSIGNED      NOT NULL,
    sort_order       SMALLINT UNSIGNED NOT NULL,
    description      VARCHAR(200)      NOT NULL,
    details          VARCHAR(500)      NULL,
    hs_code          VARCHAR(20)       NULL,
    qty              INT UNSIGNED      NOT NULL,
    unit             VARCHAR(20)       NOT NULL DEFAULT 'pcs',
    unit_price_cents INT UNSIGNED      NOT NULL,
    amount_cents     INT UNSIGNED      NOT NULL,
    PRIMARY KEY (id),
    KEY invoice_lines_invoice (invoice_id, sort_order),
    CONSTRAINT invoice_lines_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
