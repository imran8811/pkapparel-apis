-- The fabric shelves photo had transparent padding around it; it is now cropped and saved as JPEG.
UPDATE factory_departments
SET images = '[{"path":"uploads/site/factory/fabric-rolls.jpg","alt":"Rolls of denim fabric in the fabric store"},{"path":"uploads/site/factory/fabric-shelves.jpg","alt":"Fabric rolls sorted on shelves"}]'
WHERE slug = 'fabric-store';
