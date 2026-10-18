-- Samples (owner decision 2026-10-17): $100 per piece plus courier, and the sample cost is deducted from the
-- buyer's first bulk order once that order is confirmed.

-- The new sample price, unless staff already changed it from the old default.
UPDATE settings
SET value = JSON_SET(value, '$.sample_price_cents', 10000)
WHERE name = 'commerce' AND JSON_EXTRACT(value, '$.sample_price_cents') = 5000;

-- The samples FAQ, only where it still has the original text.
UPDATE faqs
SET answer = 'Yes. Samples of our catalog styles cost $100 per piece plus courier, and you can order them in the shop. The sample cost is deducted from your first bulk order once it is confirmed. Samples of your own design take about 2 weeks and are priced by design.'
WHERE answer = 'Yes. Samples of our catalog styles cost $50 per piece plus courier. Samples of your own design take about 2 weeks and are priced by design.';

-- credit_cents (bulk orders): sample costs deducted from this order's balance.
-- credited_to_order_id (sample orders): the bulk order its cost was deducted from.
ALTER TABLE orders
    ADD COLUMN credit_cents         INT UNSIGNED NOT NULL DEFAULT 0 AFTER paid_cents,
    ADD COLUMN credited_to_order_id INT UNSIGNED NULL AFTER credit_cents,
    ADD CONSTRAINT orders_credited_to FOREIGN KEY (credited_to_order_id) REFERENCES orders (id) ON DELETE SET NULL;
