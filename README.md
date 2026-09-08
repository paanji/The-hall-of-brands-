# Hall of Brands — deployment guide

## 1. Upload everything

Upload the whole folder structure to `public_html/` on your Hostinger account,
preserving the layout:

```
public_html/
├── index.html
├── terms.html, privacy.html, refund.html
├── hallofbrands.css
├── hallofbrands.js
├── api/
│   ├── lib.php
│   ├── get_blocks.php
│   ├── public_config.php
│   ├── razorpay/create_order.php, verify_payment.php, webhook.php
│   └── paypal/create_order.php, capture_order.php
├── data/
│   ├── blocks.json
│   └── .htaccess
├── uploads/
│   ├── clients/        (public — logos of paid, live blocks)
│   └── pending/.htaccess (private — logos mid-checkout)
└── .private/
    ├── config.php
    └── .htaccess
```

Make `data/`, `uploads/pending/`, and `uploads/clients/` **writable** by PHP
(755 or 775 depending on your host's user setup).

## 2. Fill in the legal pages

`terms.html`, `privacy.html`, and `refund.html` have `[placeholder]` text for
your business name, address, and contact email. **Fill these in before
requesting Razorpay activation** — live policy pages are a hard requirement,
not optional. Have them reviewed by someone who knows Indian e-commerce law
before you rely on them for real.

## 3. Set up Razorpay (domestic — Indian buyers)

1. Sign up at https://razorpay.com and complete KYC (PAN + bank account).
2. From the dashboard: **Settings → API Keys** → generate keys. Use **Test
   mode** keys first.
3. Paste `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET` into `.private/config.php`.
4. **Settings → Webhooks → Add new webhook**:
   - URL: `https://thehallofbrands.com/api/razorpay/webhook.php`
   - Active events: `payment.captured`, `payment.failed`
   - Copy the generated secret into `RAZORPAY_WEBHOOK_SECRET`.
5. Test with Razorpay's test card `4111 1111 1111 1111`, any future expiry,
   any CVC — or UPI test handle `success@razorpay` in test mode.

Once Razorpay activates your account and you're ready for real money, switch
to your **live** keys (`rzp_live_...`) — same config fields, no code changes.

## 4. Set up PayPal (international — non-Indian buyers)

1. Sign up at https://developer.paypal.com and create an app under
   **Apps & Credentials** (a **Sandbox** app first, for testing).
2. Paste the app's **Client ID** and **Secret** into `PAYPAL_CLIENT_ID` /
   `PAYPAL_CLIENT_SECRET` in `.private/config.php`.
3. Leave `PAYPAL_MODE` as `'sandbox'` while testing — switch to `'live'`
   (with a Live app's credentials) when you're ready for real payments.
4. Test with a PayPal **Sandbox buyer account** (created automatically under
   Sandbox → Accounts in the developer dashboard).

PayPal doesn't need a webhook for this integration — the payment capture
happens synchronously in `api/paypal/capture_order.php`, which is already
the authoritative confirmation.

## 5. Verify `.private` and `data` aren't publicly reachable

Run:

```
curl -I https://thehallofbrands.com/.private/config.php
curl -I https://thehallofbrands.com/data/blocks.json
```

Both must return `403` or `404`. If either returns `200`, your host isn't
honoring the included `.htaccess` files — contact Hostinger support or move
`.private/` and `data/` outside `public_html/` entirely and update the paths
in `.private/config.php` accordingly.

## 6. Test end-to-end

With test/sandbox keys in place for both gateways:

1. Load the site from an Indian IP (or without a VPN, if you're in India) —
   you should see ₹ pricing and get the Razorpay flow at checkout.
2. Load it through a VPN set to a non-Indian country — you should see $
   pricing and get PayPal buttons instead of the "Continue to payment" button.
3. Complete a test purchase on each path and confirm the block shows as sold
   with your uploaded image.
4. Try selecting blocks and closing the tab without paying — after 15 minutes
   (`PENDING_TTL_MINUTES` in config.php) they should become available again.

## How pricing works

Two separate numbers in `.private/config.php` — `PRICE_PER_BLOCK_INR_MINOR`
and `PRICE_PER_BLOCK_USD_MINOR` — are the source of truth for what actually
gets charged, applied server-side in each gateway's `create_order.php`. The
matching constants in `hallofbrands.js` (`PRICE_PER_BLOCK_INR` /
`PRICE_PER_BLOCK_USD`) are only for the running-total display; keep all four
in sync when repricing.

## Notes on what changed from the previous version

- **Payment gateways switched from Stripe to Razorpay + PayPal.** Stripe
  stopped self-serve signups for new India-based businesses in 2024 and
  remains invite-only, which made it impractical here. Razorpay handles
  domestic (₹) checkout with a real UPI/card/netbanking flow; PayPal handles
  international ($) checkout. Buyers get routed to whichever one matches
  their detected country automatically — no manual choice needed.
- **This is a genuinely dual-currency setup now**, not just a display
  difference — a $ price shown to an international visitor is what they
  actually get charged via PayPal, not an INR charge with a note about
  conversion (that was the old Stripe-only approach).
- Added `terms.html`, `privacy.html`, and `refund.html` — required for
  Razorpay's KYC and international-payment activation, and generally good
  practice for any site taking payments. Fill in the placeholders before
  going live.
- **Selling model**: the wall is framed as 1,000,000 pixels for branding, but
  sold in 40,000 fixed 5x5-pixel **blocks** — there's no way to buy an
  individual pixel. This matches the classic "million dollar homepage"
  convention of selling in pre-set blocks rather than raw pixels.
- **No minimum order** — a block is already the atomic sellable unit, so a
  single block is a valid purchase. Worth revisiting once real orders come
  in: a single 5x5-pixel block is still very small to show a real logo, so a
  small minimum (e.g. 4 blocks = a 10x10 patch) may be worth adding later.
- **Grid rendering**: a simple, directly-clickable DOM grid (each block a
  plain 10px square) — no zoom/pan canvas. `GRID_SIZE` (200, i.e. 200x200 =
  40,000 blocks) must stay in sync between `.private/config.php` and
  `hallofbrands.js`.
- Available blocks are no longer randomly colored on every page load — the
  grid reflects real state (available / selected / reserved / sold), stored
  server-side in `data/blocks.json`.
- Buyers can multi-select any number of blocks and check out for all of them
  in one payment, instead of just sending a contact request.
- Blocks are held for 15 minutes during checkout so two people can't buy the
  same block at once, then automatically release if payment isn't completed.
- `default.php` was Hostinger's unused placeholder page and can be deleted.


## Admin dashboard

A password-protected admin panel lives at `/admin/`. It's separate from
the public site — nothing links to it, and `robots.txt` keeps it out of
search engines.

**One-time setup**: visit `https://thehallofbrands.com/admin/setup.php`,
choose a username and a real password (10+ characters). This page
permanently disables itself the moment credentials are set — if you ever
need to reset them, edit `.private/admin_config.php` directly and set
`ADMIN_PASSWORD_HASH` back to `''`.

**What it does:**
- **Dashboard** (`/admin/index.php`) — total blocks sold, revenue recorded,
  a table of every client (logo, brand, blocks, source, amount, date), and
  any blocks currently reserved mid-checkout (with a "release now" button
  if one gets stuck).
- **Add client** (`/admin/add_client.php`) — for offline payments (bank
  transfer, UPI, cash). Enter block numbers or ranges (e.g.
  `1000-1049, 2200, 2201`), brand, website, logo, and optionally the amount
  collected for your own records. Marks those blocks sold immediately —
  no payment gateway involved.
- **Edit client** (`/admin/edit_client.php?order=...`) — fix a brand name,
  link, or swap a logo; or remove a client entirely, freeing their blocks.

**Security notes:**
- Login uses a bcrypt-hashed password, a session cookie scoped to
  `/admin/` with `HttpOnly`/`Secure`/`SameSite=Strict`, and CSRF tokens on
  every form.
- 5 failed login attempts locks further attempts for 15 minutes
  (`ADMIN_LOGIN_MAX_ATTEMPTS` / `ADMIN_LOGIN_LOCKOUT_MINUTES` in
  `.private/admin_config.php`).
- Sessions auto-expire after 30 minutes of inactivity
  (`ADMIN_SESSION_IDLE_MINUTES`).
- Online orders (via Razorpay or PayPal) and offline orders (via the admin
  panel) all end up in the same `data/blocks.json`, tagged with
  `source: "razorpay"`, `"paypal"`, or `"offline"` — the dashboard shows
  all of them together.

## Currency detection &amp; routing (₹ vs $)

Visitors are shown ₹ pricing and routed to Razorpay if they're in India, or
$ pricing and routed to PayPal otherwise — detected client-side via a free
IP geolocation lookup (`ipwho.is`, no API key). If that lookup fails or is
blocked (ad blockers, offline preview, etc.), it silently falls back to ₹
and Razorpay — India is this site's primary market, so that's the safer
default.

This is genuinely dual-currency: a $ price shown to an international
visitor is what they're actually charged via PayPal, not an INR charge
with a note about conversion.

To reprice, update **all four** of these together — they're independent
numbers, not a live conversion, so they won't silently drift unless you
forget one:
- `PRICE_PER_BLOCK_INR_MINOR` and `PRICE_PER_BLOCK_USD_MINOR` in
  `.private/config.php` (what actually gets charged)
- `PRICE_PER_BLOCK_INR` and `PRICE_PER_BLOCK_USD` in `hallofbrands.js`
  (what's shown as the running total while selecting blocks)

## Version control (GitHub)

This project is git-ready. The `.gitignore` excludes real secrets and live
customer data, so nothing sensitive ends up in your commit history — but
it keeps **template** versions of those files tracked, so a fresh clone
has everything needed to get running.

**What's tracked vs. not:**

| Tracked (safe, template) | Ignored (real, local only) |
|---|---|
| `.private/config.example.php` | `.private/config.php` |
| `.private/admin_config.example.php` | `.private/admin_config.php` |
| `data/blocks.example.json` (empty `{}`) | `data/blocks.json` (live orders) |
| `uploads/clients/.gitkeep`, `uploads/pending/.gitkeep`, `uploads/pending/.htaccess` | actual uploaded logo files |

**First-time setup after cloning:**

```
cd hallofbrands
git init
git add .
git commit -m "Initial commit"

cp .private/config.example.php .private/config.php
cp .private/admin_config.example.php .private/admin_config.php
cp data/blocks.example.json data/blocks.json
```

Then fill in your real Razorpay/PayPal keys in `.private/config.php`, and
visit `/admin/setup.php` once to set your admin password — same as the
non-git setup flow described earlier in this file.

**Recommended**: keep this as a **private** repository. Even though secrets
never get committed, the business logic and admin panel aren't things
worth making publicly browsable.
