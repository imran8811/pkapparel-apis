-- 2026-10-15: PK Apparel confirmed the factory capacity as 30,000+ pieces a month.
-- The other numbers from the old factory page (machines, workers, daily capacities, storage)
-- are not confirmed and no longer match this capacity, so they are hidden until confirmed.

UPDATE settings
SET value = '{"monthly_capacity":"30,000+ pcs","confirmed":true}'
WHERE name = 'factory_facts';

UPDATE factory_departments
SET stats = '[{"label":"Monthly capacity","value":"30,000+ pcs"}]'
WHERE slug = 'stitching';

UPDATE factory_departments
SET stats = '[]'
WHERE slug IN ('fabric-store', 'cutting', 'washing', 'quality', 'packing');

-- PK Apparel's row in the "list of manufacturers" blog tables
UPDATE posts
SET body_html = REPLACE(body_html, '50,000–60,000 pcs/month', '30,000+ pcs/month')
WHERE body_html LIKE '%50,000–60,000 pcs/month%';
