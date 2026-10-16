-- Order of the shop's category pages in menus and lists.
ALTER TABLE categories ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER seo_description;
