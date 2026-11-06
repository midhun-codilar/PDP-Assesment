# Codilar_RecentProductSync

Keeps a flat, store-scoped copy of catalogue data in `codilar_search_product` for a custom
search integration, and renders the most recently updated products on the storefront.

The table is kept current two ways:

* **`catalog_product_save_after` / `catalog_product_delete_after` observers** — real time, so
  an edit in the admin is reflected before the page reloads.
* **A 15-minute cron** (`codilar_recent_product_sync`) — the safety net for everything the
  observers cannot see: imports that write SQL directly, a save that threw, a restored dump,
  scheduled-update flips.

## Folder structure

```
app/code/Codilar/RecentProductSync/
├── Block/
│   └── RecentProducts.php                      storefront block, reads the side table only
├── Console/Command/
│   └── SyncProductsCommand.php                 bin/magento codilar:search:sync
├── Cron/
│   ├── PurgeExpiredRows.php                    the 1-minute expiry sweep
│   └── SyncProducts.php                        the 15-minute job
├── Model/
│   ├── Cache/
│   │   ├── Invalidator.php                     evicts cached pages when rows change
│   │   └── TagSource.php                       tag carrier for clean_cache_by_tags
│   ├── ProductSyncService.php                  upsert / delete / expire
│   ├── RowLifetime.php                         the TTL window, shared writer+reader
│   ├── SearchProduct.php                       model
│   ├── SyncState.php                           last-synced cursor
│   ├── Synchronizer.php                        the incremental run (cron + CLI share it)
│   └── ResourceModel/
│       ├── SearchProduct.php
│       └── SearchProduct/Collection.php
├── Observer/
│   ├── ProductSaveAfter.php
│   └── ProductDeleteAfter.php
├── etc/
│   ├── crontab.xml
│   ├── db_schema.xml
│   ├── db_schema_whitelist.json
│   ├── di.xml                                  logger virtual type + console command
│   ├── events.xml
│   └── module.xml
├── view/frontend/
│   ├── layout/cms_index_index.xml              places the block on the homepage
│   └── templates/recent-products.phtml
├── composer.json
├── registration.php
└── README.md
```

## Tables

### `codilar_search_product`

One row per product **per store view** — that is what lets each storefront render its own
language without a second lookup.

| Column | Notes |
| --- | --- |
| `entity_id` | PK, auto increment |
| `product_id` | FK → `catalog_product_entity.entity_id`, `ON DELETE CASCADE` |
| `store_id` | FK → `store.store_id`, `ON DELETE CASCADE` |
| `sku`, `name`, `short_description`, `status`, `visibility`, `url_key`, `image` | store-scoped values |
| `price` | regular (list) price |
| `final_price` | price actually payable, after special price and catalogue rules |
| `product_updated_at` | the product's own `updated_at` |
| `synced_at` | when this row was written |

Unique index on `(product_id, store_id)` — that is what makes `insertOnDuplicate` an upsert.
Secondary index on `(store_id, product_updated_at)` serves the storefront block's query.

### `codilar_search_sync_state`

A `state_key` / `state_value` pair. The cron keeps `last_synced_at` here.

## Install

```bash
bin/magento module:enable Codilar_RecentProductSync
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f   # production mode only
bin/magento cache:flush
```

Then seed the table once, so the block has something to show before the first cron tick:

```bash
bin/magento codilar:search:sync --full
```

## CLI

```bash
bin/magento codilar:search:sync                  # incremental, same as the cron
bin/magento codilar:search:sync --full           # ignore the cursor, walk the whole catalogue
bin/magento codilar:search:sync --batch-size=1000
```

## Logging

Everything this module logs goes to `var/log/recent_product_sync.log` (a `Monolog` virtual
type in `etc/di.xml`), not `system.log`.

```bash
tail -f var/log/recent_product_sync.log
```

## Row expiry (TTL)

**Rows are deleted `RowLifetime::TTL_SECONDS` after they are written — currently 5 seconds.**

This is a hard delete, not an archive or a hidden flag. Consequences, stated plainly:

* the table is **empty most of the time**;
* a row only comes back when its product is saved again, or a `--full` re-sync rewrites it;
* the storefront block therefore renders **nothing** most of the time — which is correct
  for this setting, not a bug;
* the custom search integration reading this table sees the same empty table.

If that is not what you want, raise the constant. `RowLifetime::TTL_SECONDS = 604800` gives
a seven-day window and the block behaves like a normal "recently updated" list again.

### What enforces it

| Where | What it does |
| --- | --- |
| `Cron\PurgeExpiredRows` (`* * * * *`) | deletes expired rows, evicts the cached pages |
| `Synchronizer::run()` | also purges, so the 15-minute sync and the CLI sweep too |
| `Block\RecentProducts` | *filters* on the same TTL at read time |

The block filter is what makes the window exact. One minute is the tightest interval
Magento's scheduler offers — the OS crontab calls `cron:run` at that rate — so with a TTL
under a minute a row can physically outlive its window by up to 60 seconds. The filter means
a visitor never sees one of those stragglers.

Both the deleting side and the filtering side read the window from `RowLifetime`, so they
cannot disagree about what "expired" means.

### Nothing expires unless something runs the cron

The sweep is a cron job, so it only happens if Magento's scheduler is running. With no
crontab entry, rows sit in the table forever and the feature looks broken:

```bash
bin/magento cron:install          # the real fix
# or, to force a sweep by hand right now:
bin/magento codilar:search:sync   # the sync purges too
```

Note that `bin/magento cron:run` on an empty `cron_schedule` only *populates* the schedule —
it does not execute anything. **Run it twice.** The first call creates the rows, the second
runs the jobs that are due. That trips people up constantly.

### Timestamps are UTC, and your SQL client will not show them that way

`synced_at` and `product_updated_at` are MySQL `TIMESTAMP` columns, which MySQL converts
using the **session** timezone on both write and read.

Magento forces `SET time_zone = '+00:00'` on its own connection
(`vendor/magento/framework/DB/Adapter/Pdo/Mysql.php`), so everything this module writes and
reads is UTC and matches `gmdate()`. A `mysql` CLI, phpMyAdmin or a GUI client connects with
`time_zone = SYSTEM` instead, so the *same row* shows shifted by your server's offset:

```
Magento (session tz +00:00) : 2026-11-05 06:33:12
mysql client (tz SYSTEM/IST): 2026-11-05 12:03:12   <- same row, +5:30
```

Nothing is wrong with the data. If you want your client to agree, run `SET time_zone =
'+00:00';` in that session before querying. The storefront separately renders
`product_updated_at` in the store's configured timezone (`general/locale/timezone`), which
here is `Asia/Kolkata` — so a row written at 06:33 UTC correctly displays as 12:03 PM.

### The full page cache caveat

A page already sitting in the FPC does not re-render just because time passed. Sequence:

```
save a product        -> rows written, cache evicted, block shows the item
+6s, request again    -> served from FPC, STILL shows the item
purge cron runs       -> rows deleted, cache evicted
next request          -> block renders nothing
```

So the *visual* disappearance follows the purge, not the TTL — up to a minute late. Exact
5-second visual expiry would need the block marked `cacheable="false"` (and that makes the
whole page uncacheable) or served as a private/ESI block. Say so if you need that.

## Prices and the discount badge

The block prints the final price, the regular price struck through, a `-N% OFF` badge and
the cash saving. Nothing about the discount is stored — it is derived from the two prices,
the same calculation the product page uses:

```php
$discount = $regular > 0 && $final < $regular ? (int)round((1 - $final / $regular) * 100) : 0;
```

Both prices come from Magento's pricing framework (`regular_price` and `final_price`),
not from the `price` attribute. That matters for three cases the attribute gets wrong:

* a **configurable or bundle** has no usable price of its own — the framework resolves it
  to the cheapest child (on this install the Niecel sofa's parent even carries a stray
  `price = 0`, and it still prices correctly at 15,000 → 11,190);
* **special_price outside its from/to dates** must not count as a discount;
* **catalogue price rules** never touch the price attribute at all.

It is the same source `Codilar\Twoxl\ViewModel\Pdp::getDiscountPercent()` reads, so this
block and the product page cannot disagree.

**Cost:** this builds a price model per product per store view, which is heavier than
reading an attribute. Fine for a sync job over a changed-products delta; if you ever point
this at a large catalogue, read `price` / `final_price` out of `catalog_product_index_price`
with a LEFT JOIN instead (left, not inner — an inner join silently drops anything the price
index has not caught up with).

### Saving a child refreshes its parent

A configurable shows its cheapest child's price, so editing a child changes what the parent
displays even though the parent's own `updated_at` never moves. `ProductSyncService` expands
every incoming id set with its parents from `catalog_product_relation`, which covers
configurable, grouped and bundle in one query.

Note that the parent's `product_updated_at` stays as the parent's own timestamp — a child
price edit corrects the parent's price without jumping it to the top of the list. `synced_at`
records when the row was actually rewritten.

## Cache invalidation

Rows are written with `ResourceConnection`, not with a model save, so the framework's
`FlushCacheByTags` plugin on `AbstractResource` never fires. Without the wiring below, an
edited product keeps showing its old name on the homepage until someone flushes by hand.

Two halves make it work:

1. **`Block\RecentProducts` implements `IdentityInterface`**, so every cached page that
   renders the block records its tags (`Magento\PageCache\Model\Layout\LayoutPlugin`
   turns them into the `X-Magento-Tags` header, which the FPC kernel stores against the
   entry — Magento strips that header before the response is sent, so you will not see it
   in `curl -D -`).
2. **`Model\Cache\Invalidator` cleans those tags** after any write. It cleans the default
   pool through `CacheInterface` (block_html, collections) *and* dispatches
   `clean_cache_by_tags`, which is the only route to the page cache — built-in and Varnish
   each have their own pool, reachable only through their core observers.

### Why the tag identifies the list, not the products

`SearchProduct::CACHE_TAG` (`codilar_search_product`, plus `codilar_search_product_<storeId>`)
names the **list**, not its current members. Per-product `cat_p_<id>` tags alone cannot work
here: a product outside the current top N has no tag on the cached page, so editing it — the
very thing that should push it to the top of the list — would leave the page untouched. The
block declares `cat_p_<id>` for the products it shows as well, so core flows that clean those
tags refresh us too, but the list tag is what makes the feature correct.

Saving a product bumps its `updated_at` for every store view, so in the usual case every
store's page is evicted — correctly, since the list is ordered by `updated_at` and every
store's ordering really did change. The per-store tag narrows it when a product is eligible
in one website only.

### Verifying it

```bash
bin/magento cache:flush
curl -s https://<host>/ | grep -o 'recent-product-sync__name">[^<]*'   # warm + note the names
# edit a product name in the admin, save, then WITHOUT flushing anything:
curl -s https://<host>/ | grep -o 'recent-product-sync__name">[^<]*'   # new name must appear
```

If it does not, check that `full_page` caching is the built-in type (`bin/magento
cache:status`, and `system/full_page_cache/caching_application` = 1) or, on Varnish, that the
purge host and port are reachable.

## Test steps

### 1. Schema is in place

```bash
bin/magento setup:db:status
mysql -e "DESCRIBE codilar_search_product;" <db>
mysql -e "SHOW INDEX FROM codilar_search_product WHERE Key_name LIKE '%PRODUCT_ID_STORE_ID%';" <db>
```

### 2. Run the sync manually

```bash
bin/magento codilar:search:sync --full
# or drive the real cron job:
bin/magento cron:run --group=default
```

Expect a `window … scanned … upserted … deleted` summary on stdout and the same line in
`var/log/recent_product_sync.log`.

### 3. Save a product and watch the row change (observer path)

```sql
SELECT store_id, sku, name, price, product_updated_at, synced_at
FROM codilar_search_product WHERE sku = '<your-sku>';
```

Edit that product's **name** in the admin for one store view, save, and re-run the query.
The row for that `store_id` shows the new name and a fresh `synced_at` — with no cron run in
between. Rows for the other store view are untouched if you scoped the edit.

### 4. Verify the ineligible / delete cases

| What you do | Expected |
| --- | --- |
| Set Enable Product → **No**, save | the product's rows disappear from the table |
| Set Visibility → **Not Visible Individually**, save | rows disappear |
| Un-tick the product's website, save | rows for that website's store views disappear |
| Re-enable and re-save | rows come back |
| Delete the product | all its rows disappear (`catalog_product_delete_after`, plus the FK cascade) |

```sql
SELECT COUNT(*) FROM codilar_search_product WHERE product_id = <id>;
```

### 5. Verify the cursor moves

```sql
SELECT * FROM codilar_search_sync_state WHERE state_key = 'last_synced_at';
```

Note the value, run `bin/magento codilar:search:sync`, re-query: the timestamp has advanced to
the moment that run *started*. Force a failure (e.g. `RENAME TABLE codilar_search_product TO
codilar_search_product_x;`) and run again — the command reports the failure, the log records
it, and the cursor is **unchanged**, so the window replays once you rename the table back.

### 6. Verify the storefront block, both store views

Visit the homepage on each store view:

* `https://<host>/` (store 1, `ae_en`)
* `https://<host>/in_en/` (store 2, `in_en`) — or whatever store code / domain the second
  store view uses

Both show "Recently Updated Products", newest first. Each store view shows the name stored
for **its own** `store_id`:

```sql
SELECT store_id, product_id, name FROM codilar_search_product
WHERE product_id = <id> ORDER BY store_id;
```

Set a store-view-scoped name (Arabic, say) on store 2, save, reload store 2's homepage — the
Arabic name appears there while store 1 keeps the English one.

### 7. Verify the storefront updates without a manual cache flush

```bash
bin/magento cache:flush
curl -s https://<host>/ -o /dev/null          # warm the page cache
# edit a product's name in the admin and save
curl -s https://<host>/ | grep -o 'recent-product-sync__name">[^<]*'
```

The new name must appear with no `cache:clean` in between. See **Cache invalidation** above.

### 8. Verify row expiry

```bash
bin/magento codilar:search:sync --full
mysql -e "SELECT COUNT(*) FROM codilar_search_product;" <db>   # rows present
sleep 6
mysql -e "SELECT COUNT(*) FROM codilar_search_product;" <db>   # still there, nothing swept yet
bin/magento cron:run --group=default                            # purge job runs
mysql -e "SELECT COUNT(*) FROM codilar_search_product;" <db>   # 0
grep 'Expiry sweep' var/log/recent_product_sync.log
```

The storefront block renders nothing once rows are past their TTL, even before the sweep —
that is the read-time filter. See **Row expiry (TTL)** above.

### 9. Verify the cron is registered

```bash
bin/magento cron:install
grep codilar_recent_product_sync -r var/log/
mysql -e "SELECT job_code, status, scheduled_at, executed_at FROM cron_schedule
          WHERE job_code='codilar_recent_product_sync' ORDER BY schedule_id DESC LIMIT 5;" <db>
```

## Notes and known trade-offs

* **Price.** Read through the pricing framework, not the price index — see **Prices and the
  discount badge** above for why, and for the cost that buys.
* **Images.** The table stores the image *path*; the block builds a media URL to the original
  upload rather than a resized cache entry, because generating a cached thumbnail needs a
  loaded product object — which the side table exists to avoid. Size it with CSS, or add a
  resize step if art direction demands it.
* **Website re-assignment via mass action** does not always bump `catalog_product_entity.updated_at`,
  so the incremental cron can miss it. The save observer catches the normal path; schedule a
  `--full` run nightly if your catalogue is managed that way.
* **`catalog_product_save_after`** fires inside the save transaction. The observer swallows
  its own exceptions on purpose: a search side table must never be the reason a merchant
  cannot save a product.
