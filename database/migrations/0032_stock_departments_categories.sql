-- Stock departments and categories (owner request 2026-11-17): every stock listing is in a department (Men, Women,
-- Boys) and a category (Jeans, Cotton pants). Staff add, rename and order both in Admin → Stock categories. A category's
-- listings have the details (specs) of one of the wholesale shop's product types: jeans have those of jeans pants,
-- cotton pants those of chino pants. Until now the stock was men's and boys' jeans only: every listing so far is jeans.
--
-- A listing keeps its department's slug in stock_styles.segment (as before: men or boys) and its category's slug in
-- stock_styles.category. Slugs are part of the stock pages' addresses (/stock/boys, ?category=cotton-pants), so they
-- never change once made; names can. The foreign keys stop a department or category with listings from being deleted.
--
-- Safe to import again: the tables, rows, column and keys are only added when they are missing.

CREATE TABLE IF NOT EXISTS stock_departments (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug       VARCHAR(20)  NOT NULL,
    name       VARCHAR(60)  NOT NULL,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY stock_departments_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- product_type: the wholesale product type (App\Models\ProductTypes) whose specs the category's listings have.
CREATE TABLE IF NOT EXISTS stock_categories (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug         VARCHAR(40)  NOT NULL,
    name         VARCHAR(60)  NOT NULL,
    product_type VARCHAR(40)  NOT NULL,
    sort_order   INT          NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY stock_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Men stays first: /stock itself lists the men's department (owner decision 2026-11-09).
INSERT INTO stock_departments (slug, name, sort_order) VALUES
('men', 'Men', 10),
('women', 'Women', 20),
('boys', 'Boys', 30)
ON DUPLICATE KEY UPDATE slug = slug;

-- Any other department a listing has (none is expected), so the foreign key below can be added.
INSERT INTO stock_departments (slug, name, sort_order)
SELECT DISTINCT s.segment, CONCAT(UPPER(LEFT(s.segment, 1)), SUBSTRING(s.segment, 2)), 100
FROM stock_styles s
WHERE NOT EXISTS (SELECT 1 FROM stock_departments d WHERE d.slug = s.segment);

INSERT INTO stock_categories (slug, name, product_type, sort_order) VALUES
('jeans', 'Jeans', 'jeans-pant', 10),
('cotton-pants', 'Cotton pants', 'chino-pant', 20)
ON DUPLICATE KEY UPDATE slug = slug;

-- Every listing so far is jeans.
SET @step := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE stock_styles ADD COLUMN category VARCHAR(40) NOT NULL DEFAULT ''jeans'' AFTER segment', 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_styles' AND COLUMN_NAME = 'category');
PREPARE migration_step FROM @step;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;

SET @step := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE stock_styles ADD CONSTRAINT stock_styles_department FOREIGN KEY (segment) REFERENCES stock_departments (slug)', 'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_styles' AND CONSTRAINT_NAME = 'stock_styles_department' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
PREPARE migration_step FROM @step;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;

SET @step := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE stock_styles ADD CONSTRAINT stock_styles_category FOREIGN KEY (category) REFERENCES stock_categories (slug)', 'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_styles' AND CONSTRAINT_NAME = 'stock_styles_category' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
PREPARE migration_step FROM @step;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;
