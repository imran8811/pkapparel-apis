-- Cargo trousers and chino pants are men only (owner rule 2026-10-17), like denim skirts are women and girls only.
-- Their women's, boys' and girls' category pages go, with their texts. No styles or orders use these combinations.
DELETE FROM categories
WHERE product_type IN ('cargo-trouser', 'chino-pant') AND segment IN ('women', 'boys', 'girls');
