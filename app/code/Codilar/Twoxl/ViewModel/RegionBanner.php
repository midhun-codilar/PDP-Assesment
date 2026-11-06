<?php
declare(strict_types=1);

namespace Codilar\Twoxl\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The regional strip under the header, and the accent colour that goes with it.
 *
 * Everything comes from twoxl/region/* read at store scope, so the UAE and India storefronts
 * differ by configuration rather than by template. Defaults live in etc/config.xml; the India
 * view overrides them in core_config_data.
 */
class RegionBanner implements ArgumentInterface
{
    private const PATH_ENABLED = 'twoxl/region/enabled';
    private const PATH_HEADLINE = 'twoxl/region/headline';
    private const PATH_SUBLINE = 'twoxl/region/subline';
    private const PATH_ACCENT = 'twoxl/region/accent';

    /** Used when the configured colour is missing or not a plain hex value. */
    private const FALLBACK_ACCENT = '#a8731a';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getHeadline(): string
    {
        return $this->value(self::PATH_HEADLINE);
    }

    public function getSubline(): string
    {
        return $this->value(self::PATH_SUBLINE);
    }

    /**
     * The currency this store sells in, shown as the badge on the right of the strip.
     */
    public function getCurrencyCode(): string
    {
        return (string)$this->storeManager->getStore()->getCurrentCurrencyCode();
    }

    /**
     * Accent colour, guaranteed safe to print inside a stylesheet.
     *
     * The value is admin editable and lands in a <style> block, so anything that is not exactly
     * a six digit hex is thrown away rather than escaped - there is no legitimate colour this
     * rejects, and it keeps arbitrary text out of the CSS.
     */
    public function getAccent(): string
    {
        $accent = $this->value(self::PATH_ACCENT);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : self::FALLBACK_ACCENT;
    }

    private function value(string $path): string
    {
        return trim((string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE));
    }
}
