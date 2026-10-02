# Quissly Search for CS-Cart

Replaces your store's native product search with Quissly's AI search. Search itself is a
thin path: the add-on sends each query to Quissly, receives a ranked list of product IDs,
and renders those products through your **existing theme**. If Quissly is ever
unreachable, it falls back automatically to native CS-Cart search.

It does **not** edit your theme's files, and it never changes your catalog, prices or
checkout. It *does* add things to the storefront, through CS-Cart's own theme hooks, once
Quissly search is live: a **search overlay** over your theme's search box (on by default,
switchable), optional **voice** and **image** search buttons, and an optional **QChat**
widget. And it reads your catalog and sends product data to Quissly — title, description,
price and sale price, stock, images, categories, URL, SKU, vendor, and the product
features your storefront shows — so Quissly can index it.

**Who installs this:** your developer / sysadmin — it needs SSH/file access **and**
CS-Cart admin access.

## At a glance

1. Place your private key on the server (outside the web root, `chmod 600`) — *manual
   setup only; skip if you will use one-click Connect.*
2. Add the credential lines to `config.local.php` — *or skip both and use one-click
   Connect after the add-on is installed (Step 2 alternative).*
3. Extract/upload the add-on, activate it, and clear the cache.
4. Open **Add-ons → Quissly → Configuration** → connect if you haven't → click
   **Test connection**.
5. Open **Add-ons → Quissly → Dashboard** → click **Start initial sync** and wait until
   the dashboard shows the products confirmed. **Quissly search stays off — shoppers keep
   native CS-Cart search — until that first sync is confirmed.**
6. Install the cron line the Dashboard prints, so later product changes keep flowing to
   Quissly.
7. Search the storefront.

Each step is detailed below.

## Prerequisites

- **CS-Cart 4.11.4.SP3**, **PHP 7.4**.
- SSH/file access to the server **and** CS-Cart admin access.
- The add-on package: **`quissly_search-1.0.0.tgz`**.
- With **one-click Connect** (Step 2 alternative) nothing below this line is needed — the
  add-on generates its own key pair and Quissly issues the token.
- For the manual setup: your Quissly **bearer token** (provided by Quissly) and your
  **RSA private key** (`quissly_private.pem`, 2048-bit) — the one whose public key you
  registered with Quissly. Quissly holds your public key under the `prod` environment.
- **Accurate server clock (NTP enabled).** Requests are signed with a timestamp; if
  the clock drifts more than ~1 minute from real time, Quissly rejects every
  request. Confirm NTP sync first.

## What the add-on changes on your system

For transparency, installing it:

- adds four directories: `app/addons/quissly_search/` (the add-on itself),
  `design/backend/templates/addons/quissly_search/` (its admin pages),
  `js/addons/quissly_search/` (the overlay, voice/image and chat-cart scripts) and
  `var/themes_repository/responsive/{css,templates}/addons/quissly_search/` (the
  storefront hook templates + stylesheet);
- reads up to five `$config[...]` lines in `config.local.php` if you use the manual setup
  (`quissly_bearer_token`, `quissly_private_key_path` *or* `quissly_private_key`,
  `quissly_environment`, `quissly_project_id`, `quissly_account_email`) — **none** with
  one-click Connect;
- creates nine small database tables, all prefixed `cscart_quissly_search_`:
  `health` (single-row connection-alert record), `queue` (products waiting to be sent),
  `synced` (what Quissly holds), `operations` (Quissly operations not yet finished),
  `state` (the add-on's own state — **including the encrypted credentials when you use
  one-click Connect**), `log` (the sync log), `tokens` (voice/image result sets),
  `rate` (per-visitor throttle buckets) and `ids` (Quissly id ↔ product id pairs, for the
  chat's "Add to cart");
- imports its interface strings in English, Georgian (ka), and Russian (ru);
- sets one first-party cookie on a guest's first search, `quissly_uid` (a random id, one
  year, HttpOnly), so Quissly's analytics count a returning visitor once — and, with CS-Cart's
  **GDPR** add-on active, lists itself in its cookie banner as **Quissly search** under
  *Performance cookies*, so a shopper can decline it (see *Security notes*).

It edits none of your own files — no theme, template, catalog, price, or checkout changes.
Disabling the add-on reverts search to native immediately. **Uninstalling drops all nine
tables** — see *Rolling back* before you do that on a store connected with one-click
Connect.

## Step 1 — Place your private key on the server

Put the `.pem` **outside the web root**, readable only by the web-server / PHP user:

```bash
sudo mkdir -p /etc/quissly
sudo mv quissly_private.pem /etc/quissly/quissly_private.pem
sudo chown <web-user> /etc/quissly/quissly_private.pem   # e.g. www-data, nginx, or the PHP-FPM user
sudo chmod 600 /etc/quissly/quissly_private.pem
```

Note the absolute path for the next step.

## Step 2 — Add credentials to `config.local.php`

Edit CS-Cart's `config.local.php` (in the CS-Cart root, beside `config.php`). Add
these lines among the existing `$config[...]` entries:

```php
$config['quissly_bearer_token']     = 'PASTE_YOUR_BEARER_TOKEN';
$config['quissly_private_key_path'] = '/etc/quissly/quissly_private.pem';
$config['quissly_environment']      = 'prod';
$config['quissly_project_id']       = 'PASTE_YOUR_PROJECT_ID';    // for the Quissly Admin Panel + QChat
$config['quissly_account_email']    = 'you@example.com';          // for the Quissly Admin Panel + QChat
```

The first three drive search and catalog sync. The last two are what the embedded
**Quissly Admin Panel** page and the QChat widget need to sign in to your Quissly
account; without them those two features stay unavailable and everything else works.

Save, and **double-check the syntax** — a stray quote or missing semicolon here
will take the whole store down with a 500 error.

The add-on reads these at runtime; the private key is loaded into memory only when
signing a request, never stored in the database, never sent to the browser.

### Step 2 alternative — connect in one click

You can skip Steps 1 and 2 entirely. Install and activate the add-on first (Steps 3–5),
then open **Add-ons → Quissly → Configuration**: the block at the top asks for an email
address and offers **Connect to Quissly**. One press creates your Quissly account,
generates the RSA key pair inside the add-on, registers its public key with Quissly, finds
your chat agent and starts the initial catalog sync. The private key and API key are stored
**encrypted** (AES-256-GCM, using CS-Cart's own `crypt_key`) in the add-on's state table —
nothing to copy, nothing to paste, and no `config.local.php` edit.

Two things to know:

- **It can only be done once.** Connecting a store that is already connected is refused —
  a second account would strand everything already synced to the first. Quissly also
  refuses an email address that already has a Quissly account.
- **`config.local.php` wins.** If it sets `quissly_bearer_token`, those credentials are
  used and the stored ones are ignored. If you later change CS-Cart's `crypt_key`, the
  stored credentials can no longer be decrypted; the add-on says so rather than failing
  silently, and you reconnect.

## Step 3 — Install the add-on files

**A. Via SSH (recommended).** Copy the package to the server and extract it from the
CS-Cart root so files land under `app/addons/quissly_search/`:

```bash
cd /path/to/cscart            # the CS-Cart root (where config.php lives)
tar -xzf /path/to/quissly_search-1.0.0.tgz
ls app/addons/quissly_search/addon.xml   # should now exist
```

**B. Via admin panel.** Admin → **Add-ons → Manage add-ons** → click **"+"** (top
right) → **Local** → upload `quissly_search-1.0.0.tgz`.

## Step 4 — Activate and test

1. Admin → **Add-ons → Manage add-ons** → find **Quissly Search** → set it to
   **Active**.
2. Open **Add-ons → Quissly → Configuration** (also reachable as the add-on's
   **Settings**). If you have not added credentials, connect here now — see the *Step 2
   alternative* above. The status block shows **ACTIVE / INACTIVE** with the reason and
   your configured environment; right after connecting it reads *INACTIVE — initial sync
   pending*, which is expected.
3. Click **Test connection**. It performs one real signed search and reports the
   exact result — a success means your token, key, and environment all line up. A
   401/402/403 tells you precisely what to fix (see Troubleshooting).

## Step 5 — Clear the cache

```bash
# Option 1: load the storefront once with ?cc appended
#   https://YOURSTORE/?cc
# Option 2:
rm -rf /path/to/cscart/var/cache/*
```

## Step 6 — Run the initial catalog sync

**Until this is done, Quissly search is off.** Shoppers keep native CS-Cart search, and
the status block reads **INACTIVE — initial sync pending**. This is deliberate: Quissly
cannot answer searches about a catalog it has not indexed, and an empty answer would look
like "no results" to your shoppers.

1. Open **Add-ons → Quissly → Dashboard**.
2. Under *Catalog sync*, click **Start initial sync**. Each press sends for about 25
   seconds and then returns, so on a large catalog press it again (the button becomes
   **Send queued changes now**) until the queue is empty.
3. Wait for Quissly to confirm the products. The dashboard's *In Quissly* counter and its
   *waiting* line track this; it usually takes a few minutes after the last batch.
4. When the first sync is confirmed, search switches to Quissly by itself — the status
   block flips to **ACTIVE**.

## Step 7 — Keep the catalog in sync (cron)

Saving, deleting, re-stocking or de-listing a product only *queues* it — the add-on never
calls Quissly from inside a product save. Queued changes go out when you press a sync
button, or automatically from the server's cron. The Dashboard's *Automatic sync* section
prints the exact line for your install and says whether cron has been running; it looks
like:

```
*/5 * * * * php /path/to/cscart/admin.php --dispatch=quissly_search.cron
```

Add it to the crontab of the user that owns the CS-Cart files. Without it, product changes
sit in the queue until someone opens the Dashboard and presses a button.

## Step 8 — Verify

Search the storefront for a term you know exists. Results render through the normal
theme. To confirm they're coming from Quissly rather than native search, try a loose
or semantic query that plain keyword matching wouldn't handle well — Quissly should
still surface relevant products.

**Then confirm the safety net (recommended, ~2 minutes).** Temporarily point the key
at a path that doesn't exist — in `config.local.php`, set
`$config['quissly_private_key_path'] = '/tmp/nope.pem';` — clear the cache, and search
again. The search results page should load **normally as native CS-Cart search**, with
no error or blank page. (If your catalog has no match for that term, a clean "0
results" page is the correct outcome — you're verifying it doesn't *crash*, not that it
finds products.) Restore the real key path and clear the cache when done.

## How search behaves

A few things to expect on a Quissly-powered search results page, so they don't look
like defects:

- **Results are ordered by Quissly's ranking, and the "Sort by" dropdown is shown but
  restricted to the orderings Quissly honors for this account:** **Relevance**
  (the default) and **Price** (low→high and high→low). Date, popularity, and name
  sorts are **intentionally not offered** — for this account Quissly returns its
  relevance order for those, so showing them would be a control that silently does
  nothing. The per-page count selector and the grid/list layout toggle remain
  available as usual. (Category and other non-search pages keep their full sort
  controls.)
- **Interface strings:** every admin and storefront string ships in English, Georgian and
  Russian.
- **Filtered searches use CS-Cart's own search.** When a shopper has applied a filter, a
  category, a price range, a product code or a vendor, the add-on steps aside so the
  refinement is honoured; `?quissly_debug=1` shows `filter:<name>` as the reason.
- **Product titles, descriptions, and prices** are rendered by CS-Cart itself in the
  shopper's language, exactly as on any other page — the add-on only changes which
  products appear and their order.

## Storefront features and their switches

**Add-ons → Quissly → Configuration** carries six switches. The overlay, voice, image and
QChat features only appear once Quissly is actually answering searches — connected, first
sync confirmed, QSearch on — so nothing Quissly-related shows on the storefront before
Step 6 is done.

| Switch | Default | What it does |
|---|---|---|
| **QSearch: Quissly answers storefront search** | on | The search interception itself. Off = native CS-Cart search. |
| **Search overlay takes over the theme's search box** | on | Clicking the theme's search box opens a full-width Quissly search bar at the top of a blurred page (Escape, the backdrop or the close button dismisses it). Off = the theme's own box is left alone and the mic/camera buttons are placed inside it. A theme with no search box gets a search button in the header either way. |
| **Search button mount point** | empty | A CSS selector for the header control that search button should sit beside. Empty = the add-on picks the cart, account or language control, else a floating button. |
| **Quick Recommendations** | off | **Not built into the CS-Cart add-on yet** — the switch (and its *Quick Recommendations style*) is there for parity and changes nothing. |
| **Voice search** | off | A mic button; up to 5 seconds of audio per query. |
| **Image search** | off | A camera button (and Ctrl+V / Cmd+V paste into the overlay's search bar); the photo is resized to 1024 px before it is sent. |
| **QChat assistant** | off | Loads the Quissly chat widget on every storefront page, with a bridge so the chat's "Add to cart" reaches CS-Cart's real cart. Needs the account's chat agent, which the add-on looks up for you. |

Voice and image searches go through the add-on's own storefront endpoints, which are
**off unless their switch is on**, capped in size, and throttled to **10 requests per
minute per visitor**. A results page from voice or image search is addressed by a token
that lives for **10 minutes**; after that the page shows a notice instead of stale
results.

**Search bar suggestions.** While the overlay's search bar is empty it types example
searches into it, letter by letter and in random order, so shoppers see what they can ask;
they stop the moment a shopper types. After the first catalog sync the add-on's cron
generates five from your catalog (each checked to find products). Edit them in the
**Search bar suggestions** block on the Configuration page — one per line, up to 20 (a live
"N of 20" under the list) — switch the typing off, or switch back to the generated list.
The list is kept in Quissly, not in CS-Cart.

There is also a **Catalog data → Product attributes sent to Quissly** checklist at the
bottom of the Configuration page: every product feature your store has, ticked where the
storefront shows it. Untick one to keep it out of Quissly, or tick a hidden one to send it.
Saving a change queues a full re-sync.

## The admin pages

**Add-ons → Quissly** has three pages:

- **Configuration** — the setup/connection block, the ACTIVE/INACTIVE status with the
  reason, **Test connection**, the six switches and the Catalog data checklist.
- **Dashboard** — search status, connection and environment, which features are on, the
  catalog-sync counters (in Quissly / queued / waiting on Quissly / last run), the sync
  buttons, whether automatic sync (cron) is running plus the exact crontab line, and the
  recent sync log.
- **Quissly Admin Panel** — Quissly's own console, embedded and signed in for you. It needs
  your project id and account email: one-click Connect stores them, or set
  `quissly_project_id` / `quissly_account_email` in `config.local.php` for the manual setup.

## Where problems show up

- The **Configuration** page shows the current connection status, and the **Dashboard**
  shows the sync state and the recent log. If a shopper hits an authentication or billing
  problem (401/402/403), it's recorded and shown as an alert there — so you'll see it in
  admin, not only in logs.
- Detailed failures are logged under **Administration → Logs**.
- **Which engine answered a search:** add `?quissly_debug=1` to a search URL. A small badge
  on the page says whether Quissly or CS-Cart answered, and why Quissly stepped aside; the
  same answer is in the `X-Quissly-Search` response header. Shoppers never see it.

## Rolling back

- **Temporary off:** set the add-on to **Disabled** (or its Quissly search switch off).
  Search instantly reverts to native CS-Cart search; no data is affected. This is the safe
  way to switch Quissly off.
- **Remove completely:** Uninstall from Manage add-ons, then optionally delete
  `app/addons/quissly_search/` (plus the other three directories) and the
  `config.local.php` lines.

  ⚠️ **If the store was connected with one-click Connect, uninstalling destroys your
  Quissly credentials.** They live only in `cscart_quissly_search_state`, and uninstall
  drops that table. They cannot be re-issued by reconnecting: Quissly refuses a second
  account for an email that already has one, so a reinstall + Connect with the same address
  fails with *Email already registered*. Before uninstalling, export those credentials or
  ask Quissly to re-key the account. Prefer **Disabled** for anything temporary.

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Status says **INACTIVE — initial sync pending**, and search results didn't change | The first catalog sync hasn't been confirmed yet. Dashboard → **Start initial sync**, then wait for Quissly to confirm the products (Step 6). |
| Search results didn't change | Cache not cleared (Step 5); add-on not Active or its Quissly search switch off; the first sync not confirmed (row above); or the page isn't the product-search results page. |
| Product edits don't reach Quissly | Automatic sync isn't running — install the crontab line the Dashboard prints (Step 7), or press **Send queued changes now**. |
| **401 Unauthorized** | Bearer token wrong, `X-Environment` not `prod`, or your public key isn't registered under `prod` on Quissly's side. Contact Quissly to confirm token + public key + environment match. |
| **403 Forbidden** | Key valid but the service isn't enabled on your plan, or the signature was rejected. Contact Quissly. |
| Every request fails / signature errors | Server clock drift > 1 minute — confirm NTP. |
| Store 500 error right after editing config | PHP syntax slip in `config.local.php`. |
| Works, but sometimes falls back to native | A Quissly request exceeded the timeout (by design it falls back after a few seconds). Check network/latency to `api.quissly.com`. |

## Security notes

- Keep the `.pem` outside the web root and `chmod 600`. Never commit it or the
  bearer token to version control.
- All request signing is server-side; the token and key are never exposed to the
  browser.
- **Who searched (for your privacy policy).** Each search tells Quissly who is searching, as
  the Quissly Shopify app does: `customer:<id>` for a signed-in customer, otherwise
  `guest:<random id>` from the `quissly_uid` cookie, plus the device type and operating system
  (from the browser). No name, email or IP address is sent. With the GDPR add-on active, a guest
  who declined **Quissly search** in the cookie banner — or, under its *explicit* consent mode,
  has not accepted yet — gets no cookie and is sent as anonymous. Searching works the same either
  way.

## Support

Questions, or a **Test connection** you can't resolve? Contact Quissly with the
relevant lines from **Administration → Logs** — the log messages never contain your
key or token, so they're safe to share.