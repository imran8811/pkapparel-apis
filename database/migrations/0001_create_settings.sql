-- Site-wide settings as JSON values. Public ones are served to the website by GET /v1/site.
CREATE TABLE settings (
    name       VARCHAR(100) NOT NULL,
    value      JSON         NOT NULL,
    is_public  TINYINT(1)   NOT NULL DEFAULT 0,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
