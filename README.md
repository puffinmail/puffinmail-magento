# PuffinMail_LifeCycle — PuffinMail for Magento 2

Connects a Magento 2 store to [PuffinMail](https://puffinmail.co), email marketing for online stores:
newsletters, abandoned-cart and birthday emails, automations and signup forms. It uses the
**PuffinMail Store API v1** (`https://my.puffinmail.co/api/v1`) with your store's API key. You need a
PuffinMail account ([start a free trial](https://puffinmail.co)).

**Requirements:** Magento Open Source or Adobe Commerce 2.4, PHP 8.1 or later.

## Installation

```bash
composer require puffinmail/module-lifecycle
bin/magento module:enable PuffinMail_LifeCycle
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode only
bin/magento cache:flush
```

Then follow [Setup](#setup) below. To update later: `composer update puffinmail/module-lifecycle`, then
`bin/magento setup:upgrade` (and `setup:di:compile` in production mode).

| What | How |
| --- | --- |
| **Customers → contacts** | Name, phone, billing address, birthday (DOB), language, timezone and order stats (orders, total spent, first/last order) |
| **Newsletter** | Magento subscribes go to the PuffinMail store's newsletter list with a consent record (source, time, IP, wording). Unsubscribes go too. PuffinMail unsubscribes come back through a signed webhook |
| **Orders** | `order.placed` (revenue) when paid, `order.completed`, `order.cancelled` (withdraws the revenue) and `order.refunded` (negative value). These feed lifetime value, segments and automations |
| **Abandoned carts** | `cart.abandoned`, then `cart.abandoned.reminder` (optionally with a coupon). After a reminder, a bought cart sends `cart.recovered` |
| **Birthdays** | `customer.birthday` on the customer's DOB, optionally with a coupon |
| **History** | A one-off backfill of customers and paid orders, flagged `backfill`, so no emails go out |
| **Catalogue** | Products (current and regular price, image, stock, categories) for product blocks and bestsellers in emails. The whole catalogue once after connecting, then every product save, stock change and deletion. A configurable product's child refreshes its parent |
| **Website** | The PuffinMail storefront script on every page: visits from emails, browse-abandonment emails and signup forms. It knows the product on product pages, and the signed-in customer through a private customer-data section (the email plus an HMAC with the store's secret), so full-page cache never shares it. Works on Luma and Hyvä (1.1.1) |

Magento decides **who** and **when**, and generates **which coupon**. PuffinMail decides whether the
address may be emailed: it applies newsletter status, unsubscribes and bounces per the event's
`notify`. It then sends the transactional email for the event.

## How it works

- **Outbox.** Observers only write a row to `puffinmail_outbox`, so storefront and admin requests
  never wait on PuffinMail. Repeat saves of the same customer or order collapse into one row.
- **Cron `puffinmail_outbox` (every minute).** It expands customer, order and credit-memo
  references into contacts and events, under storefront emulation so product URLs and images
  are right. It then sends them in batches: 100 contacts or 50 events per call.
- **Failures.** A rejected item fails on its own. Network and 5xx errors retry with backoff, up to
  8 attempts. A 401 pauses that store view and keeps everything queued until the key is fixed.
  Failures show in the admin panel and in `bin/magento puffinmail:status`.
- **Connections.** Each store view connects with its own API key, so each brand can be a separate
  PuffinMail store. Connecting registers the store view's URL as the webhook URL
  (`/puffinmail/webhook`) and stores the webhook secret.

## Setup

1. `bin/magento setup:upgrade && bin/magento cache:flush`. This creates `puffinmail_outbox`,
   `puffinmail_cart`, `puffinmail_order` and `puffinmail_birthday`.
2. In the PuffinMail portal (my.puffinmail.co → **Stores**), create a store with platform
   Magento and copy its API key (`pm_sk_…`).
3. Go to **Stores > Configuration > PuffinMail > PuffinMail** and switch scope to the brand's
   **store view**. Paste the API key and save. The Status row should then say *connected as "…"*.
   From the CLI:
   `bin/magento config:set puffinmail/connection/api_key <key> --scope=stores --scope-code=<store_view_code>`
   (`config:set` encrypts it through the backend model), then `bin/magento puffinmail:connect`.
4. In PuffinMail, check **Store emails**: the abandoned cart, reminder and birthday emails are ready-made
   (variables below); edit them and turn each one on. While one is off, its events are recorded but not
   emailed (`not_configured`).
5. Enable *Abandoned Cart* and *Birthday* per store view. Pick coupon rules: a cart price rule with
   *Specific Coupon* and *Use Auto Generation* gives a unique code per email. Set *Uses per Coupon*
   = 1 and an end date, which becomes `coupon.expires_at`.
6. For birthdays, collect DOB under **Customers > Customer Configuration > Name and Address Options
   > Show Date of Birth = Optional**.
7. Click **Sync last 12 months** in the Status row, or run `bin/magento puffinmail:backfill
   --months=12`. This sends existing customers and order history without subscribing anyone.

**Moving from another email platform:** turn **off** its abandoned-cart and birthday automations (and its
cart sync) when enabling these, or customers get both.

## Template variables

Event `properties` become the notification's Liquid variables. `currency` and `event_type` are
added, along with the engine's own `contact.*` and `notification_center_url`
(the unsubscribe/preferences page). These match the WooCommerce plugin, so one template works
for both.

**Every email event:** `store_name`, `shop_url`, `first_name`, `coupon` (`code`, `amount`, `type`
= `percent|fixed`, `expires_at`, or empty) and `coupon_code` (a string; empty when there is none).

**Cart events** (`cart.abandoned`, `cart.abandoned.reminder`):

| Variable | Example |
| --- | --- |
| `reminder` | `1` or `2` |
| `restore_url` | Link that restores the cart and applies the coupon |
| `items` | List of `{name, sku, quantity, price, price_formatted, url, image_url, product_id, variant_id}`. `price` is per unit including tax |
| `total`, `total_formatted` | `123.45`, `$123.45` (subtotal including tax) |
| `item_count` | `3` |

```liquid
{% for item in items %}
  <img src="{{ item.image_url }}" width="80"> {{ item.name }} × {{ item.quantity }} — {{ item.price_formatted }}
{% endfor %}
{% if coupon_code != "" %}Use code <strong>{{ coupon_code }}</strong> at checkout.{% endif %}
{% if delivery_date %}Order by {{ order_by }} for delivery on {{ delivery_date }}.{% endif %}
<a href="{{ restore_url }}">Return to my cart</a>
<a href="{{ notification_center_url }}">Unsubscribe</a>
```

**Order events** (for automations): `order_number`, `total`, `total_formatted`, `items`, `coupons`,
`payment_method`, `shipping_method`, `customer_type`, `order_url` and more (see `Mapper::orderProperties`).

### Adding variables from another module

Observe `puffinmail_event_properties`. Change `transport->getData('properties')`. The observer also
receives `event_type`, `store`, and `quote_id`, `order_id` or `customer_id` as relevant.
For example, a delivery-estimate module can add the order cut-off time to the second cart reminder.

## Abandoned-cart rules

- **Which carts.** Carts come from Magento's quotes: active, with items and an email.
  - Logged-in customers always have an email on the cart.
  - Checkouts that save the guest email early also work, e.g. checkouts that call
    `setGuestEmailOnCart`.
  - Stock Luma checkout only saves it at order time. There, *Remember Guest Emails at Checkout*
    posts it to `/puffinmail/cart/email` as soon as it's typed.
- **Timing.** Reminder 1 goes after *first delay* minutes idle (at least 15). Reminder 2 goes
  *second delay* hours after both reminder 1 and the last cart change.
- **Skipped:**
  - carts older than *max age* or below *minimum subtotal*;
  - an address's older carts;
  - addresses that ordered since the cart was last touched;
  - addresses reminded about another cart in the last 20 hours.
- **Restore link.** `/puffinmail/cart/restore?t=` restores a guest cart into the session, or asks
  a customer to sign in. It applies the email's coupon and records the click.

## CLI

```bash
bin/magento puffinmail:connect                         # (re)connect every store view with a key
bin/magento puffinmail:status                          # connection, queue, failures, backfill
bin/magento puffinmail:run outbox                      # send the queue now
bin/magento puffinmail:run abandoned-cart --dry-run    # who would get a cart reminder now
bin/magento puffinmail:run birthday --dry-run
bin/magento puffinmail:backfill --months=12            # or --months=0 for everything; --cancel
bin/magento puffinmail:test-email cart <quote_id> you@example.com [--reminder=2]
bin/magento puffinmail:test-email birthday <customer_id> you@example.com
bin/magento puffinmail:delete-contact jane@example.com # privacy erasure
bin/magento puffinmail:catalog [--restart]             # catalogue sync state; --restart sends every product again
```

`test-email` sends the event straight to PuffinMail with `notify=always`, using real cart or
customer data. It records nothing, and a configured coupon shows as `TESTCODE`.

## Cron (group `default`)

| Job | Schedule |
| --- | --- |
| `puffinmail_outbox` | Every minute (45 s budget) |
| `puffinmail_backfill` | Every minute (does nothing unless a backfill is running) |
| `puffinmail_catalog` | Every minute: queues the catalogue (200 products a run) until it has been sent once; refreshes old connections for the website script |
| `puffinmail_abandoned_cart` | Every 5 min |
| `puffinmail_birthday` | Hourly 09:00–20:00, once a year per customer; a later run catches up a missed one |

Logs go to `var/log/puffinmail.log`. Turn on *Advanced > Debug Log* to log every API call.

## Not included (yet)

- A newsletter checkbox at checkout. Magento has none out of the box, and the checkouts differ
  (Luma, Hyvä, ReactCheckout). Subscriptions from the footer form, registration and My Account
  are synced.
- Campaign revenue attribution is done by PuffinMail itself (the last email click before an order), so no
  tracking parameter is needed.

## Website script and signup forms

- **Stores › Configuration › PuffinMail › Website** turns the script on or off per store view (on by default).
- Signup forms are made in PuffinMail (Audience › Signup forms). A pop-up appears by itself once live; a form in a
  page goes where you paste its `<div data-puffinmail-form="…"></div>` (a CMS page or block).
- `etc/csp_whitelist.xml` allows `my.puffinmail.co` for scripts and connections. A development portal on another
  host needs adding there (or CSP in report-only mode).
- Mention the first-party cookie `pm_vid` (one year) in the privacy policy.

## Support and licence

Questions: hello@puffinmail.co.

Copyright © 2026 Solaniq. Licensed under the Open Software License version 3.0 ([LICENSE.txt](LICENSE.txt)).
