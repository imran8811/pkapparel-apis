-- The old "Jeans manufacturers" post showed a wrong phone number as link text. The correct number is +92 310 4489288.
UPDATE posts
SET body_html = REPLACE(body_html, '+92 301 9062877', '+92 310 4489288')
WHERE body_html LIKE '%+92 301 9062877%';
