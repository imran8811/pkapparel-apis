-- Starting values, taken from the current pkapparel.com contact page.
-- TO CONFIRM with PK Apparel: phone/WhatsApp (a blog post links +92 301 9062877), legal name, hours.
INSERT INTO settings (name, value, is_public) VALUES
(
    'company',
    '{"name":"PK Apparel","legal_name":null,"tagline":"Denim manufacturer & exporter","founded_year":2015,"city":"Lahore","country":"Pakistan"}',
    1
),
(
    'contact',
    '{"phone":"+92 310 4489288","whatsapp":"923104489288","email":"info@pkapparel.com"}',
    1
),
(
    'addresses',
    '[{"label":"Factory","line":"Sufiabad Stop, 18-KM Ferozepur Road","city":"Lahore","country":"Pakistan"},{"label":"Liaison office","line":"Lucky Hotel Stop, 21-KM Ferozepur Road","city":"Lahore","country":"Pakistan"}]',
    1
),
(
    'social',
    '{"facebook":"https://www.facebook.com/pkapparel1","instagram":"https://www.instagram.com/pkapparel_official","tiktok":"https://www.tiktok.com/@pkapparel","linkedin":"https://www.linkedin.com/company/pkapparel-official"}',
    1
),
(
    'commerce',
    '{"currency":"USD","moq_per_color":100,"set_pcs_per_size":2,"deposit_percent":50,"lead_time_days":30,"sample_price_cents":5000,"shipping_modes":["air","sea","own_forwarder"]}',
    1
);
