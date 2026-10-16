-- Order management (Phase 4): document numbers, production stages and photos, uploaded documents,
-- shipments, and the extra order and payment columns the staff workflow needs.

-- Running numbers per document type and year, e.g. receipts RCPT-2026-00001.
CREATE TABLE document_sequences (
    name       VARCHAR(30)       NOT NULL,
    year       SMALLINT UNSIGNED NOT NULL,
    last_value INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (name, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Production progress: one row per stage that has started.
CREATE TABLE order_stages (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id     INT UNSIGNED NOT NULL,
    stage        VARCHAR(30)  NOT NULL,
    started_on   DATE         NULL,
    completed_on DATE         NULL,
    note         VARCHAR(500) NULL,
    updated_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY order_stages_stage (order_id, stage),
    CONSTRAINT order_stages_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT order_stages_staff FOREIGN KEY (updated_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Production photos. Private files (storage/private/production): only staff and the order's buyer see them.
CREATE TABLE order_photos (
    id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    order_id     INT UNSIGNED      NOT NULL,
    stage        VARCHAR(30)       NULL,
    path         VARCHAR(255)      NOT NULL,
    preview_path VARCHAR(255)      NOT NULL,
    width        SMALLINT UNSIGNED NOT NULL,
    height       SMALLINT UNSIGNED NOT NULL,
    caption      VARCHAR(300)      NULL,
    uploaded_by  INT UNSIGNED      NULL,
    created_at   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY order_photos_order (order_id, created_at),
    CONSTRAINT order_photos_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT order_photos_staff FOREIGN KEY (uploaded_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files staff attach to an order: commercial invoice, packing list, B/L or AWB, QC report, other.
-- Private files (storage/private/documents). is_public = 0 keeps a file for staff only.
CREATE TABLE order_documents (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED NOT NULL,
    type        VARCHAR(30)  NOT NULL,
    title       VARCHAR(160) NOT NULL,
    path        VARCHAR(255) NOT NULL,
    mime        VARCHAR(60)  NOT NULL,
    size_bytes  INT UNSIGNED NOT NULL,
    is_public   TINYINT(1)   NOT NULL DEFAULT 1,
    uploaded_by INT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY order_documents_order (order_id, created_at),
    CONSTRAINT order_documents_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT order_documents_staff FOREIGN KEY (uploaded_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How the order travelled (one shipment per order).
CREATE TABLE shipments (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id     INT UNSIGNED NOT NULL,
    carrier      VARCHAR(120) NOT NULL,
    tracking_no  VARCHAR(120) NULL,
    tracking_url VARCHAR(500) NULL,
    shipped_on   DATE         NOT NULL,
    cartons      INT UNSIGNED NULL,
    gross_g      INT UNSIGNED NULL,
    note         VARCHAR(500) NULL,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY shipments_order (order_id),
    CONSTRAINT shipments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT shipments_staff FOREIGN KEY (created_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status_before_hold: where an order goes back to after a hold.
-- balance_invoice_no / balance_invoiced_at: the balance invoice issued when the order is ready to ship.
-- reminders_sent: payment reminders already sent for an unpaid order (the daily job sends 2, then cancels).
ALTER TABLE orders
    ADD COLUMN status_before_hold  VARCHAR(30)      NULL AFTER status,
    ADD COLUMN balance_invoice_no  VARCHAR(30)      NULL AFTER paid_cents,
    ADD COLUMN balance_invoiced_at DATETIME         NULL AFTER balance_invoice_no,
    ADD COLUMN reminders_sent      TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER balance_invoiced_at,
    ADD UNIQUE KEY orders_balance_invoice (balance_invoice_no);

-- received_cents / received_on: what actually arrived, entered by the admin who confirms the payment
-- (bank fees can make it less than the buyer reported). recorded_by_staff: entered by staff, not reported by the buyer.
ALTER TABLE order_payments
    ADD COLUMN received_cents    INT UNSIGNED NULL AFTER amount_cents,
    ADD COLUMN received_on       DATE         NULL AFTER transferred_on,
    ADD COLUMN recorded_by_staff TINYINT(1)   NOT NULL DEFAULT 0 AFTER notes;
