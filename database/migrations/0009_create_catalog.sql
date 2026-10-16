-- Catalog: size charts, shop category pages, styles (products), their colours, photos and optional volume price tiers.
-- A style is one cut (one product page); each colour has its own code (the old article number), photos and,
-- optionally, its own price and fabric details. Order rules left empty fall back to the "commerce" settings.

CREATE TABLE size_charts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(120) NOT NULL,
    unit         VARCHAR(10)  NOT NULL DEFAULT 'in',
    measurements JSON         NOT NULL,
    size_rows    JSON         NOT NULL,
    note         VARCHAR(500) NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Text for /wholesale-shop/{segment} (product_type = '') and /wholesale-shop/{segment}/{type}.
CREATE TABLE categories (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    segment         VARCHAR(20)  NOT NULL,
    product_type    VARCHAR(40)  NOT NULL DEFAULT '',
    title           VARCHAR(160) NOT NULL,
    intro_html      MEDIUMTEXT   NOT NULL,
    faq             JSON         NOT NULL,
    seo_title       VARCHAR(200) NULL,
    seo_description VARCHAR(320) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY categories_segment_type (segment, product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug               VARCHAR(190) NOT NULL,
    name               VARCHAR(200) NOT NULL,
    style_no           VARCHAR(40)  NULL,
    segment            VARCHAR(20)  NOT NULL,
    product_type       VARCHAR(40)  NOT NULL,
    fit                VARCHAR(60)  NULL,
    fabric             VARCHAR(120) NULL,
    composition        VARCHAR(120) NULL,
    fabric_weight      VARCHAR(40)  NULL,
    stretch            VARCHAR(20)  NULL,
    wash               VARCHAR(120) NULL,
    closure            VARCHAR(60)  NULL,
    description_html   MEDIUMTEXT   NOT NULL,
    features           JSON         NOT NULL,
    sizes              JSON         NOT NULL,
    size_chart_id      INT UNSIGNED NULL,
    price_cents        INT UNSIGNED NOT NULL,
    moq_per_color      INT UNSIGNED NULL,
    set_pcs_per_size   INT UNSIGNED NULL,
    lead_time_days     INT UNSIGNED NULL,
    sample_price_cents INT UNSIGNED NULL,
    piece_weight_g     INT UNSIGNED NULL,
    pcs_per_carton     INT UNSIGNED NULL,
    carton_length_mm   INT UNSIGNED NULL,
    carton_width_mm    INT UNSIGNED NULL,
    carton_height_mm   INT UNSIGNED NULL,
    carton_weight_g    INT UNSIGNED NULL,
    seo_title          VARCHAR(200) NULL,
    seo_description    VARCHAR(320) NULL,
    status             VARCHAR(20)  NOT NULL DEFAULT 'draft',
    is_featured        TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order         INT          NOT NULL DEFAULT 0,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY products_slug (slug),
    KEY products_listing (status, segment, product_type, sort_order),
    CONSTRAINT products_size_chart FOREIGN KEY (size_chart_id) REFERENCES size_charts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_colors (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id        INT UNSIGNED NOT NULL,
    code              VARCHAR(40)  NOT NULL,
    name              VARCHAR(80)  NOT NULL,
    slug              VARCHAR(80)  NOT NULL,
    hex               CHAR(7)      NULL,
    price_cents       INT UNSIGNED NULL,
    spec_overrides    JSON         NOT NULL,
    legacy_article_no VARCHAR(40)  NULL,
    is_active         TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order        INT          NOT NULL DEFAULT 0,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY product_colors_code (code),
    UNIQUE KEY product_colors_slug (product_id, slug),
    KEY product_colors_legacy (legacy_article_no),
    CONSTRAINT product_colors_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    color_id   INT UNSIGNED NULL,
    path       VARCHAR(255) NOT NULL,
    alt        VARCHAR(200) NOT NULL,
    width      INT UNSIGNED NOT NULL,
    height     INT UNSIGNED NOT NULL,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY product_images_color (color_id, sort_order),
    CONSTRAINT product_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT product_images_color FOREIGN KEY (color_id) REFERENCES product_colors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cheaper per piece from a total quantity of the style (all colours together).
CREATE TABLE product_price_tiers (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  INT UNSIGNED NOT NULL,
    min_qty     INT UNSIGNED NOT NULL,
    price_cents INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY product_price_tiers_qty (product_id, min_qty),
    CONSTRAINT product_price_tiers_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
