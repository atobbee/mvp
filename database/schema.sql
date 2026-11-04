-- AtoBbee MVP schema. Written for SQLite (dev/small deployments); see README for moving to MySQL.
-- All money is stored in euro cents (INTEGER). All timestamps are UTC ISO-8601 strings.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS cities (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    country     TEXT NOT NULL DEFAULT 'NL',
    lat         REAL NOT NULL,
    lng         REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    email                TEXT NOT NULL UNIQUE,
    password_hash        TEXT NOT NULL,
    first_name           TEXT NOT NULL,
    last_name            TEXT NOT NULL,
    role                 TEXT NOT NULL CHECK (role IN ('sender','driver','business','admin')),
    -- drivers and businesses must be verified before they can accept work
    verification_status  TEXT NOT NULL DEFAULT 'none'
                         CHECK (verification_status IN ('none','pending','verified','rejected')),
    verification_note    TEXT,
    is_professional      INTEGER NOT NULL DEFAULT 0,   -- professional transporter (KvK registered)
    kvk_number           TEXT,
    bio                  TEXT,
    languages            TEXT,                         -- e.g. "NL,EN,FR"
    stripe_account_id    TEXT,                         -- Stripe Connect account for payouts
    created_at           TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

CREATE TABLE IF NOT EXISTS vehicles (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    make_model    TEXT NOT NULL,
    year          INTEGER,
    fuel          TEXT NOT NULL DEFAULT 'petrol' CHECK (fuel IN ('petrol','diesel','electric','hybrid','none')),
    kind          TEXT NOT NULL DEFAULT 'car' CHECK (kind IN ('car','van','cargo_bike','bike')),
    max_size      TEXT NOT NULL DEFAULT 'medium' CHECK (max_size IN ('envelope','small','medium','large')),
    max_weight_kg REAL NOT NULL DEFAULT 20,
    plate_masked  TEXT
);

CREATE TABLE IF NOT EXISTS pickup_points (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id             INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    name                TEXT NOT NULL,
    address             TEXT NOT NULL,
    city_id             INTEGER NOT NULL REFERENCES cities(id),
    opening_hours       TEXT NOT NULL DEFAULT 'Mon–Sat 09:00–18:00',
    daily_limit         INTEGER NOT NULL DEFAULT 10,
    max_size            TEXT NOT NULL DEFAULT 'medium',
    accepts_pickup      INTEGER NOT NULL DEFAULT 1,  -- recipients collect here
    accepts_dropoff     INTEGER NOT NULL DEFAULT 1,  -- senders drop off here
    paused              INTEGER NOT NULL DEFAULT 0,
    subscription_status TEXT NOT NULL DEFAULT 'inactive' CHECK (subscription_status IN ('inactive','active','past_due','cancelled')),
    subscription_until  TEXT,
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

-- A driver's published journey
CREATE TABLE IF NOT EXISTS routes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    driver_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    vehicle_id      INTEGER REFERENCES vehicles(id),
    origin_city_id  INTEGER NOT NULL REFERENCES cities(id),
    dest_city_id    INTEGER NOT NULL REFERENCES cities(id),
    travel_date     TEXT NOT NULL,          -- YYYY-MM-DD
    window_start    TEXT NOT NULL,          -- HH:MM
    window_end      TEXT NOT NULL,          -- HH:MM
    max_detour_km   INTEGER NOT NULL DEFAULT 10,
    max_size        TEXT NOT NULL DEFAULT 'medium',
    max_weight_kg   REAL NOT NULL DEFAULT 20,
    capacity        INTEGER NOT NULL DEFAULT 3,  -- packages per journey
    categories      TEXT NOT NULL DEFAULT '',    -- comma separated
    price_cents     INTEGER NOT NULL,            -- driver's asking price (customer price)
    status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','cancelled','completed')),
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

-- A sender's delivery request
CREATE TABLE IF NOT EXISTS packages (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id             INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    pickup_address        TEXT NOT NULL,
    pickup_city_id        INTEGER NOT NULL REFERENCES cities(id),
    delivery_address      TEXT NOT NULL,
    delivery_city_id      INTEGER NOT NULL REFERENCES cities(id),
    pickup_point_id       INTEGER REFERENCES pickup_points(id),  -- deliver to a business instead of address
    recipient_name        TEXT NOT NULL,
    recipient_email       TEXT,
    pickup_date           TEXT NOT NULL,
    window_start          TEXT NOT NULL,
    window_end            TEXT NOT NULL,
    size                  TEXT NOT NULL CHECK (size IN ('envelope','small','medium','large')),
    weight_kg             REAL NOT NULL,
    category              TEXT NOT NULL,
    description           TEXT NOT NULL,
    declared_value_cents  INTEGER,
    requirements          TEXT NOT NULL DEFAULT '[]',  -- JSON array of requirement keys
    note_to_driver        TEXT,
    status                TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','booked','cancelled','completed')),
    created_at            TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

CREATE TABLE IF NOT EXISTS bookings (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    ref                 TEXT NOT NULL UNIQUE,        -- ATB-2026-00001
    package_id          INTEGER NOT NULL REFERENCES packages(id),
    route_id            INTEGER NOT NULL REFERENCES routes(id),
    sender_id           INTEGER NOT NULL REFERENCES users(id),
    driver_id           INTEGER NOT NULL REFERENCES users(id),
    price_cents         INTEGER NOT NULL,
    commission_cents    INTEGER NOT NULL,
    driver_cents        INTEGER NOT NULL,
    compatibility       INTEGER NOT NULL,           -- 0-100 at time of booking
    status              TEXT NOT NULL,              -- see src/Domain/BookingStatus.php
    pickup_pin          TEXT NOT NULL,
    delivery_pin        TEXT NOT NULL,
    pin_failures        INTEGER NOT NULL DEFAULT 0,
    payment_ref         TEXT,                        -- Stripe PaymentIntent / Checkout session id
    transfer_ref        TEXT,                        -- Stripe Transfer id after payout
    proof_photo         TEXT,                        -- stored filename (outside webroot)
    signature_file      TEXT,                        -- recipient signature PNG (outside webroot)
    confirmations       TEXT NOT NULL DEFAULT '{}', -- JSON: id_checked, age_checked, recipient_only...
    paid_at             TEXT,
    picked_up_at        TEXT,
    delivered_at        TEXT,
    payout_due_at       TEXT,                        -- delivered_at + dispute window
    paid_out_at         TEXT,
    cancelled_at        TEXT,
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX IF NOT EXISTS idx_bookings_status ON bookings(status);
CREATE INDEX IF NOT EXISTS idx_bookings_payout ON bookings(status, payout_due_at);

CREATE TABLE IF NOT EXISTS booking_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id  INTEGER NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
    type        TEXT NOT NULL,
    note        TEXT,
    actor_id    INTEGER REFERENCES users(id),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

CREATE TABLE IF NOT EXISTS disputes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id  INTEGER NOT NULL REFERENCES bookings(id),
    opened_by   INTEGER NOT NULL REFERENCES users(id),
    reason      TEXT NOT NULL,
    details     TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','resolved_driver','resolved_refund')),
    resolution  TEXT,
    resolved_by INTEGER REFERENCES users(id),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
    resolved_at TEXT
);

CREATE TABLE IF NOT EXISTS reviews (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id     INTEGER NOT NULL REFERENCES bookings(id),
    reviewer_id    INTEGER NOT NULL REFERENCES users(id),
    reviewee_id    INTEGER NOT NULL REFERENCES users(id),
    communication  INTEGER NOT NULL CHECK (communication BETWEEN 1 AND 5),
    punctuality    INTEGER NOT NULL CHECK (punctuality BETWEEN 1 AND 5),
    care           INTEGER NOT NULL CHECK (care BETWEEN 1 AND 5),
    accuracy       INTEGER NOT NULL CHECK (accuracy BETWEEN 1 AND 5),
    overall        INTEGER NOT NULL CHECK (overall BETWEEN 1 AND 5),
    comment        TEXT,
    flagged        INTEGER NOT NULL DEFAULT 0,
    hidden         INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
    UNIQUE (booking_id, reviewer_id)
);

CREATE TABLE IF NOT EXISTS payment_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id  INTEGER REFERENCES bookings(id),
    kind        TEXT NOT NULL,     -- charge, transfer, refund, webhook
    gateway     TEXT NOT NULL,     -- fake, stripe
    reference   TEXT,
    amount_cents INTEGER,
    payload     TEXT,
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
