<?php
declare(strict_types=1);

namespace Codilar\RecentProductSync\Model;

use Codilar\RecentProductSync\Model\Cache\Invalidator;
use Codilar\RecentProductSync\Model\ResourceModel\SearchProduct as SearchProductResource;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Writes product rows into codilar_search_product.
 *
 * Both entry points - the save/delete observers and the cron - come through here, so
 * "eligible" is defined exactly once (see loadEligibleRows()) and the two paths can
 * never drift apart.
 *
 * Bulk writes go through ResourceConnection rather than the Model/Repository pair on
 * purpose: a cron batch is 500 products x N store views, and insertOnDuplicate does
 * that in one round trip instead of thousands of model saves.
 */
class ProductSyncService
{
    public const TABLE = SearchProductResource::TABLE_NAME;

    /**
     * Columns overwritten when a (product_id, store_id) row already exists.
     */
    private const UPDATE_COLUMNS = [
        'sku',
        'name',
        'short_description',
        'price',
        'final_price',
        'status',
        'visibility',
        'url_key',
        'image',
        'product_updated_at',
        'synced_at',
    ];

    private const ATTRIBUTES = [
        'name',
        'short_description',
        'price',
        'special_price',
        'special_from_date',
        'special_to_date',
        'status',
        'visibility',
        'url_key',
        'image',
        'small_image',
        'thumbnail',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Invalidator $cacheInvalidator,
        private readonly RowLifetime $rowLifetime
    ) {
    }

    /**
     * Sync the given products into the custom table for every store view.
     *
     * Products that are no longer eligible in a given store view have their row for that
     * store removed, so disabling or hiding a product cleans itself up on the next save.
     *
     * @param int[] $productIds
     * @return array{upserted:int, deleted:int}
     */
    public function syncProducts(array $productIds): array
    {
        $result = ['upserted' => 0, 'deleted' => 0];
        $productIds = $this->normalizeIds($productIds);
        if (!$productIds) {
            return $result;
        }

        // A configurable shows its cheapest child's price, so saving a child changes what
        // the parent displays even though the parent itself was never touched. Without
        // this the parent row keeps a stale price until the next full re-sync.
        $productIds = $this->withParents($productIds);

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $touchedStoreIds = [];

        // getStores() skips the admin store (ID 0), which is what we want.
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            $rows = $this->loadEligibleRows($productIds, $storeId);

            if ($rows) {
                $connection->insertOnDuplicate($table, $rows, self::UPDATE_COLUMNS);
                $result['upserted'] += count($rows);
                $touchedStoreIds[$storeId] = $storeId;
            }

            // Requested IDs that did NOT come back as eligible must not stay in the table.
            $staleIds = array_values(array_diff($productIds, array_column($rows, 'product_id')));
            if ($staleIds) {
                $removed = (int)$connection->delete($table, [
                    'store_id = ?' => $storeId,
                    'product_id IN (?)' => $staleIds,
                ]);
                $result['deleted'] += $removed;
                if ($removed > 0) {
                    $touchedStoreIds[$storeId] = $storeId;
                }
            }
        }

        // Only the store views whose rows actually moved. Saving a product normally
        // bumps its updated_at for every store view, so this is usually all of them -
        // and rightly so, since the list is ordered by updated_at and every store's
        // ordering really did change. The narrowing earns its keep for a product that
        // is eligible in one website only: then the other store never gets evicted.
        if ($touchedStoreIds) {
            $this->cacheInvalidator->invalidate(array_values($touchedStoreIds));
        }

        return $result;
    }

    /**
     * Drop every row of the given products, in all store views.
     *
     * The foreign key already cascades when the product row itself disappears; this is
     * for catalog_product_delete_after, which fires while the row still exists, and for
     * the cron's safety net.
     *
     * @param int[] $productIds
     */
    public function deleteProducts(array $productIds): int
    {
        $productIds = $this->normalizeIds($productIds);
        if (!$productIds) {
            return 0;
        }

        $connection = $this->resource->getConnection();

        $deleted = (int)$connection->delete(
            $this->resource->getTableName(self::TABLE),
            ['product_id IN (?)' => $productIds]
        );

        if ($deleted > 0) {
            // A delete spans every store view, so invalidate without a store scope.
            $this->cacheInvalidator->invalidate();
        }

        return $deleted;
    }

    /**
     * Delete every row that has outlived RowLifetime::TTL_SECONDS.
     *
     * This is a hard delete, not an archive: once a row expires the only way it returns
     * is for its product to be saved again, or for a --full re-sync to rewrite it. With
     * a short TTL that means the table is empty most of the time, which is the intended
     * behaviour here.
     *
     * Deleting also evicts the cached pages, otherwise a visitor would keep being served
     * a homepage listing rows that no longer exist.
     *
     * @return int rows removed
     */
    public function purgeExpiredRows(): int
    {
        $connection = $this->resource->getConnection();

        $deleted = (int)$connection->delete(
            $this->resource->getTableName(self::TABLE),
            ['synced_at < ?' => $this->rowLifetime->getExpiryThreshold()]
        );

        if ($deleted > 0) {
            // Expiry spans every store view, so no store scope is passed.
            $this->cacheInvalidator->invalidate();
        }

        return $deleted;
    }

    /**
     * Remove rows whose product no longer exists in catalog_product_entity.
     *
     * ON DELETE CASCADE normally handles this, so in a healthy install it deletes nothing.
     * It is kept as a repair step for rows orphaned by raw SQL imports or a restore that
     * skipped the constraint.
     */
    public function removeOrphanRows(): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $productTable = $this->resource->getTableName('catalog_product_entity');

        $orphanIds = $connection->fetchCol(
            $connection->select()
                ->from(['s' => $table], 'product_id')
                ->joinLeft(['p' => $productTable], 's.product_id = p.entity_id', [])
                ->where('p.entity_id IS NULL')
                ->distinct()
        );

        return $orphanIds ? $this->deleteProducts(array_map('intval', $orphanIds)) : 0;
    }

    /**
     * Single definition of "eligible": enabled, visible in catalog/search, and assigned
     * to the website this store view belongs to.
     *
     * The collection is loaded per store view so name and short_description carry the
     * store-scoped value - that is what makes the Arabic storefront show Arabic names.
     *
     * @param int[] $productIds
     * @return array<int, array<string, mixed>>
     */
    private function loadEligibleRows(array $productIds, int $storeId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addIdFilter($productIds)
            ->addAttributeToSelect(self::ATTRIBUTES)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_IN_SEARCH,
                Visibility::VISIBILITY_BOTH,
            ]]);

        $now = gmdate('Y-m-d H:i:s');
        $rows = [];
        foreach ($collection as $product) {
            [$regularPrice, $finalPrice] = $this->resolvePrices($product);
            $rows[] = [
                'product_id'         => (int)$product->getId(),
                'store_id'           => $storeId,
                'sku'                => (string)$product->getSku(),
                'name'               => $product->getData('name'),
                'short_description'  => $product->getData('short_description'),
                'price'              => $regularPrice,
                'final_price'        => $finalPrice,
                'status'             => (int)$product->getData('status'),
                'visibility'         => (int)$product->getData('visibility'),
                'url_key'            => $product->getData('url_key'),
                'image'              => $this->resolveImage($product),
                'product_updated_at' => $product->getUpdatedAt(),
                'synced_at'          => $now,
            ];
        }

        return $rows;
    }

    /**
     * Regular (list) price and final (payable) price.
     *
     * Read through the pricing framework rather than off the price attribute, because
     * that is the only source that agrees with what the storefront actually prints:
     *
     *  - it resolves a configurable or bundle to its cheapest child, where the parent's
     *    own price attribute is empty or, as on this install, a stray 0;
     *  - it applies special_price only inside its from/to dates;
     *  - it applies catalogue price rules, which never touch the price attribute at all.
     *
     * The theme's own badge uses exactly these two prices (see
     * Codilar\Twoxl\ViewModel\Pdp::getDiscountPercent), so the figure this block shows
     * and the figure on the product page cannot disagree.
     *
     * It costs more than reading an attribute - a price model per product per store - so
     * if this ever runs against a catalogue of any size, read price/final_price out of
     * catalog_product_index_price with a LEFT JOIN instead.
     *
     * @return array{0: ?string, 1: ?string} [regular, final], either may be NULL
     */
    private function resolvePrices(\Magento\Catalog\Model\Product $product): array
    {
        $regular = 0.0;
        $final = 0.0;

        try {
            $priceInfo = $product->getPriceInfo();
            $regular = (float)$priceInfo->getPrice('regular_price')->getAmount()->getValue();
            $final = (float)$priceInfo->getPrice('final_price')->getAmount()->getValue();
        } catch (\Throwable $e) {
            // A product type with no price model (gift card without amounts, a broken
            // composite) must not take the whole batch down with it.
            $regular = (float)$product->getData('price');
        }

        // Last resort: the raw attribute. Keeps a row renderable even when the price
        // index has not caught up with a newly created product.
        if ($regular <= 0.0) {
            $regular = (float)$product->getData('price');
        }
        if ($final <= 0.0) {
            $final = $regular;
        }

        // Never advertise a "discount" that is really a rounding artefact or bad data.
        if ($final > $regular) {
            $final = $regular;
        }

        return [
            $regular > 0.0 ? (string)$regular : null,
            $final > 0.0 ? (string)$final : null,
        ];
    }

    /**
     * First usable image role. "no_selection" is Magento's way of saying "none set".
     */
    private function resolveImage(\Magento\Catalog\Model\Product $product): ?string
    {
        foreach (['image', 'small_image', 'thumbnail'] as $role) {
            $value = $product->getData($role);
            if ($value && $value !== 'no_selection') {
                return (string)$value;
            }
        }

        return null;
    }

    /**
     * Add the parents of any given product: configurable, grouped and bundle all record
     * their children in catalog_product_relation, so one query covers every composite
     * type without asking each type model in turn.
     *
     * @param int[] $productIds
     * @return int[]
     */
    private function withParents(array $productIds): array
    {
        $connection = $this->resource->getConnection();

        $parentIds = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_relation'), 'parent_id')
                ->where('child_id IN (?)', $productIds)
                ->distinct()
        );

        if (!$parentIds) {
            return $productIds;
        }

        return $this->normalizeIds(array_merge($productIds, array_map('intval', $parentIds)));
    }

    /**
     * @param int[] $productIds
     * @return int[]
     */
    private function normalizeIds(array $productIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $productIds))));
    }
}
