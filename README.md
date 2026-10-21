# Quissly for CS-Cart

Quissly's AI search, chat and product discovery for your CS-Cart store. When a shopper
searches, Quissly decides which products match and in what order; **your own theme still shows
them**, exactly as it shows any search today. If Quissly ever cannot answer, CS-Cart's own search
answers instead, so search never breaks.

The add-on edits none of your files (theme, templates, catalog, prices or checkout). Through
CS-Cart's own hooks it adds a search overlay over your theme's search box and, when you switch
them on, voice search, image search and the QChat shopping assistant.

## Requirements

- **CS-Cart 4.21** (tested on 4.21.1). Older 4.x versions have not been tested yet - contact
  Quissly before installing on one.
- **PHP 7.4 or newer**, with the `curl`, `openssl`, `json`, `zip` and `sodium` extensions (the
  last two are for automatic updates; most hosts have all five).
- Access to your CS-Cart admin. Server access is needed only to add the cron line (step 4).
- An accurate server clock (NTP on): every request to Quissly is signed with the time, and a clock
  more than a minute off is refused.

## Install in four steps

### 1. Upload the add-on

Download **[quissly-for-cs-cart.zip](https://github.com/quissly/quissly-for-cs-cart/releases/latest/download/quissly-for-cs-cart.zip)**
(always the latest version). In the CS-Cart admin open **Add-ons → Manage add-ons**, then on
4.11 click the **+** button (top right) and choose **Local**, on 4.21 click the **gear** (top right),
**Manual installation**, with **All storefronts** selected. Upload the zip, then make sure **Quissly Search** is
installed and **Active**.

### 2. Run Quissly Setup

Open **Add-ons → Quissly**. Until setup is finished, every Quissly page opens **Quissly Setup**:

1. **Your details** - your email (Quissly's account and billing emails go there; it cannot be
   changed later, and it must be on a real, public domain), your store name and a short "What do
   you sell?" (drafted from your catalog - check it). Quissly confirms the email: press **Send
   code** under it and enter the 6-digit code it emails you (it expires in 10 minutes; **Send a
   new code** after a minute). **Save & continue** creates your Quissly account and connects the
   store - there is nothing to copy or paste.
2. **Choose a plan** - Search or Chat, monthly or annual, paid by **Manual invoice** (the default)
   or **Credit card**. The free search plan starts at once. Once chosen, the plan is changed in
   **Billing**, not here.
3. **Go live** - Quissly builds your services (about a minute), then your products are sent to
   Quissly; this starts by itself and the page updates without a refresh. When everything shows
   done, press **Finish Setup**: Quissly now answers your store's search. **Do this later** saves
   setup with search left off.

### 3. Check your storefront

Search your store for something you sell. The results look as always - your theme renders them.
Add `?quissly_debug=1` to a search page's address to see which engine answered (a small badge,
visible only with that parameter).

### 4. Add the cron line

Background work - sending product changes to Quissly, shopping activity, update checks - runs
from a cron line. **Add-ons → Quissly → Dashboard → Automatic sync** prints the exact line for your
store; it looks like this:

```
*/5 * * * * php /path/to/cscart/admin.php --dispatch=quissly_search.cron
```

Add it to the crontab of the user that owns the CS-Cart files (or under *Cron jobs* in your
hosting panel). The Dashboard then says **Running**. Without it, changed products are sent every few
minutes while shoppers visit your store, or at once when you press a sync button on the Dashboard -
so a store nobody visits for a while waits that long.

## The Quissly menu

**Add-ons → Quissly** has four pages:

- **Configuration** - every switch and list described below, in folding sections.
- **Dashboard** - whether Quissly is answering your search, the connection (with **Test
  connection**), which features are on, the catalog sync (products in Quissly, queued, last run),
  the sync buttons, automatic sync (cron) and the sync log.
- **Quissly Admin Panel** - Quissly's own console, signed in for you.
- **Billing** - your plans and this month's usage, invoices with their PDFs, change plan, buy
  extra requests, automatic top-up, cancel or keep a plan, update your card and request a refund.
  Every charge is shown before you confirm it; payments go through Paddle, never through CS-Cart.

## Configuration

| Setting | Default | What it does |
|---|---|---|
| **QSearch: Quissly answers storefront search** | on (set by Finish Setup) | Off = CS-Cart's own search. |
| **Search overlay takes over the theme's search box** | on | Clicking your search box opens Quissly's search bar over the page. A theme without a search box gets a search button either way. |
| **Search button mount point** | empty | Where that search button goes (a CSS selector); empty = chosen for you. |
| **Voice search** | off | A microphone button (up to 5 seconds of speech). |
| **Image search** | off | A camera button; shoppers can also paste a photo. |
| **QChat assistant** | off | Quissly's chat on every page; its **Add to cart** puts products in your real CS-Cart cart. |
| **Quick Recommendations** | off | Products as the shopper types - under the search overlay's bar, or under your theme's search box - with their image, name and price from your store. Quick must also be switched on for your Quissly account. |
| **Shopping activity for Quissly analytics** | on | Sends product views, searches, adds to cart and wish list, and paid orders to Quissly's analytics (see *Privacy*). |
| **Search Examples** | generated | The example searches typed into the empty search bar, one list per storefront language, up to 20. Quissly writes them from your catalog after the first sync; edit, remove or reset them here. |
| **Search Suggestions** | on, Automatic | Buttons under the empty search bar. *Automatic* shows what Quissly generated; *Manual* shows your own, up to 10 per language. |
| **Show recent searches** | on | The shopper's last 5 searches beside the suggestions, each with a bin to remove it. Kept only in the shopper's own browser, never sent anywhere. |
| **Most Loved Products** | off | 8 product cards in the overlay, picked by you (plus 4 backups that step in when a pick is sold out or hidden). Click a field to browse your catalog, drag to reorder. |
| **Catalog data** | what your storefront shows | Which product features are sent to Quissly. Changing it sends your whole catalog again. |
| **Update check frequency** | 30 minutes | How often the add-on looks for a new version (5 minutes for testing, up to a day, or **Never**). |

A feature your plan does not include - the chat on a Search plan, or search on a Chat plan - is shown
off and greyed out with a link to Billing, and stays off on the storefront until you add it. Your
setting is kept for then.

## Updates

New versions install themselves. At the frequency above, the add-on checks Quissly's releases on
GitHub; a newer one is downloaded, **checked against Quissly's signature** (a file without it is
never installed), and put in place with the old files kept as a backup - any problem puts the
previous version back. Your settings, credentials and synced data are kept. The Dashboard's sync log
records each update ("Quissly updated from 1.0.7 to 1.0.8") and, if one is not arriving, why.

For this the add-on's files must be writable by the user the cron line runs as, and the server must
reach `github.com` and `raw.githubusercontent.com` over HTTPS. A store whose files are a git checkout is not updated automatically.

**Add-ons → Upgrades.** CS-Cart's own Upgrade center lists a new Quissly version as well (from the
first release after 1.0.26): **Quissly search 1.0.x** with a short note and a *What's new* link to
the release on GitHub. Click **Download**, then **Install**. It is the same signed release, checked
the same way before it is even listed - a file that fails the check is never offered, and PHP without
the sodium extension cannot check it, so nothing is offered (the page says why). Your settings and data
are kept, and if the update cannot be finished the previous version is put back. As for any upgrade
there, CS-Cart backs up the store first (unless you tick *Skip files and database backup*), e-mails the
administrator - the install stops if the e-mail cannot be sent - and closes the storefront while it
copies the files; Quissly opens it again when it is done. A version shows up there only once its release
is published on GitHub (a minute or two after the new version is announced).

Automatic updates stay the default. To update only from Add-ons → Upgrades, set **Update check
frequency** to **Never**. If both run, nothing breaks: whichever comes second finds the version already
installed.

## How search behaves

- **Order:** results come in Quissly's order. The *Sort by* menu offers **Relevance** (the
  default) and, of the sortings the store has turned on, **Price**, **Name**, **Popularity**,
  **Newest** and **On sale** - Quissly sorts all the matches, not just the first page.
- **Filters:** a price range (the price slider) is applied by Quissly, and the slider's range
  is taken from Quissly's results. When a shopper filters by anything else - category, vendor, a
  feature or a product code - CS-Cart's own search answers, so the filter is always respected.
- **Before the first sync** (or with QSearch off) shoppers get CS-Cart's own search, and nothing
  of Quissly appears on the storefront.
- **Languages:** product names, descriptions and prices are shown by CS-Cart in the shopper's
  language as always. The add-on's own texts are in English, Georgian and Russian.

## Privacy

- To count a returning visitor once, a guest gets one first-party cookie on their first search,
  `quissly_uid` (a random id, one year). With CS-Cart's **GDPR** add-on it is listed in the
  cookie banner as **Quissly search** under *Performance cookies*; a shopper who declines gets no
  cookie and is sent as anonymous.
- Each search tells Quissly `customer:<id>` for a signed-in customer or `guest:<random id>`, plus
  the device type and operating system. Shopping activity sends product ids, quantities, prices
  and order totals. **No name, email, address or IP address is ever sent.**
- Requests are signed on your server; no key or token ever reaches the browser.
- **Error reports:** when something in the add-on fails - a PHP error in its files, a call to Quissly
  that is refused or not answered, a Setup step, an error in one of its scripts in a shopper's browser
  - it tells Quissly what failed and where in the add-on, with the add-on's, CS-Cart's and PHP's
  versions, so Quissly can fix it. The same problem is sent at most once an hour, with a count. The
  messages carry no key, no shopper data, no email or IP address and no query text. A failure in the
  add-on never stops your store: the product is still saved, the cart and the order go on, and a search
  CS-Cart's own search answers.
- **Once installed** (and again when you connect), the add-on tells Quissly what the store runs on -
  your store's address, CS-Cart's and PHP's versions and settings, the add-ons and theme in use, the
  languages, currency and number of products - so Quissly can help if something does not work.

## Switching off and removing

- **Off for now:** set the add-on to **Disabled**, or switch QSearch off. Search goes back to
  CS-Cart's own at once; nothing is lost.
- **Remove:** uninstall it in **Manage add-ons**. ⚠️ This deletes the add-on's tables, and with
  them the store's Quissly connection, which cannot be made again with the same email. Contact
  Quissly first if you may want it back; prefer **Disabled** for anything temporary.

## Troubleshooting

| What you see | What to do |
|---|---|
| Search looks the same as before | Setup not finished (**Finish Setup**), QSearch off, or the first sync not done yet (Dashboard). Check with `?quissly_debug=1`. |
| Product changes do not reach Quissly | Add the cron line (step 4), or press **Send queued changes now** on the Dashboard. |
| Dashboard shows a 401, 402 or 403 | Quissly refused the store: the plan, payment or connection needs attention. **Test connection** says which; contact Quissly if it persists. |
| Every request fails | The server clock is off - turn NTP on. |
| An update is not arriving | The sync log says why (files not writable, GitHub unreachable). |
| Search sometimes falls back to CS-Cart's | Quissly took too long to answer (a few seconds); check the server's connection to `api.quissly.com`. |

Errors are also logged under **Administration → Logs**. The add-on never logs your key or token,
so log lines are safe to send to Quissly support. CS-Cart also logs every outgoing request there
("Requests: http" under Settings → Logging); for the add-on's requests to Quissly it keeps only
the address and the sizes, never what was sent or answered (before 1.0.19 it kept both - clear
Administration → Logs, or its "Requests" entries, if you ran an earlier version).

## What the add-on adds to your store

- Its own folders only: `app/addons/quissly_search/`, and `quissly_search` folders under
  `design/backend/`, `js/addons/` and `var/themes_repository/responsive/`, plus
  `var/quissly_search_update/` for an update's backup.
- Ten database tables starting `cscart_quissly_search_` (the sync queue, what Quissly holds, the
  sync log, the add-on's state - including the connection, encrypted with your store's own
  `crypt_key` - and small tables for voice/image results, rate limits and shopping activity).

## Manual connection (instead of Setup)

Only if Quissly gave you credentials to use instead of **Save & continue**: put the private key
outside the web root (readable only by the web-server user, `chmod 600`) and add to
`config.local.php`:

```php
$config['quissly_bearer_token']     = 'YOUR_BEARER_TOKEN';
$config['quissly_private_key_path'] = '/etc/quissly/quissly_private.pem';
$config['quissly_environment']      = 'prod';
$config['quissly_project_id']       = 'YOUR_PROJECT_ID';   // for the Admin Panel and QChat
$config['quissly_account_email']    = 'you@example.com';   // for the Admin Panel and QChat
```

These lines win over a Setup connection. Check the PHP syntax carefully - a mistake in
`config.local.php` takes the whole store down. Then run **Start initial sync** on the Dashboard.

## Support

Contact Quissly with what the Dashboard shows and the relevant lines from **Administration → Logs**.
