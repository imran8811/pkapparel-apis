-- The MOQ answer gains the minimum per size (owner rule 2026-10-17). Only the original text is replaced,
-- so an answer staff have edited in the admin stays as it is.
UPDATE faqs
SET answer = 'MOQ is 100 pieces per style per color, with at least 20 pieces in each size you order: 100 pieces cover up to 5 sizes, and more sizes need more pieces. Enter a quantity for each size, or add ready-made sets (1 set = 2 pieces of every size).'
WHERE answer = 'MOQ is 100 pieces per style per color. Within a color you can mix sizes freely: enter a quantity for each size, or add ready-made sets (1 set = 2 pieces of every size).';
