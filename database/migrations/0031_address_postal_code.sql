-- The company's addresses get a post code, printed after the city on invoices, receipts and emails:
-- "Sufiabad Stop, 18-KM Ferozepur Road, Lahore 54000, Pakistan" (owner's request, 2026-11-14).
-- Editable in Admin → Settings → Addresses. Only Lahore addresses without a post code are changed (at most 4 addresses).
UPDATE settings SET value = JSON_SET(value, '$[0].postal_code', '54000')
WHERE name = 'addresses' AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(value, '$[0].city'))) = 'lahore' AND JSON_EXTRACT(value, '$[0].postal_code') IS NULL;

UPDATE settings SET value = JSON_SET(value, '$[1].postal_code', '54000')
WHERE name = 'addresses' AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(value, '$[1].city'))) = 'lahore' AND JSON_EXTRACT(value, '$[1].postal_code') IS NULL;

UPDATE settings SET value = JSON_SET(value, '$[2].postal_code', '54000')
WHERE name = 'addresses' AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(value, '$[2].city'))) = 'lahore' AND JSON_EXTRACT(value, '$[2].postal_code') IS NULL;

UPDATE settings SET value = JSON_SET(value, '$[3].postal_code', '54000')
WHERE name = 'addresses' AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(value, '$[3].city'))) = 'lahore' AND JSON_EXTRACT(value, '$[3].postal_code') IS NULL;
