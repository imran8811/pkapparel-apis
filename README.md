# PK Apparel API

The REST API behind pkapparel.com: plain PHP 8.2+ (no framework) on MySQL.
It holds everything the website shows or saves: site settings, marketing content, the blog, the wholesale catalog, inquiries, buyer accounts, carts, orders, payment reports and staff accounts.

**Only the website talks to it.** The Next.js website (the separate `web` repository) calls it from its server.
Every request needs the shared `X-Client-Key`. Buyer account routes also need a buyer login token, and admin routes a staff login token. Browsers never call this API directly.

## Requirements

- **PHP 8.2+** (8.3 recommended) with these extensions:
  - `pdo_mysql`, `mbstring`, `intl`, `gd`, `curl`, `dom` and `openssl`
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

## Staff accounts

Staff log in to the website's admin panel at `/admin`. Accounts are managed here:

```
php bin/create-staff.php --name="Your Name" --email=you@example.com --role=admin
```

- A new account gets a generated password, printed once. Change it under *My account* after logging in.
- `--reset-password --email=…` gives an account a new password and logs it out everywhere.
- `--deactivate` / `--activate` turn an account off or on. A deactivated account is locked out immediately.
- `--list` shows all accounts.

| Role | Can use |
|---|---|
| `admin` | everything, including company settings and the bank details |
| `editor` | website content: blog, pages, FAQs, reviews, certificates, factory, shop styles, size charts and shop category texts |
| `sales` | orders, customers, inquiries, shipping rates and add-ons |
| `production` | orders (read); production updates come with Phase 4 |

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
- **Cart** (`/v1/cart`): one line per color of a style, with a quantity per size. It is always priced from the catalog (`App\Services\CartPricing`): color or style price, volume tiers across all colors of a style, the minimum per color, and colors no longer sold. A guest's cart is merged into the account at login or signup.
- **Freight estimates** (`App\Services\Freight`): staff keep zones of countries with air rates (per chargeable kg) and sea rates (per CBM) in rate bands; the cheapest band applies. Packing comes from each style, or the `commerce.packing` defaults. Without a rate, checkout says the freight will be quoted.
- **Orders** (`App\Services\OrderService`):
  - Placing an order freezes copies of the lines, prices, buyer and address, empties the cart and emails the buyer the **proforma invoice** (PDF), with the bank details, and staff a summary.
  - Order numbers look like `PK-261016-482` (Pakistan date plus a random code).
  - The deposit (`commerce.deposit_percent`, 50%) covers the goods and priced add-ons. Freight is an estimate until staff confirm it and is paid with the balance, together with "price on request" add-ons.
- **Payment reports:** the buyer reports each transfer (transaction ID, amount, date, sender, optional slip). A transaction ID can be used only once. Staff review them in Phase 4.
- **Bank details** are the private `bank` setting: never in `/v1/site`, and only shown to the buyer of an order with an amount due.
- **PDFs** are made with dompdf from `templates/pdf/` (DejaVu Sans; the logo is `templates/pdf/logo.png`). Its font cache lives in `storage/cache/dompdf`.

| Folder | What it contains |
|---|---|
| `public/` | `index.php` (the only entry point), `.htaccess`, `uploads/` |
| `src/` | `Core/`, `Controllers/` (`Admin/` for the admin panel), `Middleware/`, `Models/`, `Services/` |
| `routes/api.php` | every route |
| `config/app.php` | settings, read from `.env` |
| `database/migrations/` | numbered SQL files |
| `templates/` | email templates (`email/`) and the proforma invoice (`pdf/`) |
| `bin/` | command-line scripts |
| `tests/` | PHPUnit tests |
| `storage/` | logs, cache and private files (`private/payment-slips`); must be writable and must not be web-accessible |

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
- **Staff notifications** (inquiries, new orders, payment reports, cancellations) go to `MAIL_ADMIN_TO`.
- **Emails to buyers:** inquiry replies, "confirm your email", password reset, order placed (with the proforma invoice attached), payment details received.
- **Links in emails** point to `WEB_URL`, so set it to the live website address in production.

**Gmail (pkapparel2@gmail.com):**
1. Turn on 2-Step Verification for the Google account.
2. Create an **App Password** at https://myaccount.google.com/apppasswords.
3. Paste it into `.env` after `MAIL_PASSWORD=`. Never send it by chat or email.
4. Check it with `php bin/send-test-email.php you@example.com`.

Until the password is filled in, emails go to the log file and a warning is logged.

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

**Payment slips are private** (`App\Services\PaymentSlips`): they are stored in `storage/private/payment-slips`, never in `public/uploads`, and only staff can download them through the admin. Photos are re-encoded like other uploads; PDFs are checked by their signature. The limit is 4 MB, because the website's host accepts at most 4.5 MB per request.

## Local XAMPP setup

Changes made on 2026-10-15. Each changed file has a backup next to it with the suffix `.bak-pkapparel`.

- `D:\xampp\php\php.ini`: enabled the `gd`, `intl`, `sodium` and `zip` extensions.
- `D:\xampp\apache\conf\extra\httpd-vhosts.conf`: added `Listen 8080` and a vhost for `D:/pkapparel/api/public` (local requests only).
- User PATH: added `D:\xampp\php` so `php` and `composer` work in new terminals.
- MariaDB:
  - created the database `pkapparel` and its user `pkapparel`
  - gave that user read-only access to the old site's database (`pkappar2_wholesale_2166718`) for the imports
