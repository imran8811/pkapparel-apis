-- Marketing content, blog, simple pages, website inquiries and rate limiting.

CREATE TABLE faqs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category     VARCHAR(40)  NOT NULL,
    question     VARCHAR(300) NOT NULL,
    answer       TEXT         NOT NULL,
    sort_order   INT          NOT NULL DEFAULT 0,
    is_published TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY faqs_category_sort (category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE testimonials (
    id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    author_name  VARCHAR(120)     NULL,
    author_role  VARCHAR(120)     NOT NULL,
    company      VARCHAR(160)     NULL,
    country_code CHAR(2)          NULL,
    quote        TEXT             NOT NULL,
    rating       TINYINT UNSIGNED NULL,
    sort_order   INT              NOT NULL DEFAULT 0,
    is_published TINYINT(1)       NOT NULL DEFAULT 1,
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certifications (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(160) NOT NULL,
    issuer         VARCHAR(160) NULL,
    certificate_no VARCHAR(80)  NULL,
    member_since   DATE         NULL,
    valid_until    DATE         NULL,
    description    VARCHAR(500) NULL,
    image_path     VARCHAR(255) NULL,
    sort_order     INT          NOT NULL DEFAULT 0,
    is_published   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE factory_departments (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug         VARCHAR(60)  NOT NULL,
    name         VARCHAR(120) NOT NULL,
    summary      TEXT         NOT NULL,
    stats        JSON         NOT NULL,
    images       JSON         NOT NULL,
    sort_order   INT          NOT NULL DEFAULT 0,
    is_published TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY factory_departments_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE posts (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug             VARCHAR(190) NOT NULL,
    title            VARCHAR(200) NOT NULL,
    excerpt          VARCHAR(500) NOT NULL DEFAULT '',
    body_html        MEDIUMTEXT   NOT NULL,
    cover_image_path VARCHAR(255) NULL,
    cover_image_alt  VARCHAR(200) NULL,
    author_name      VARCHAR(120) NOT NULL DEFAULT 'PK Apparel',
    status           VARCHAR(20)  NOT NULL DEFAULT 'draft',
    published_at     DATETIME     NULL,
    seo_title        VARCHAR(200) NULL,
    seo_description  VARCHAR(320) NULL,
    legacy_path      VARCHAR(255) NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY posts_slug (slug),
    KEY posts_status_published (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pages (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(120) NOT NULL,
    title           VARCHAR(200) NOT NULL,
    body_html       MEDIUMTEXT   NOT NULL,
    seo_title       VARCHAR(200) NULL,
    seo_description VARCHAR(320) NULL,
    is_published    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY pages_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inquiries (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type         VARCHAR(20)  NOT NULL,
    name         VARCHAR(120) NOT NULL,
    company      VARCHAR(160) NULL,
    email        VARCHAR(190) NOT NULL,
    phone        VARCHAR(40)  NULL,
    country_code CHAR(2)      NULL,
    message      TEXT         NOT NULL,
    details      JSON         NULL,
    source_url   VARCHAR(500) NULL,
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'new',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY inquiries_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket       VARCHAR(190) NOT NULL,
    hits         INT UNSIGNED NOT NULL,
    window_start DATETIME     NOT NULL,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
