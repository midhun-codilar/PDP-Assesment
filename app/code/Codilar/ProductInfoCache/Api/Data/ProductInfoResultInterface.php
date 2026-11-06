<?php
declare(strict_types=1);

namespace Codilar\ProductInfoCache\Api\Data;

/**
 * One product's cached info for one store view, plus how it was obtained.
 *
 * The diagnostic fields (source, time_ms, cache_key) are the point of this interface:
 * they let a caller see whether the cache was used without having to trust that it was.
 */
interface ProductInfoResultInterface
{
    public const SOURCE_CACHE = 'CACHE';

    public const SOURCE_BACKEND = 'BACKEND';

    /**
     * @return int
     */
    public function getProductId(): int;

    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @return string
     */
    public function getStoreCode(): string;

    /**
     * @return string
     */
    public function getLocale(): string;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @return string
     */
    public function getDescription(): string;

    /**
     * Where this response came from: CACHE or BACKEND.
     *
     * @return string
     */
    public function getSource(): string;

    /**
     * @return string
     */
    public function getCacheKey(): string;

    /**
     * Configured lifetime of the entry, in seconds.
     *
     * @return int
     */
    public function getCacheLifetime(): int;

    /**
     * Wall clock time the lookup took, in milliseconds.
     *
     * @return float
     */
    public function getTimeMs(): float;

    /**
     * How long the entry had already been cached when this call arrived, in seconds.
     *
     * @return int
     */
    public function getAgeSeconds(): int;

    /**
     * Seconds left before the entry expires and the next call hits the backend.
     *
     * @return int
     */
    public function getExpiresInSeconds(): int;
}
