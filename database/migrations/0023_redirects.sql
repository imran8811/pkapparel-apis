-- Phase 6: redirects managed in the admin, and the addresses visitors hit that do not exist.

-- Old addresses that point to a page of the site (or another website). Staff add them; changing a style's or a
-- post's address adds one automatically. The website follows them with a permanent (308) redirect.
CREATE TABLE redirects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_path VARCHAR(255) NOT NULL,
    to_path VARCHAR(500) NOT NULL,
    -- staff: added in the admin; style / post: the address of a style or post changed
    source VARCHAR(16) NOT NULL DEFAULT 'staff',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY redirects_from (from_path),
    KEY redirects_to (to_path(191)),
    CONSTRAINT redirects_staff FOREIGN KEY (created_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Addresses that showed the "page not found" page, reported by visitors' browsers, so staff can redirect them.
CREATE TABLE missing_pages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    path VARCHAR(255) NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 1,
    last_referrer VARCHAR(255) NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    ignored_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY missing_pages_path (path),
    KEY missing_pages_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
