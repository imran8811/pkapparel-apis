-- Starter content. Everything here can be edited later in the admin panel.
-- TO CONFIRM with PK Apparel: factory numbers (taken from the old factory page) and the legal page drafts.

INSERT INTO settings (name, value, is_public) VALUES
(
    'factory_facts',
    '{"monthly_capacity":"50,000–60,000 pcs","machines":"450–500","workers":"500–600","qa_staff":"50+","confirmed":false}',
    1
);

INSERT INTO faqs (category, question, answer, sort_order) VALUES
('ordering', 'What is your minimum order quantity (MOQ)?', 'MOQ is 100 pieces per style per color. Within a color you can mix sizes freely: enter a quantity for each size, or add ready-made sets (1 set = 2 pieces of every size).', 10),
('ordering', 'Do you keep stock?', 'No. Everything is made to order in our Lahore factory, so every order is fresh production in the sizes, colors and labels you choose.', 20),
('ordering', 'Can you make my own design?', 'Yes. Send us your tech pack, a reference sample or photos. We quote fabric, wash, trims and price, make a sample for your approval and then start bulk production.', 30),
('ordering', 'Do you offer private label?', 'Yes. We can add your woven labels, care labels, hang tags, leather or PU patches and branded buttons and rivets, and pack in your own polybags and cartons.', 40),
('production', 'How long does production take?', 'About 30 days from the day your deposit is confirmed. Custom developments need extra time for sampling, and very large orders may take longer. Your ready date is shown on your proforma invoice.', 10),
('production', 'Can I follow my order during production?', 'Yes. Your buyer account shows each stage (fabric, cutting, stitching, washing, quality check and packing) with photos from the factory floor.', 20),
('payment', 'How do I pay?', 'By bank transfer. After you place an order you receive our bank details and a proforma invoice. Pay a 50% deposit to start production and the 50% balance before shipment. After each transfer, enter the transaction ID on the Confirm payment page, and we email you a receipt as soon as the payment is confirmed.', 10),
('payment', 'Which currency do you use?', 'All prices are in US dollars (USD), per piece, ex-factory (FOB).', 20),
('shipping', 'How do you ship?', 'By air (usually 5 to 10 days in transit) or by sea (usually 20 to 45 days, depending on the destination). We deliver to your door or port, or hand the goods to your own forwarder.', 10),
('shipping', 'Who pays import duties and taxes?', 'The buyer pays duties and taxes at the destination, unless we agree otherwise in writing.', 20),
('shipping', 'Which countries do you ship to?', 'Worldwide. Most of our buyers are in the USA, the UK and Europe.', 30),
('samples', 'Can I order a sample first?', 'Yes. Samples of our catalog styles cost $50 per piece plus courier. Samples of your own design take about 2 weeks and are priced by design.', 10),
('quality', 'How do you control quality?', 'We check fabric before cutting, inspect garments during stitching, approve every wash against the standard, check measurements and finishing, and do a final inspection before packing. Your own or third-party inspectors are welcome.', 10);

INSERT INTO testimonials (author_role, country_code, quote, sort_order, is_published) VALUES
('Brand owner', 'US', 'We ordered 500 women’s jackets. Delivery was on time and the quality was good, so we placed a repeat order.', 10, 1),
('Store owner', 'DE', 'Awesome quality, better than China. Pakistani jeans have a real denim look and the price is good. We are a regular client of PK Apparel.', 20, 1),
('Amazon retailer', 'GB', 'Communication is a bit slow, but overall the denim quality and on-time delivery are good. We liked working with them.', 30, 1),
('Wholesaler', 'GB', 'The pants quality was awesome, but the shipment was two weeks late. We will try them again.', 40, 0);

INSERT INTO certifications (name, issuer, certificate_no, member_since, description, image_path, sort_order) VALUES
('Lahore Chamber of Commerce & Industry', 'LCCI', '111158-A', '2021-12-17', 'Registered member of the Lahore Chamber of Commerce & Industry, affiliated with the Federation of Pakistan Chambers of Commerce & Industry.', 'uploads/site/certificates/lcci-membership.jpg', 10),
('FBR registered exporter', 'Federal Board of Revenue, Pakistan', 'NTN 4096911-8', NULL, 'Registered with Pakistan’s Federal Board of Revenue for income tax and sales tax as an importer and exporter.', NULL, 20);

INSERT INTO factory_departments (slug, name, summary, stats, images, sort_order) VALUES
(
    'fabric-store', 'Fabric store',
    'Denim, twill and canvas from Pakistani mills are checked for shade, weight and width before they reach the cutting table.',
    '[{"label":"Fabric storage","value":"1.5M meters"}]',
    '[{"path":"uploads/site/factory/fabric-rolls.jpg","alt":"Rolls of denim fabric in the fabric store"},{"path":"uploads/site/factory/fabric-shelves.png","alt":"Fabric rolls sorted on shelves"}]',
    10
),
(
    'cutting', 'Cutting',
    'Markers are planned for each size ratio to keep waste low. Cut panels are bundled by size and shade so every pair matches.',
    '[{"label":"Cutting tables","value":"3"},{"label":"Daily capacity","value":"10,000 pcs"}]',
    '[{"path":"uploads/site/factory/cutting.jpg","alt":"Cutting denim panels with a straight-knife machine"}]',
    20
),
(
    'stitching', 'Stitching',
    'Dedicated lines for jeans, jackets, shorts, cargo and chino pants, with bar-tacks, rivets and buttons fitted in-house.',
    '[{"label":"Machines","value":"450–500"},{"label":"Workers","value":"500–600"},{"label":"Monthly capacity","value":"50,000–60,000 pcs"}]',
    '[{"path":"uploads/site/factory/stitching-floor.jpg","alt":"Stitching hall with sewing lines"},{"path":"uploads/site/factory/stitching-line.jpg","alt":"Sewing machines on a stitching line"},{"path":"uploads/site/factory/stitching-team.jpg","alt":"Stitching team at work on denim"}]',
    30
),
(
    'washing', 'Washing & finishing',
    'Our own laundry washes and finishes every lot in-house, and each shade is checked against the approved standard.',
    '[{"label":"Tumblers","value":"25"},{"label":"Washing machines","value":"25+"},{"label":"Rinsers","value":"10"},{"label":"Daily capacity","value":"10,000 pcs"}]',
    '[{"path":"uploads/site/factory/washing-unit.jpg","alt":"Washing unit with industrial washing machines"},{"path":"uploads/site/factory/washing-machine.jpg","alt":"Industrial denim washing machine"}]',
    40
),
(
    'quality', 'Quality control',
    'Measurements, stitching, buttonholes, rivets, metal buttons and snaps are checked piece by piece. Faulty pieces are repaired and inspected again.',
    '[{"label":"QA staff","value":"50+"},{"label":"Daily inspection","value":"10,000 pcs"}]',
    '[{"path":"uploads/site/factory/quality-check.jpg","alt":"Inspecting a pair of jeans"},{"path":"uploads/site/factory/finished-goods.jpg","alt":"Finished jeans on racks before packing"}]',
    50
),
(
    'packing', 'Packing & dispatch',
    'Garments are folded, tagged and packed to your size ratio, with your polybags and carton marks, ready for air or sea freight.',
    '[{"label":"Finished goods storage","value":"15,000 pcs"}]',
    '[{"path":"uploads/site/factory/packing-area.jpg","alt":"Packed jeans ready for cartons"},{"path":"uploads/site/factory/cartons.jpg","alt":"Export cartons ready for dispatch"}]',
    60
);

INSERT INTO pages (slug, title, body_html, seo_description) VALUES
(
    'terms-of-sale', 'Terms of sale',
    '<p>These terms apply to every order placed with PK Apparel (“we”, “us”) through this website or by email.</p>
<h2>1. Orders and confirmation</h2><p>An order is confirmed when we receive the deposit shown on the proforma invoice. Until then, prices and production slots are not reserved.</p>
<h2>2. Prices</h2><p>Prices are per piece in US dollars, ex-factory (FOB). They exclude freight, insurance, import duties and taxes unless the proforma invoice says otherwise.</p>
<h2>3. Payment</h2><p>Payment is by bank transfer only. A 50% deposit confirms the order and starts production. The remaining 50% is due before shipment. Bank charges on the sending side are paid by the buyer. Goods are released for shipment after the full amount is received.</p>
<h2>4. Production and delivery dates</h2><p>Production normally takes about 30 days from deposit confirmation. Ready dates are estimates and may change because of sample approvals, fabric availability, public holidays or events outside our control. We will tell you as soon as we know of a delay.</p>
<h2>5. Samples and approvals</h2><p>When a sample is approved, bulk production follows it within normal industry tolerances for shade, measurements and wash.</p>
<h2>6. Shipping and risk</h2><p>We ship by air or sea as chosen at checkout, or hand the goods to your forwarder. Risk passes to the buyer according to the agreed Incoterm. Import duties, taxes and clearance at the destination are paid by the buyer unless agreed otherwise in writing.</p>
<h2>7. Changes and cancellations</h2><p>Because every order is made for you, changes and cancellations are only possible before fabric is cut. After cutting starts, the deposit is non-refundable and covers the materials and work already done.</p>
<h2>8. Quality claims</h2><p>Please inspect the goods on arrival. Send claims for defects in writing, with photos, within 14 days of delivery. If a claim is accepted, we will repair, replace or credit the affected pieces.</p>
<h2>9. Liability</h2><p>Our total liability for any order is limited to the value of that order.</p>
<h2>10. Governing law</h2><p>These terms are governed by the laws of Pakistan, and the courts of Lahore have jurisdiction.</p>
<p>Questions about these terms? Email <a href="mailto:info@pkapparel.com">info@pkapparel.com</a>.</p>',
    'Terms of sale for wholesale, made-to-order orders from PK Apparel: payment, production dates, shipping, cancellations and quality claims.'
),
(
    'privacy', 'Privacy policy',
    '<p>This policy explains how PK Apparel (“we”) collects and uses personal information through this website.</p>
<h2>What we collect</h2><ul><li>Contact details you give us: name, company, email, phone or WhatsApp number and country.</li><li>Account and order information: business details, delivery addresses, order history and payment confirmations. We never receive your card or online banking details.</li><li>Technical information such as your IP address and browser, used for security and to prevent spam.</li></ul>
<h2>How we use it</h2><ul><li>To answer inquiries and prepare quotes and samples.</li><li>To process orders, payments, production updates and shipments.</li><li>To keep the website secure and prevent fraud.</li></ul><p>We do not sell your information.</p>
<h2>Who we share it with</h2><p>Only with the service providers needed to fulfil your order, such as freight forwarders, couriers and our bank, and with authorities when the law requires it.</p>
<h2>How long we keep it</h2><p>Inquiries are kept for up to 3 years. Order records are kept for as long as tax and accounting rules require.</p>
<h2>Your rights</h2><p>You can ask to see, correct or delete your personal information by emailing <a href="mailto:info@pkapparel.com">info@pkapparel.com</a>.</p>
<h2>Cookies</h2><p>See our <a href="/cookies">cookie policy</a>.</p>',
    'How PK Apparel collects, uses and protects personal information shared through this website.'
),
(
    'cookies', 'Cookie policy',
    '<p>This website only uses the cookies it needs to work.</p>
<h2>Essential cookies</h2><ul><li><strong>Login session</strong>: keeps you signed in to your buyer account.</li><li><strong>Cart</strong>: remembers the products in your cart.</li><li><strong>Security</strong>: protects forms against misuse.</li></ul><p>These cookies cannot be switched off, because the website cannot work without them.</p>
<h2>Analytics</h2><p>We do not use analytics or advertising cookies at the moment. If we add them, we will ask for your consent first and update this page.</p>',
    'The cookies used on the PK Apparel website.'
);
