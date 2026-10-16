-- Buyer accounts (Phase 3). Login sessions reuse auth_tokens with user_type 'customer'.

CREATE TABLE customers (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email             VARCHAR(190) NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    email_verified_at DATETIME     NULL,
    company_name      VARCHAR(160) NOT NULL,
    contact_name      VARCHAR(120) NOT NULL,
    business_type     VARCHAR(30)  NOT NULL,
    country_code      CHAR(2)      NOT NULL,
    phone             VARCHAR(30)  NOT NULL,
    website           VARCHAR(255) NULL,
    status            VARCHAR(20)  NOT NULL DEFAULT 'active',
    staff_notes       TEXT         NULL,
    last_login_at     DATETIME     NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY customers_email (email),
    KEY customers_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery addresses; the one chosen at checkout is copied into the order.
CREATE TABLE customer_addresses (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id  INT UNSIGNED NOT NULL,
    company      VARCHAR(160) NOT NULL,
    contact_name VARCHAR(120) NOT NULL,
    phone        VARCHAR(30)  NOT NULL,
    email        VARCHAR(190) NULL,
    line1        VARCHAR(190) NOT NULL,
    line2        VARCHAR(190) NULL,
    city         VARCHAR(120) NOT NULL,
    state        VARCHAR(120) NULL,
    postal_code  VARCHAR(30)  NULL,
    country_code CHAR(2)      NOT NULL,
    tax_id       VARCHAR(60)  NULL,
    is_default   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY customer_addresses_customer (customer_id),
    CONSTRAINT customer_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use links for email verification and password reset. Only a SHA-256 hash is stored,
-- and a verification link only confirms the address it was sent to.
CREATE TABLE customer_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT UNSIGNED    NOT NULL,
    purpose     VARCHAR(20)     NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    email       VARCHAR(190)    NOT NULL,
    expires_at  DATETIME        NOT NULL,
    used_at     DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY customer_tokens_hash (token_hash),
    KEY customer_tokens_customer (customer_id, purpose),
    CONSTRAINT customer_tokens_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
