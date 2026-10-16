-- The company's tax number (FBR NTN), printed on proforma invoices. Editable in Admin → Settings → Company.
UPDATE settings SET value = JSON_SET(value, '$.ntn', '4096911-8') WHERE name = 'company';
