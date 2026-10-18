# PK Apparel API

The REST API behind pkapparel.com: plain PHP 8.2+ (no framework) on MySQL.
It holds everything the website shows or saves: site settings, marketing content, the blog, the wholesale catalog, inquiries with their attachments, buyer accounts, carts, orders (bulk and sample) with their payments, production and shipping, buyer reviews, and staff accounts.

**Only the website talks to it.** The Next.js website (the separate `web` repository) calls it from its server.
Every request needs the shared `X-Client-Key`. Buyer account routes also need a buyer login token, and admin routes a staff login token. Browsers never call this API directly.

## Requirements

- **PHP 8.2+** (8.3 recommended) with these extensions:
  - `pdo_mysql`, `mbstring`, `intl`, `gd`, `curl`, `dom` and `openssl`
  - `zip` (checks Excel and Word files attached to quote requests)
  - `exif` (optional, used to turn photos upright)
- **MySQL 8+ or MariaDB 10.4+**, utf8mb4
- **Composer**
- **A web server** whose document root is `public/` (Apache with `public/.htaccess`, or LiteSpeed)

## Set up locally

1. Install the dependencies: `composer install`
2. Copy `.env.example` to `.env` and fill it in.
3. Create the database and its user, then run `php bin/migrate.php`.
4. Serve `public/`:
   - With XAMPP: an Apache vhost on port 8080 (see [Local XAMPP setup](#local-xampp-setup)).
   - Without Apache, for a quick test: `php -S 127.0.0.1:8099 -t public public/index.php`
5. Check that http://127.0.0.1:8080/v1/health reports `"status":"ok"`.

## Commands

All of these run from this folder.

| Task | Command |
|---|---|
| Apply new database migrations | `php bin/migrate.php` |
| Run the tests | `composer test` (unit and integration tests; the integration tests use the local database) |
| Admin accounts | `php bin/create-staff.php` (see [Staff accounts](#staff-accounts)) |
| Daily order emails and clean-up | `php bin/daily.php`, once a day from cron (see [Daily job](#daily-job-cron)) |
| Send a test email | `php bin/send-test-email.php you@example.com` |
| Refresh the website's cache | `php bin/revalidate.php <tag> …` (see the tags below) |
| Import the old blog posts | `php bin/import-legacy-posts.php` |
| Import the old shop's products | `php bin/import-legacy-products.php` |

**The import scripts** add only what is missing. `--dry-run` shows what they would do. `--overwrite` also replaces items imported before, which **loses edits made in the admin**.
They read the old site's files and database, set in `LEGACY_*` in `.env`, and never change them.

**Website cache tags:**
- `site`
- `faqs`, `testimonials`, `certifications`, `factory`
- `posts`, `post:<slug>`, `pages`, `page:<slug>`
- `products`, `product:<slug>`, `categories`
- `redirects`

## Staff accounts

Staff log in to the website's admin panel at `/admin`. Admins manage accounts there (*Staff accounts*: add, change role, switch off, new password). The first admin account is created here:

```
php bin/create-staff.php --name="Your Name" --email=you@example.com --role=admin
```

- A new account gets a generated password, printed once. Change it under *My account* after logging in.
- `--reset-password --email=…` gives an account a new password and logs it out everywhere.
- `--deactivate` / `--activate` turn an account off or on. A deactivated account is locked out immediately.
- `--list` shows all accounts.

| Role | Can use |
|---|---|
| `admin` | everything: the only role that confirms, rejects or records payments; company settings, bank details and staff accounts |
| `editor` | website content: blog, pages, FAQs, reviews, certificates, factory, shop styles, size charts and shop category texts |
| `sales` | orders (every step except payments), the payment queue (read only), customers, inquiries, shipping rates and add-ons |
| `production` | orders: production stages, photos and notes |

## How it is built

- **Request flow:** `public/index.php` → `App\Core\App` → Router (`routes/api.php`) → middleware → controller → models (PDO).
  - Services are created by a small autowiring container.
  - Middleware: `RequireClientKey` checks the client key; `RequireStaff` checks the admin login; `RequireCustomer` checks a buyer login; `IdentifyBuyer` (cart routes) accepts a buyer login or a guest's cart token (`X-Cart-Token`).
- **Responses:**
  - success: `{"data": …, "meta": …}`
  - errors: `{"error": {"code", "message", "fields"}}`
  - Input is checked with `App\Core\Validator`.
- **Units:** money in integer cents (USD), weights in grams, sizes in millimetres, times in UTC.
- **Database changes** are numbered SQL files in `database/migrations`.
  - Never edit one that has been applied anywhere; add a new one.
  - SQL must work on both MySQL 8 and MariaDB 10.4.
- **Rich text** (posts, pages, shop texts) is cleaned by `App\Core\HtmlSanitizer` before it is stored.
- **Logins** are random tokens. Only their SHA-256 hash is stored (`auth_tokens`), and login is rate-limited. Staff sessions last 7 days, buyer sessions 30 days.
- **After each admin change**, `App\Services\WebRevalidator` calls the website's `POST /api/revalidate` with the matching cache tags, so the website shows the change at once.

### Buyers, cart and orders

- **Accounts** (`/v1/account/*`): signup, login, email confirmation and password reset by single-use emailed links (`customer_tokens`, stored hashed), profile, password, address book. Placing an order needs a confirmed email address.
- **Cart** (`/v1/cart`): one line per color of a style, with a quantity per size. It is always priced from the catalog (`App\Services\CartPricing`): color or style price, volume tiers across all colors of a style, the minimum per color, the minimum per size (`commerce.min_pcs_per_size`, 20 by default, or the style's own), and colors no longer sold. A guest's cart is merged into the account at login or signup.
- **Freight estimates** (`App\Services\Freight`): staff keep zones of countries with air rates (per chargeable kg), sea rates (per CBM) and courier rates for samples (per piece) in rate bands; the cheapest band applies. Packing comes from each style, or the `commerce.packing` defaults. Without a rate, checkout says the freight will be quoted.
- **Orders** (`App\Services\OrderService`):
  - Placing an order freezes copies of the lines, prices, buyer and address, empties the cart and emails the buyer the **proforma invoice** (PDF), with the bank details, and staff a summary.
  - Order numbers look like `PK-261016-482` (Pakistan date plus a random code).
  - The deposit (`commerce.deposit_percent`, 50%) covers the goods and priced add-ons. Freight is an estimate until staff confirm it and is paid with the balance, together with "price on request" add-ons.
- **Payment reports:** the buyer reports each transfer (transaction ID, amount, date, sender, optional slip). A transaction ID can be used only once (until the payment is rejected).
- **Bank details** are the private `bank` setting: never in `/v1/site`, and only shown to the buyer of an order with an amount due.
- **PDFs** are made with dompdf from `templates/pdf/` (DejaVu Sans; the logo is `templates/pdf/logo.png`): the proforma invoice, the balance invoice and receipts. They are made when needed from the order's current figures, not stored. The font cache lives in `storage/cache/dompdf`.

### Order management (`/v1/admin/orders/*`, `/v1/admin/payments`)

- **Payments** (`App\Services\PaymentReview`, admins only):
  - Confirming records the amount that arrived, gives a receipt number (`RCPT-2026-00001`) and emails the buyer the receipt PDF.
  - A confirmed deposit makes the order **Confirmed** and sets the production due date (today plus the lead time). A short deposit waits unless the admin starts production anyway.
  - Rejecting emails the buyer the reason and frees the transaction ID. Staff can also record a transfer the buyer did not report.
- **Workflow** (`App\Services\OrderWorkflow`): hold and resume, cancel, a new due date, the final freight and prices for "on request" add-ons (one total per order), ready to ship, shipped, delivered, internal notes.
  - **Ready to ship** issues the balance invoice (`INV-2026-00001`) and emails it. It needs the freight (the estimate is used if none was entered) and every add-on priced, and marks unfinished production stages done.
  - **Shipping** needs the full payment, or a reason that is kept in the history.
- **Production** (`App\Services\Production`): seven stages with start and done dates and a note, and photos with a preview copy. Starting the first stage moves the order to *In production*. Updates can email the buyer.
- **Documents** (`App\Services\OrderDocuments`): staff upload the commercial invoice, packing list, B/L or AWB, QC report and other files (PDF or photo), each shared with the buyer or kept for staff.
- **Receipt and invoice numbers** count up per year in `document_sequences`.
- **Every step** is written to the order history (`order_events`) with who did it. The buyer sees only the steps meant for them.
- **Times:** due dates and "late" use the Pakistan date (`Asia/Karachi`).

### Quote requests, samples and reviews

- **Files on quote requests:** the website uploads each file alone to `POST /v1/inquiry-files` (at most 4 MB, 30 per hour per visitor).
  - Accepted: PDF (Illustrator files saved with PDF content count too), Excel and Word (`.xlsx`, `.docx`; files with macros are refused) and JPG or PNG. Files are checked by their content.
  - The answer is a token. The form sends up to 5 tokens as `files` with `POST /v1/inquiries` (`App\Services\InquiryAttachments`).
  - Staff download the files with `GET /v1/admin/inquiry-files/{id}`. Uploads never sent with a form are deleted by the daily job after 24 hours.
- **Sample orders** (`orders.kind = 'sample'`, `App\Services\SampleOrders`):
  - The buyer sees the price with `POST /v1/account/sample-orders/quote` and orders with `POST /v1/account/sample-orders`.
  - Up to 10 pieces, at most 3 of each color and size. The minimum per size does not apply.
  - The price is each style's sample price (`commerce.sample_price_cents`, $100 by default) plus the courier from the zone's courier rate. It is paid in full before dispatch: no deposit, production stages or balance invoice.
  - Without a courier rate for the country, or with a custom-design sample (an "on request" line, with the buyer's files as `design` documents), the order waits as *Waiting for our price*. Staff enter the charges, then `POST /v1/admin/orders/{id}/send-price` emails the sample invoice. Reminders count from that moment (`priced_at`).
  - Once paid, samples ship straight away. The ready time is `commerce.sample_lead_time_days` (7), or `custom_sample_lead_time_days` (14) with a custom design.
- **Sample credit:** when the deposit of a buyer's bulk order is confirmed, their paid sample orders that were not deducted yet are deducted from it.
  - Only the samples count, not the courier. The amount is stored in `orders.credit_cents`, and each sample order gets `credited_to_order_id`.
  - The order total, the balance invoice and the PDFs show the deduction.
- **Reviews** (`App\Services\Reviews`, table `reviews`):
  - The daily job emails a review link 7 days after a bulk order is delivered (once; the link works for 90 days): `GET`/`POST /v1/reviews/{token}`.
  - Buyers can also write one from the order page: `/v1/account/orders/{orderNo}/review`.
  - New reviews wait for staff (`GET /v1/admin/reviews?status=pending`, then `POST …/{id}/approve` or `…/hide`).
  - Approved reviews appear in `GET /v1/products/{slug}` (`reviews`) for every style in the order, and those product pages are refreshed.
  - `…/{id}/feature` copies an approved review into the home page reviews (`testimonials`). Hiding the review deletes that copy.
- **Product types:** cargo trousers and chino pants are for men only, denim skirts for women and girls only (`App\Models\Product::ONLY_IN`; the website's `lib/nav.ts` mirrors it).

### Redirects and the launch checklist

- **Redirects** (`App\Services\Redirects`, table `redirects`): old addresses that lead to a page of the website, or to another website.
  - The website reads them all with `GET /v1/redirects` (cache tag `redirects`) and follows them only where no page exists. A redirect may lead to an address that redirects again; the website sends visitors straight to the end of the chain.
  - Staff manage them with `GET`/`POST /v1/admin/redirects` and `PUT`/`DELETE /v1/admin/redirects/{id}` (editors and admins).
  - Addresses are stored as paths: decoded, without a trailing slash, compared without regard to case. A pasted full link keeps its path.
  - Refused: the website's own areas (`/admin`, `/account`, `/checkout` and others), the home page, an address where a style or published post lives, and redirects that would go in a circle.
  - **Automatic redirects:** changing the address of a style that was in the shop, or of a published post, adds a redirect from the old address.
- **Missing addresses** (table `missing_pages`): the website's "page not found" page reports each address with `POST /v1/missing-pages`.
  - At most 30 reports per visitor and hour. Bot probes (`.php`, `/wp-…`, dot files) and the website's own areas are dropped.
  - Staff see them most visited first (`GET /v1/admin/missing-pages?status=open|ignored`) and can redirect or ignore them (`POST /v1/admin/missing-pages/{id}/ignore` or `/restore`). Adding a redirect takes the address off the list.
  - The daily job deletes addresses nobody has hit for 90 days.
- **Old shop links:** the old site's sitemap numbered its product links 10051–10076 instead of using the article numbers, and Google indexed them. `App\Services\Catalog::legacy()` maps those numbers to their articles.
- **Launch checklist** (`App\Services\LaunchChecklist`, admins only): `GET /v1/admin/launch-checklist` lists what is still missing before the shop takes orders.
  - Most items are checked from the settings and data: legal name, bank details, freight and courier rates, add-on prices, email sending, an admin account with a real email, current certificates, factory numbers, the daily job running.
  - The rest are ticked by hand with `PUT /v1/admin/launch-checklist/{key}` (`{done}`), stored in the `launch_checklist` setting.

| Folder | What it contains |
|---|---|
| `public/` | `index.php` (the only entry point), `.htaccess`, `uploads/` |
| `src/` | `Core/`, `Controllers/` (`Admin/` for the admin panel), `Middleware/`, `Models/`, `Services/` |
| `routes/api.php` | every route |
| `config/app.php` | settings, read from `.env` |
| `database/migrations/` | numbered SQL files |
| `templates/` | email templates (`email/`) and the PDFs (`pdf/`: proforma and sample invoice, balance invoice, receipt) |
| `bin/` | command-line scripts |
| `tests/` | PHPUnit tests |
| `storage/` | logs, cache and private files (`private/payment-slips`, `private/documents`, `private/production`, `private/inquiries`); must be writable and must not be web-accessible |

## Configuration (`.env`)

`.env` holds secrets, so it is never committed. The template is `.env.example`.

- **`APP_ENV`, `APP_DEBUG`, `APP_URL`:** set `APP_DEBUG=false` in production. `APP_BASE_PATH` is only needed when the API lives in a sub-folder.
- **`CLIENT_KEY`:** a long random secret that the website sends with every request. It must equal `API_CLIENT_KEY` in the website's `.env.local`.
- **`WEB_URL` and `REVALIDATE_SECRET`:** where the website is, and the secret for its cache refresh endpoint. The secret must equal the website's `REVALIDATE_SECRET`. `WEB_REVALIDATE=0` turns the calls off (the tests do this).
- **`DB_*`:** the database connection.
- **`MAIL_*`:** email (see below).
- **`LEGACY_*`:** the old website's database and files, used only by the import scripts.

To make a secret: `php -r "echo bin2hex(random_bytes(32));"`

## Email

- **In development,** use `MAIL_DRIVER=log`. Emails are then written to `storage/logs/mail-YYYY-MM-DD.log` instead of being sent (attachments are listed by name and size).
- **For real sending,** use `MAIL_DRIVER=smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_ENCRYPTION`.
- **Staff notifications** (inquiries with their file list, new orders and sample orders, payment reports, cancellations, new reviews, the daily summary) go to `MAIL_ADMIN_TO`.
- **Emails to buyers:**
  - accounts and inquiries: inquiry replies, "confirm your email", password reset
  - orders: order placed (proforma invoice attached), payment details received, payment confirmed (receipt attached) or rejected, production updates, on hold, resumed, new ready date, cancelled, ready to ship (balance invoice attached), shipped, delivered
  - sample orders: placed (sample invoice attached, or "we are pricing it"), priced (sample invoice attached), then the same payment and shipping emails in sample wording
  - from the daily job: payment reminders, the automatic cancellation and review requests
- **Links in emails** point to `WEB_URL`, so set it to the live website address in production.

**Gmail (pkapparel2@gmail.com):**
1. Turn on 2-Step Verification for the Google account.
2. Create an **App Password** at https://myaccount.google.com/apppasswords.
3. Paste it into `.env` after `MAIL_PASSWORD=`. Never send it by chat or email.
4. Check it with `php bin/send-test-email.php you@example.com`.

Until the password is filled in, emails go to the log file and a warning is logged.

## Daily job (cron)

`php bin/daily.php` does the daily order chores:
- reminds buyers whose deposit (or sample payment) has not arrived, 3 and 7 days after the order or after staff sent the price
- cancels orders still unpaid after 30 days, and tells the buyer
- asks buyers for a review 7 days after delivery (bulk orders, once each)
- deletes files uploaded for a quote request or sample order that was never sent (after 24 hours), and missing addresses nobody has hit for 90 days
- records when it ran (`daily_job.last_run_at`), so the admin's launch checklist can show that the cron job works
- emails staff a summary: payments to check, sample orders to price, late orders, orders due within 3 days, reviews to approve, and what the job did (nothing is sent when there is nothing to report)

Run it once a day with a cron job (in cPanel: *Cron Jobs*), using the server's PHP 8.2+ command and the path to this folder, for example:

```
0 2 * * * php /home/<account>/<api folder>/bin/daily.php
```

The time is in the server's time zone: 02:00 UTC is 07:00 in Lahore. Running it again on the same day sends nothing twice. It prints one line, such as `reminders sent: 0, orders cancelled: 0, review requests: 1, unsent uploads deleted: 0, staff summary: sent`.

## Uploaded files

**Where they live:** images uploaded in the admin and imported photos are stored in `public/uploads`:
- `blog/`, `pages/`
- `products/`
- `site/factory/`, `site/certificates/`

The database stores relative paths such as `uploads/products/2026/10/….jpg`.

**How uploads are processed** (`App\Services\ImageStore`):
- Each upload is checked to be a real image (at most 10 MB and 50 megapixels).
- It is re-encoded, which removes hidden data and photo metadata.
- It is turned upright and scaled to at most 2400 px. Photos are saved as JPEG; images with transparency are saved as PNG.

`public/uploads/.htaccess` stops anything in that folder from running as code. The website serves these files on its own domain by proxying `/uploads/*` to this API.

**Order files are private** (`App\Services\PrivateFiles`), stored in `storage/private`, never in `public/uploads`:
- `payment-slips/`: transfer slips; only staff can download them.
- `documents/`: files staff attach to an order; the buyer can download the ones shared with them.
- `production/`: production photos, each with a small preview copy; staff and the buyer of that order can see them.
- `inquiries/`: files attached to quote requests (staff only), and the design files of custom samples (which become documents of the sample order).

Photos are re-encoded like other uploads; PDFs are checked by their signature, and Excel and Word files by their content (no macros). The limit is 4 MB, because the website's host accepts at most 4.5 MB per request. The website streams these files through its own routes, checking the login.

## Local XAMPP setup

Changes made on 2026-10-15. Each changed file has a backup next to it with the suffix `.bak-pkapparel`.

- `D:\xampp\php\php.ini`: enabled the `gd`, `intl`, `sodium` and `zip` extensions.
- `D:\xampp\apache\conf\extra\httpd-vhosts.conf`: added `Listen 8080` and a vhost for `D:/pkapparel/api/public` (local requests only).
- User PATH: added `D:\xampp\php` so `php` and `composer` work in new terminals.
- MariaDB:
  - created the database `pkapparel` and its user `pkapparel`
  - gave that user read-only access to the old site's database (`pkappar2_wholesale_2166718`) for the imports
