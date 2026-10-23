<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Store level settings the 2XL storefront relies on: AED currency, suffix-less URLs.
 */
class ConfigureStore implements DataPatchInterface
{
    private const SETTINGS = [
        'currency/options/base' => 'AED',
        'currency/options/default' => 'AED',
        'currency/options/allow' => 'AED',
        'catalog/seo/product_url_suffix' => '',
        'catalog/seo/category_url_suffix' => '',
        'design/head/default_title' => '2XL Home | Furniture & Home Decor',
        'design/head/default_description' => 'Shop furniture and home decor online.',
        'design/footer/copyright' => 'Copyrights © 2026 2XL Home, All rights reserved.',
    ];

    public function __construct(private readonly WriterInterface $configWriter)
    {
    }

    public function apply(): self
    {
        foreach (self::SETTINGS as $path => $value) {
            $this->configWriter->save($path, $value, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
