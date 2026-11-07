# AtoBbee website (after the teaser)

This folder is the base of the **real AtoBbee website**, for when the teaser phase is over. It follows the original Atobbee prototype page by page, rebuilt in the AtoBbee design system (light ivory pages, navy bands, muted gold, Barlow / Barlow Condensed, line icons instead of emojis), with a light/dark switch.

It is **front-end only**: every person, booking and price in it is example data. Nothing is saved.

| File | What it is |
|---|---|
| `index.html` | Public homepage: hero with quick quote and the A → B bee animation, how it works, three communities, active routes, pricing, proof options, trust, pickup points, footer |
| `app.html` | The app screens: log in / create account, send a package, matches, driver profile, checkout, booking confirmed, tracking, sender / driver / pickup point / admin dashboards, publish a route, reviews, trust & safety |
| `assets/logo-mark.svg` | Small logo |

## How this fits with the rest of the repo

- `teaser/` is the live "coming soon" site with the waitlist. It stays live until launch.
- `website/` (this folder) is the design for the real site and app.
- The PHP MVP (`public/`, `src/`, `views/`) has the working logic: accounts, matching, bookings, PINs, payouts. Its screens still use the older honey-yellow look.

## Going from here to the real thing

1. Restyle the MVP's `views/` and `public/assets/app.css` to these designs, using `index.html` and `app.html` as the reference.
2. Replace example data with real data from the MVP backend.
3. Before real users and money: the open legal, insurance and tax questions in the project notes, a real ID-verification provider, and a security review.

Design tokens and rules: see `brand/brand-guide.md` in the AtoBbee Claude project.
