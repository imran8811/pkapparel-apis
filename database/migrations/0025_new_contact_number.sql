-- New company phone/WhatsApp number (owner request 2026-11-04). Editable in Admin → Settings → Contact.
UPDATE settings
SET value = JSON_SET(value, '$.phone', '+92 3 000-911-000', '$.whatsapp', '923000911000')
WHERE name = 'contact';

-- Imported blog posts link the old number as text and as a tel: link.
UPDATE posts
SET body_html = REPLACE(REPLACE(body_html, '+92 310 4489288', '+92 3 000-911-000'), 'tel:+923104489288', 'tel:+923000911000'),
    updated_at = UTC_TIMESTAMP()
WHERE body_html LIKE '%+92 310 4489288%' OR body_html LIKE '%tel:+923104489288%';
