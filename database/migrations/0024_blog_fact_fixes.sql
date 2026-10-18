-- Phase 6: two sentences of the imported blog posts stated wrong facts (owner decision 2026-10-17: correct the facts and
-- state the current rules). REPLACE changes only the exact old sentence, so a post edited in the admin keeps its edits.
-- bin/import-legacy-posts.php makes the same corrections when the posts are imported after this migration.

UPDATE posts
SET body_html = REPLACE(
        body_html,
        'As the best jeans manufacturer in the USA, we deliver superb quality jeans with skillful stitching.',
        'From our factory in Lahore, we make jeans for brands in the USA, Europe and beyond, with skillful stitching and consistent quality.'
    ),
    updated_at = UTC_TIMESTAMP()
WHERE slug = 'jeans-manufacturers'
  AND body_html LIKE '%As the best jeans manufacturer in the USA, we deliver superb quality jeans with skillful stitching.%';

UPDATE posts
SET body_html = REPLACE(
        body_html,
        'At PK Apparel, we work with brands to accommodate flexible order quantities while keeping the ',
        'At PK Apparel, the minimum is 100 pieces per color, with at least 20 of each size, while keeping the '
    ),
    updated_at = UTC_TIMESTAMP()
WHERE slug = 'jeans-manufacturing-cost'
  AND body_html LIKE '%At PK Apparel, we work with brands to accommodate flexible order quantities while keeping the %';
