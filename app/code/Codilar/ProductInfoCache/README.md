# Codilar_ProductInfoCache

A custom Magento cache type that stores product **name** and **description** separately
for each store view, plus a CLI command that proves one store's text can never be served
to another.

## Design

```
app/code/Codilar/ProductInfoCache/
├── registration.php
├── README.md
├── etc/
│   ├── cache.xml                       declares the codilar_product_info cache type
│   ├── di.xml                          registers the CLI command
│   ├── events.xml                      save/delete observers
│   ├── module.xml
│   └── webapi.xml                      the REST route
├── Api/
│   ├── Data/ProductInfoResultInterface.php   API response contract
│   └── ProductInfoApiInterface.php           API service contract
├── Model/
│   ├── Cache/Type/ProductInfo.php      the cache type (TagScope)
│   ├── Data/ProductInfoResult.php      API response carrier
│   ├── ProductInfoApi.php              Web API implementation
│   └── ProductInfoCache.php            the service: read-through cache
├── Observer/
│   └── CleanProductInfoCache.php
└── Console/Command/
    └── VerifyCacheCommand.php
```

### The cache type

`Model\Cache\Type\ProductInfo` extends `TagScope`, which stamps `CODILAR_PRODUCT_INFO`
onto every entry written through it and confines every `clean()` to that tag. That is what
makes the cache a closed world: flushing it cannot touch another module's entries, and
another module flushing its own type cannot touch ours, even though both sit in the same
physical backend.

Declaring it in `etc/cache.xml` is what gives it a row in **System > Cache Management**
and makes `bin/magento cache:clean codilar_product_info` work with no extra code.

### Why it is store safe

The store id is part of the key, not an afterthought:

```
codilar_product_info_{productId}_{storeId}
```

Two store views are therefore two unrelated entries. Whichever store warms the cache
first cannot poison the other, because the other never reads that key.

Just as important, the miss path passes the store explicitly:

```php
$this->productRepository->getById($productId, false, $storeId);
```

Not `getById($productId)` with the current store left implicit. On the CLI, in a cron, or
inside a queue consumer, "the current store" is whatever was set last - usually the admin
store - and that is exactly how English text ends up cached under an Arabic key.

### Invalidation

| Trigger | Effect |
| --- | --- |
| `catalog_product_save_after` | `clean($productId)` |
| `catalog_product_delete_after` | `clean($productId)` |
| Lifetime | 86400 s backstop for changes made outside Magento |

`clean()` cleans by the per product tag `CODILAR_PRODUCT_INFO_{productId}`, which removes
**every store view** of that product in one call - no need to know how many stores exist
or which were ever warmed.

The observer swallows its own exceptions: a cache that will not clear must never stop a
merchant saving a product, and the entry expires on its own anyway.

## Requirements traceability

Each requirement, the code that satisfies it, and how to see it for yourself.

| # | Requirement | Implemented in | Evidence |
| --- | --- | --- | --- |
| 1 | Retrieve product name and description | `ProductInfoCache::getProductInfo()` | the verify command prints both per store view |
| 2 | Cache data separately for each store view | the key includes the store id, so N stores = N entries | `3 store views produced 3 distinct cache entries` |
| 3 | Include store ID in the cache key | `getCacheKey()` -> `codilar_product_info_{productId}_{storeId}` | each key printed, annotated with its store id |
| 4 | English never shown in Arabic (and vice versa) | `getById($id, false, $storeId)` - store passed explicitly | the command reads each entry **back out of the cache** and asserts the `store_id` inside it matches the key |
| 5 | Suitable cache lifetime | `CACHE_LIFETIME` + save/delete observers | printed in the verdict and returned by the API as `cache_lifetime`, `age_seconds` and `expires_in_seconds` |

### Why requirement 4 is the one that actually needs care

Putting the store id in the key stops two stores sharing an entry. It does **not**, on its
own, stop the wrong text going *into* an entry. That depends on this line:

```php
$this->productRepository->getById($productId, false, $storeId);
```

Not `getById($productId)`. On the CLI, in a cron, or in a queue consumer, "the current
store" is whatever was set last - usually the admin store - so the implicit form happily
loads English text and files it under the Arabic key. The key would look right and the
contents would be wrong.

That is why the verify command does not just compare what the service returned. It calls
`loadRaw()` to pull each entry straight back out of the cache and asserts the `store_id`
recorded *inside the cached payload* matches the key it was found under.

### Sample output

```
 STORE VIEW 1 - UAE English (ae_en)
   locale        : en_US
   cache key     : codilar_product_info_3_1   <- ends in _1, the store id
   before call   : CACHE MISS
   name          : Tresa 8 Seater Dining table
   stored under  : store_id=1  [matches this store]

 STORE VIEW 3 - UAE Arabic (ae_ar)
   locale        : ar_SA
   cache key     : codilar_product_info_3_3   <- ends in _3, the store id
   before call   : CACHE MISS
   name          : طاولة طعام تريسا لثمانية أشخاص
   stored under  : store_id=3  [matches this store]

 VERDICT
 [OK]    1. name and description retrieved
 [OK]    2. 3 store views produced 3 distinct cache entries
 [OK]    3. every key ends in _<storeId>
 [OK]    4. each key holds data belonging to its own store
 [OK]    5. lifetime 86400s (24h)

 PASS - every requirement verified.
```

When every store view happens to return the same text (no store scoped name set yet), the
command still passes requirements 1-5 but prints a NOTE saying the run cannot demonstrate
the difference visually - rather than claiming a proof it did not make.

## REST API

```
GET /rest/V1/codilar/product-info/:productId/store/:storeId
```

Anonymous, so you can open it straight in a browser. It returns the cached name and
description for that store view **plus the diagnostics that show how the answer was
obtained**: `source` (CACHE or BACKEND), `time_ms`, `cache_key` and `cache_lifetime`.

### Example

```bash
curl -s "http://midhun2.test/rest/V1/codilar/product-info/3/store/3" | json_pp
```

```json
{
    "product_id": 3,
    "store_id": 3,
    "store_code": "ae_ar",
    "locale": "ar_SA",
    "name": "طاولة طعام تريسا لثمانية أشخاص",
    "description": "طاولة طعام أنيقة تتسع لثمانية أشخاص...",
    "source": "BACKEND",
    "cache_key": "codilar_product_info_3_3",
    "cache_lifetime": 5,
    "time_ms": 36.487,
    "age_seconds": 0,
    "expires_in_seconds": 5
}
```

Call it again and `source` flips to `CACHE` while `time_ms` collapses:

```
store 1  ae_en  en_US  BACKEND  251.688 ms   ->  CACHE  0.049 ms
store 2  in_en  en_IN  BACKEND   39.912 ms   ->  CACHE  0.042 ms
store 3  ae_ar  ar_SA  BACKEND   36.487 ms   ->  CACHE  0.057 ms
```

### Watching the lifetime expire

`CACHE_LIFETIME` is **5 seconds**, small enough to watch the whole cycle by hand.
`age_seconds` and `expires_in_seconds` turn it into a countdown, so you can see the entry
ageing rather than guessing when it will go:

```
 0.1s  source=BACKEND  age=0s  expires_in=5s    39.930 ms
 1.1s  source=CACHE    age=1s  expires_in=4s     0.048 ms
 2.2s  source=CACHE    age=2s  expires_in=3s     0.050 ms
 3.2s  source=CACHE    age=3s  expires_in=2s     0.051 ms
 4.3s  source=CACHE    age=4s  expires_in=1s     0.049 ms
 5.4s  source=BACKEND  age=0s  expires_in=5s    39.276 ms   <- expired, reloaded
 6.4s  source=CACHE    age=1s  expires_in=4s     0.048 ms
```

Five seconds of hits at ~0.05 ms, then one 39 ms trip to the database, then five more
seconds of hits. That is the cache doing its job, visible in one screen.

**This is a demonstration value.** Put `CACHE_LIFETIME` back to `86400` for production -
one line in `Model/ProductInfoCache.php`. The lifetime is only a backstop anyway, since
the save and delete observers invalidate entries as soon as a product actually changes.

### How `source` is determined

It is sampled **before** the read:

```php
$wasCached = $this->productInfoCache->isCached($productId, $storeId);
$info = $this->productInfoCache->getProductInfo($productId, $storeId);
```

Asking afterwards would report `CACHE` on every single call, including the one that just
populated it - a diagnostic that always says yes is worse than none.

### Errors

| Request | Response |
| --- | --- |
| unknown store id | `404 The store that was requested wasn't found.` |
| unknown product id | `404 The product with ID "99999" does not exist.` |
| `productId` of 0 | `404 Product id must be a positive integer.` |

### Security note

The route is declared `<resource ref="anonymous"/>` so it is open. It exposes only product
name and description, which the storefront already shows to everyone. To lock it down,
change the resource in `etc/webapi.xml`:

```xml
<resource ref="Magento_Catalog::products"/>
```

and call it with an admin bearer token.

### Watch out: the root element is `<routes>`

`etc/webapi.xml` uses `<routes>`, not `<config>`. Magento's merge keys route identity on
the XPath `/routes/route`, so a `<config>` root makes every merge fail with a fairly
opaque `DOMXPath::query(): Invalid expression` during an unrelated request.

## Install

```bash
bin/magento module:enable Codilar_ProductInfoCache
bin/magento setup:upgrade
bin/magento setup:di:compile      # needed: the CLI command is wired through di.xml
bin/magento cache:enable codilar_product_info
bin/magento cache:flush
```

**`cache:enable` is not optional.** A newly declared cache type is registered *disabled*
(`cache:status` shows `codilar_product_info: 0`). While it is disabled `save()` is a no-op
and `load()` always returns false, so the verify command reports `CACHE MISS` forever and
the cache looks broken when it is merely switched off.

## Test steps

### 1. The type is registered

```bash
bin/magento cache:status | grep codilar_product_info
```

It also appears in **System > Cache Management** as "Codilar Product Info".

### 2. Miss, then hit

```bash
bin/magento cache:clean codilar_product_info
bin/magento codilar:product-cache:verify <product_id>   # every store: CACHE MISS
bin/magento codilar:product-cache:verify <product_id>   # every store: CACHE HIT
```

The command fails loudly if a returned `store_id` does not match the store it asked for.

### 3. Prove the stores really are separate

Give the product a different name in one store view (admin, switch the store view scope
to Arabic, untick **Use Default Value** on Name, save), then:

```bash
bin/magento codilar:product-cache:verify <product_id>
```

Expect `PASS: each store view returned its own name.` Until a store scoped name exists
the command prints a NOTE instead, because every store is showing the same default value
and the run proves nothing either way.

### 4. Invalidation works

Run verify (all HIT), save the product in admin, run verify again - every store should be
back to MISS, because the save observer cleaned the per product tag.
