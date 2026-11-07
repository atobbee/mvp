# AtoBbee teaser site

The public "coming soon" site for AtoBbee: a teaser homepage with Send / Drive / Pickup point pages, a waitlist, a privacy statement, and a clickable demo with example data. Plain PHP + SQLite, no installs needed. Runs on Hostnet's standard webhosting.

## What's in here

| File | What it does |
|---|---|
| `index.html` | The teaser site (home, Send, Drive, Pickup points, Join) |
| `demo.html` | Clickable product demo with example data. Nothing is real. |
| `privacy.html` | Privacy statement. Fill in the [BRACKETS] before going live. |
| `signup.php` | Saves waitlist signups |
| `admin.php` | Your private waitlist page: view, remove, download as CSV |
| `config.example.php` | Settings template. Copy to `config.php` on the server. |
| `db.php` | Creates the database file on first signup |
| `data/` | Where the database file lives. Blocked from the web. |
| `assets/` | Logo files |

## Before you upload

1. In `index.html` and `privacy.html`, replace `YOUR-ADDRESS@gmail.com` with your email.
2. In `privacy.html`, fill in `[FULL NAME(S)]` and `[DATE]`.

## Going live on Hostnet

1. Upload everything in this `teaser` folder into your website's public folder (usually `httpdocs`, sometimes `public_html`). Upload the *contents* of `teaser`, not the folder itself.
2. On the server, copy `config.example.php` to `config.php` and set your own admin password in it. Never put `config.php` back in GitHub: the repo is public.
3. Turn on the free SSL certificate (Let's Encrypt) for the domain.
4. Check:
   - `https://yourdomain.nl` shows the site
   - joining the waitlist says "You're on the list"
   - `https://yourdomain.nl/admin.php` lets you log in and shows your signup
   - `https://yourdomain.nl/data/waitlist.sqlite` is **blocked** (403 / Forbidden). If it downloads, tell whoever is helping you right away.

## Updating later

Change the files here, then upload the changed files to the same folder on Hostnet again. Don't overwrite `config.php` or the `data` folder on the server, because those hold your password and your signups.
