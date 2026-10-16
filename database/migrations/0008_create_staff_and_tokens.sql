-- Staff accounts for the admin panel, login tokens (staff now, buyers later), and inquiry follow-up notes.

CREATE TABLE staff_users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'editor',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY staff_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only a SHA-256 hash of each token is stored; the token itself is given to the client once.
CREATE TABLE auth_tokens (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_type    VARCHAR(20)     NOT NULL,
    user_id      INT UNSIGNED    NOT NULL,
    token_hash   CHAR(64)        NOT NULL,
    expires_at   DATETIME        NOT NULL,
    last_used_at DATETIME        NULL,
    ip_address   VARCHAR(45)     NULL,
    user_agent   VARCHAR(255)    NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY auth_tokens_hash (token_hash),
    KEY auth_tokens_user (user_type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE inquiries
    ADD COLUMN notes      TEXT         NULL AFTER status,
    ADD COLUMN handled_by INT UNSIGNED NULL AFTER notes;
