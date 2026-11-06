<?php
declare(strict_types=1);

namespace Codilar\Twoxl\ViewModel;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Formats amounts the 2XL way: dirham glyph followed by the number, no trailing ".00".
 *
 * Only the UAE storefront has the glyph; every other region falls back to its own currency
 * format, the same split Magento_Catalog::product/price/amount/default.phtml makes.
 */
class PriceFormatter implements ArgumentInterface
{
    private const AED = 'AED';

    private const GLYPH = '<i class="aed-symbol" role="img" aria-label="AED"></i>';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * Plain number, decimals only when the amount is not a whole number.
     */
    public function formatNumber(float $amount): string
    {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;

        return number_format($amount, $decimals, '.', ',');
    }

    /**
     * Number with two decimals, used for instalment amounts.
     */
    public function formatDecimal(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }

    public function getCurrencyCode(): string
    {
        return (string)$this->storeManager->getStore()->getCurrentCurrencyCode();
    }

    public function isAed(): bool
    {
        return $this->getCurrencyCode() === self::AED;
    }

    /**
     * Ready to print amount including its currency. Returns markup, so templates treat it as
     * @noEscape; both branches escape the part that comes from data.
     */
    public function money(float $amount, bool $forceDecimals = false): string
    {
        if ($this->isAed()) {
            $number = $forceDecimals ? $this->formatDecimal($amount) : $this->formatNumber($amount);

            return self::GLYPH . htmlspecialchars($number, ENT_QUOTES, 'UTF-8');
        }

        return htmlspecialchars(
            (string)$this->priceCurrency->format($amount, false),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
