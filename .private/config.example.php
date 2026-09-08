<?php
/**
 * Hall of Brands — secrets & settings.
 * This file lives OUTSIDE the web root's exposed reach (.private/) — make sure
 * your host does not serve it directly. On Hostinger, .private is already
 * excluded from public_html serving by convention, but double check with:
 *   curl -I https://thehallofbrands.com/.private/config.php
 * It must return 403/404, never 200.
 */

// --- Razorpay (domestic — Indian buyers) ------------------------------------
// From https://dashboard.razorpay.com/app/keys — use Test mode keys first.
define('RAZORPAY_KEY_ID', 'rzp_test_REPLACE_ME');
define('RAZORPAY_KEY_SECRET', 'REPLACE_ME');
// From Razorpay Dashboard > Account & Settings > Webhooks, after adding
// https://thehallofbrands.com/api/razorpay/webhook.php
define('RAZORPAY_WEBHOOK_SECRET', 'REPLACE_ME');

// --- PayPal (international — non-Indian buyers) -----------------------------
// From https://developer.paypal.com/dashboard/applications — use a Sandbox
// app first, switch to Live only once you're ready to accept real payments.
define('PAYPAL_CLIENT_ID', 'REPLACE_ME');
define('PAYPAL_CLIENT_SECRET', 'REPLACE_ME');
define('PAYPAL_MODE', 'sandbox'); // 'sandbox' or 'live'

// --- Pricing ---------------------------------------------------------------
// Each block is a 5x5 patch of the 1,000,000-pixel wall (branding only —
// pixels aren't sold individually).
// INR: 25 pixels x Rs.85/pixel = Rs.2125 = 212500 paise.
define('PRICE_PER_BLOCK_INR_MINOR', 212500);
// USD: ~$1/pixel matching the original branding this site is patterned on.
define('PRICE_PER_BLOCK_USD_MINOR', 2500); // $25.00, in cents

// --- URLs --------------------------------------------------------------
define('SITE_URL', 'https://thehallofbrands.com');
define('SUCCESS_URL', SITE_URL . '/?success=1');
define('CANCEL_URL', SITE_URL . '/?canceled=1');

// --- Reservation behaviour -----------------------------------------------
// Minutes a block stays "pending" (reserved during checkout) before it's
// released back to the pool if payment isn't completed.
define('PENDING_TTL_MINUTES', 15);

// Max blocks allowed in a single order (basic abuse guard). No minimum —
// a block is already the atomic sellable unit, so a 1-block order is valid.
define('MAX_BLOCKS_PER_ORDER', 5000);

// --- Grid size ---------------------------------------------------------
// 200 x 200 = 40,000 sellable blocks. Must match GRID_SIZE in hallofbrands.js.
define('GRID_SIZE', 200);

// --- File paths ------------------------------------------------------------
define('BLOCKS_JSON_PATH', __DIR__ . '/../data/blocks.json');
define('UPLOADS_PENDING_DIR', __DIR__ . '/../uploads/pending');
define('UPLOADS_CLIENTS_DIR', __DIR__ . '/../uploads/clients');
define('UPLOADS_CLIENTS_URL', SITE_URL . '/uploads/clients');
