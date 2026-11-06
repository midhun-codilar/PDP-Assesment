<?php
declare(strict_types=1);

namespace Codilar\ProductInfoCache\Model\Data;

use Codilar\ProductInfoCache\Api\Data\ProductInfoResultInterface;
use Magento\Framework\DataObject;

/**
 * Immutable-in-practice carrier for one API response.
 *
 * Extends DataObject so the generated factory can build it from an array in one call,
 * while the typed getters below are what Magento's Web API output processor reflects on.
 */
class ProductInfoResult extends DataObject implements ProductInfoResultInterface
{
    public const PRODUCT_ID = 'product_id';
    public const STORE_ID = 'store_id';
    public const STORE_CODE = 'store_code';
    public const LOCALE = 'locale';
    public const NAME = 'name';
    public const DESCRIPTION = 'description';
    public const SOURCE = 'source';
    public const CACHE_KEY = 'cache_key';
    public const CACHE_LIFETIME = 'cache_lifetime';
    public const TIME_MS = 'time_ms';
    public const AGE_SECONDS = 'age_seconds';
    public const EXPIRES_IN_SECONDS = 'expires_in_seconds';

    /**
     * @inheritDoc
     */
    public function getProductId(): int
    {
        return (int)$this->getData(self::PRODUCT_ID);
    }

    /**
     * @inheritDoc
     */
    public function getStoreId(): int
    {
        return (int)$this->getData(self::STORE_ID);
    }

    /**
     * @inheritDoc
     */
    public function getStoreCode(): string
    {
        return (string)$this->getData(self::STORE_CODE);
    }

    /**
     * @inheritDoc
     */
    public function getLocale(): string
    {
        return (string)$this->getData(self::LOCALE);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return (string)$this->getData(self::NAME);
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return (string)$this->getData(self::DESCRIPTION);
    }

    /**
     * @inheritDoc
     */
    public function getSource(): string
    {
        return (string)$this->getData(self::SOURCE);
    }

    /**
     * @inheritDoc
     */
    public function getCacheKey(): string
    {
        return (string)$this->getData(self::CACHE_KEY);
    }

    /**
     * @inheritDoc
     */
    public function getCacheLifetime(): int
    {
        return (int)$this->getData(self::CACHE_LIFETIME);
    }

    /**
     * @inheritDoc
     */
    public function getTimeMs(): float
    {
        return (float)$this->getData(self::TIME_MS);
    }

    /**
     * @inheritDoc
     */
    public function getAgeSeconds(): int
    {
        return (int)$this->getData(self::AGE_SECONDS);
    }

    /**
     * @inheritDoc
     */
    public function getExpiresInSeconds(): int
    {
        return (int)$this->getData(self::EXPIRES_IN_SECONDS);
    }
}
