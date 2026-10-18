-- Minimum pieces per size (owner rule 2026-10-17): every size ordered in a color needs at least this many pieces,
-- e.g. 20, so 100 pieces of a color cover at most 5 sizes. NULL: the business default (commerce.min_pcs_per_size).
ALTER TABLE products
    ADD COLUMN min_pcs_per_size SMALLINT UNSIGNED NULL AFTER set_pcs_per_size;
