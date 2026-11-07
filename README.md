# Atobbee MVP

Community-powered package delivery for the Breda–Rotterdam corridor. Senders post a package, verified drivers already travelling that way carry it, Atobbee takes 8% (min €1.50) and pays the driver after delivery plus a 24-hour dispute window.

Plain PHP 8.2+, no framework, no Composer dependencies. Runs on any basic PHP host.

## Run it locally

```bash
cp config.example.php config.php          # optional in dev, the example is used as fallback
php bin/install.php --fresh --demo        # creates storage/atobbee.sqlite with demo users
php -S 127.0.0.1:8000 -t public public/index.php
```

Open http://127.0.0.1:8000. Demo accounts, all with password `demo1234`:

| Email | Role |
|---|---|
| pieter@atobbee.test | Sender |
| sophie@atobbee.test, marco@atobbee.test, lena@atobbee.test | Verified drivers |
| karin@atobbee.test | Driver awaiting verification |
| kaashoek@atobbee.test | Pickup point (De Kaashoek, Breda) |
| admin@atobbee.test | Admin |

Tests:

```bash
php tests/run.php                         # domain rules: pricing, matching, booking lifecycle (in-memory DB)
php bin/install.php --fresh --demo && php tests/e2e.php   # full HTTP flows; needs the dev server running
```

## What works

- **Accounts & roles**: sender, driver, business (pickup point), admin. Sessions, CSRF on every POST, bcrypt password hashing, role guards.
- **Send flow**: route, timing window, size/weight/category, declared value, delivery requirements (PIN always, plus QR, signature, photo, recipient-only, ID check, 18+, fragile, upright), prohibited-items confirmation. Deliver to an address or to a pickup point.
- **Matching** (`src/Domain/Matching.php`): rule-based, not AI. Hard filters on date, window overlap, size, weight, accepted categories, fragile handling, capacity, and detour (extra km for origin → pickup → delivery → destination vs the direct trip). Score 0–100 from detour used and window overlap.
- **Two ways to book**: sender picks a driver's route, or driver sees compatible open requests and sends an offer the sender accepts.
- **Pricing** (`src/Domain/Pricing.php`): 8% with €1.50 floor, €5 minimum price, driver price suggestions (cost recovery / fair / priority).
- **Booking lifecycle** (`src/Domain/BookingStatus.php`): offered → awaiting payment → paid (held) → picked up → [at pickup point] → delivered → completed, plus cancelled / disputed / refunded. Every change is checked against the transition table and logged to a timeline.
- **Proof**: 4-digit pickup PIN (sender → driver) and delivery PIN (recipient → driver or pickup point), locked after 5 wrong tries. Photo upload, on-screen signature pad, ID/age/recipient-only confirmations when required. Proof files stored outside the webroot and only served to people on the booking.
- **Pickup points**: profile, opening hours, daily limit (enforced), max size, pause, subscription status, QR handover (camera scan via BarcodeDetector where supported, typed reference everywhere).
- **Disputes**: sender reports a problem until the window closes; payout freezes; admin releases or refunds with a note.
- **Reviews**: both sides, five categories, one per booking, only completed deliveries count towards ratings, reviewee can report an unfair review, admin hides or keeps.
- **Admin**: stats, gross bookings, commission, verification queue, disputes, flagged reviews, recent activity.
- **Payments**: `fake` gateway for dev; `stripe` gateway with Checkout, Express connected accounts, transfers tied to the original charge, refunds, signed webhooks, idempotency keys.
- **Cron** (`bin/cron.php`, every 5 min): expires unpaid bookings after 30 min, releases payouts after the dispute window.

## Turning on Stripe (test mode first)

1. In `config.php`: `'payment_gateway' => 'stripe'`, your `sk_test_…` key, and a webhook secret.
2. Enable Connect in the Stripe dashboard (Express accounts, NL platform). Enable iDEAL as a payment method.
3. Add a webhook endpoint `https://your-domain/webhooks/stripe` for `checkout.session.completed`.
4. Locally: `stripe listen --forward-to 127.0.0.1:8000/webhooks/stripe` and use the printed `whsec_…`.
5. Drivers connect payouts from their profile before their first payout. Payouts to drivers without an account fail and stay in "delivered" (logged) until they connect.

The Stripe integration is written against Stripe's REST API but has not been run against a live Stripe account here — test it end to end in test mode before trusting it.

## Deploying

- Document root → `public/`. Apache uses `public/.htaccess`; for nginx: `try_files $uri /index.php?$query_string;`.
- `storage/` must be writable by PHP and **not** web-accessible.
- Set `'env' => 'prod'` (secure cookies, no error output) and a real `base_url` with HTTPS.
- Add the cron line from `bin/cron.php`.
- Create the first admin: `php bin/create-admin.php you@example.com 'long-password'`.
- SQLite is fine for a pilot. To move to MySQL/MariaDB: change `db_dsn`, convert `schema.sql` (AUTOINCREMENT → AUTO_INCREMENT, TEXT timestamps → DATETIME or keep as strings, drop the PRAGMA line), and replace `INSERT OR IGNORE` in `bin/install.php` with `INSERT IGNORE`.

## Deliberately not done yet

| Area | Now | Before launch |
|---|---|---|
| Identity verification | Admin approves manually | Regulated ID provider (iDIN, Onfido, Stripe Identity) with a callback that sets `verification_status` |
| Addresses | City list + street text, straight-line × 1.3 for road km | Geocoding + routing API (e.g. PDOK Locatieserver for NL addresses, OSRM/Google for distances) |
| Live tracking | Status timeline | Driver location pings while `picked_up` |
| Notifications | None | Email/SMS: recipient PIN, offers, status changes, payout notices |
| Pickup point billing | Activate button, no charge | Stripe Billing subscription (€0.99/mo) + webhooks |
| Drop-off at pickup points | Setting exists | Flow where sender drops off and driver collects from the point |
| Invoices & VAT | Not generated | Depends on whether drivers are private or VAT-registered; get advice |
| Rate limiting | Per-session login slowdown | Proper rate limiting at the web server / WAF |
| Languages | English | Dutch first |
| Legal text | Placeholder | Lawyer-reviewed terms, privacy policy, cookie policy |

## Code map

```
public/index.php          front controller + route table
src/bootstrap.php         autoloader, config, timezone
src/helpers.php           escaping, money, CSRF, flash, views
src/Auth.php              sessions, login, role guards
src/Db.php                PDO wrapper (prepared statements only)
src/Domain/               Pricing, Matching, BookingStatus, BookingService (all business rules)
src/Payments/             Gateway interface, FakeGateway, StripeGateway
src/Http/                 thin controllers per area
views/                    plain PHP templates (every value escaped with e())
database/schema.sql       schema
bin/                      install, create-admin, cron
tests/                    run.php (domain), e2e.php (HTTP)
```
