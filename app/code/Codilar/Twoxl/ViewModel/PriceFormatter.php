<?php
declare(strict_types=1);

namespace Codilar\Twoxl\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Formats amounts the 2XL way: dirham glyph followed by the number, no trailing ".00".
 */
class PriceFormatter implements ArgumentInterface
{
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
}
