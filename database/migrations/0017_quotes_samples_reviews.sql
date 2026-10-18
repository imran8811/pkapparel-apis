-- Lead generation (Phase 5): files attached to quote requests and custom sample orders,
-- sample orders, and reviews of delivered orders.

-- Files buyers attach (tech packs, spec sheets, photos). Private files (storage/private/inquiries).
-- A file is uploaded on its own first and found by its upload token (inquiry_id NULL); sending the form
-- attaches it. Uploads never sent with a form are deleted by the daily job.
CREATE TABLE inquiry_files (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    inquiry_id        INT UNSIGNED NULL,
    upload_token_hash CHAR(64)     NOT NULL,
    name              VARCHAR(190) NOT NULL,
    mime              VARCHAR(100) NOT NULL,
    size_bytes        INT UNSIGNED NOT NULL,
    path              VARCHAR(255) NOT NULL,
    ip_address        VARCHAR(45)  NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY inquiry_files_token (upload_token_hash),
    KEY inquiry_files_inquiry (inquiry_id),
    KEY inquiry_files_created (created_at),
    CONSTRAINT inquiry_files_inquiry FOREIGN KEY (inquiry_id) REFERENCES inquiries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kind: "bulk" (the wholesale shop) or "sample" (samples, paid in full before dispatch).
-- priced_at: when the order became payable. Sample orders that staff must price first get it later;
-- the daily job counts payment reminders from it.
ALTER TABLE orders
    ADD COLUMN kind      VARCHAR(10) NOT NULL DEFAULT 'bulk' AFTER order_no,
    ADD COLUMN priced_at DATETIME    NULL AFTER placed_at,
    ADD KEY orders_kind (kind, status);

UPDATE orders SET priced_at = placed_at;

-- A review of a delivered order. The row is created when the review is requested (token_hash: the emailed link,
-- stored hashed); the buyer fills in rating and body. Nothing is shown before staff approve it.
-- show_as: how the buyer is named: "company" (company name) or "business" (business type and country).
-- testimonial_id: the home page review made from it, if staff featured it.
CREATE TABLE reviews (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    order_id       INT UNSIGNED     NOT NULL,
    token_hash     CHAR(64)         NULL,
    requested_at   DATETIME         NULL,
    rating         TINYINT UNSIGNED NULL,
    body           TEXT             NULL,
    show_as        VARCHAR(20)      NOT NULL DEFAULT 'business',
    status         VARCHAR(20)      NOT NULL DEFAULT 'requested',
    submitted_at   DATETIME         NULL,
    reviewed_by    INT UNSIGNED     NULL,
    reviewed_at    DATETIME         NULL,
    testimonial_id INT UNSIGNED     NULL,
    created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY reviews_order (order_id),
    UNIQUE KEY reviews_token (token_hash),
    KEY reviews_status (status, submitted_at),
    CONSTRAINT reviews_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT reviews_staff FOREIGN KEY (reviewed_by) REFERENCES staff_users (id) ON DELETE SET NULL,
    CONSTRAINT reviews_testimonial FOREIGN KEY (testimonial_id) REFERENCES testimonials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
