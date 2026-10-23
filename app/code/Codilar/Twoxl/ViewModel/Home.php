<?php
declare(strict_types=1);

namespace Codilar\Twoxl\ViewModel;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Content of the 2XL home page sections.
 *
 * The copy, links and prices are sample data taken from the reference page; replace the
 * `image` entries (files in the theme's web/images/home) and the product rows with real
 * sources (CMS blocks, a product collection) when the content is managed from the admin.
 */
class Home implements ArgumentInterface
{
    /** Number of bundled sample photos (web/images/home/sample-N.png). */
    private const SAMPLE_IMAGES = 5;

    public function __construct(private readonly UrlInterface $urlBuilder)
    {
    }

    public function getUrl(string $path): string
    {
        return $this->urlBuilder->getUrl($path);
    }

    /**
     * @return array<string, array{label: string, image: int, position: string}>
     */
    public function getDiscoverTiles(): array
    {
        return [
            'living' => ['label' => 'Living', 'url' => 'furniture/living', 'image' => 3, 'position' => '50% 50%'],
            'dining' => ['label' => 'Dining', 'url' => 'furniture/dining', 'image' => 5, 'position' => '50% 60%'],
            'bedroom' => ['label' => 'Bedroom', 'url' => 'furniture/bedroom', 'image' => 4, 'position' => '50% 50%'],
            'decor' => ['label' => 'Decor', 'url' => 'accessories/home-decor', 'image' => 2, 'position' => '40% 50%'],
        ];
    }

    /**
     * Rows of the "Designs born from artistry" mosaic; `span` is the grid weight of a tile.
     *
     * @return array<int, array<int, array{title: string, text: string, url: string, image: int, span: int}>>
     */
    public function getMosaicRows(): array
    {
        return [
            [
                ['title' => 'Tabletop', 'text' => 'Every gathering, beautifully dressed', 'url' => 'accessories/tabletop', 'image' => 5, 'span' => 2],
                ['title' => 'Home Decor', 'text' => 'Breathe life into every corner.', 'url' => 'accessories/home-decor', 'image' => 4, 'span' => 1],
            ],
            [
                ['title' => 'Wall Decor', 'text' => 'Wall art that sets the tone for every room.', 'url' => 'accessories/wall-decor', 'image' => 2, 'span' => 1],
                ['title' => 'Lighting', 'text' => 'Illuminate your space beautifully.', 'url' => 'accessories/lighting', 'image' => 3, 'span' => 1],
                ['title' => 'Garden', 'text' => 'Curated for the open-air moments.', 'url' => 'outdoor', 'image' => 1, 'span' => 1],
            ],
            [
                ['title' => 'Fragrances', 'text' => 'Invisible, Unforgettable.', 'url' => 'accessories/fragrances', 'image' => 3, 'span' => 1],
                ['title' => 'Soft Furnishing', 'text' => 'Where every space feels fresh.', 'url' => 'accessories/soft-furnishing', 'image' => 4, 'span' => 2],
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, regular: float, price: float, discount: int, image: int, url: string}>
     */
    public function getProducts(string $section): array
    {
        $rows = match ($section) {
            'outdoor' => [
                ['Makena 5 Seater Sofa', 6995, 5540], ['Oakley 8 Seater Dining Table', 4995, 3950],
                ['Barron 3 Seater Sofa', 6295, 4990], ['Denzel Fire Pit', 4295, 3430],
                ['Wilder Coffee Table', 3495, 2770], ['Wilder 3 Seater Sofa', 5695, 4540],
            ],
            'new-arrivals' => [
                ['Maja 8 Seater Dining Table', 3395, 2690], ['Zeloh King Bed -180x200cm', 7995, 6350],
                ['Mardid 3 Seater Sofa', 9595, 7650], ['Mauricio Single Seater Sofa', 5995, 4750],
                ['Kantana 2 Seater Sofa', 3195, 2550], ['Kantana Single Seater Sofa', 1595, 1270],
            ],
            default => [
                ['Flavio King Bed Ivory - 180x200 cm', 7395, 5890], ['Flavio Nightstand with 2 Drawers', 2895, 2290],
                ['Tera Nightstand', 1375, 1090], ['Flavio Vanity Brown - 180x45x79 cm', 6995, 5550],
                ['Frost King Bed - 180x200 cm', 3995, 3180], ['Kameron 8 Seater Dining Table', 9999, 7949],
            ],
        };

        $products = [];
        foreach ($rows as $i => [$name, $regular, $price]) {
            $products[] = [
                'name' => $name,
                'regular' => (float)$regular,
                'price' => (float)$price,
                'discount' => (int)round((1 - $price / $regular) * 100),
                'image' => ($i % self::SAMPLE_IMAGES) + 1,
                'url' => 'tresa-8-seater-dining-table-130917',
            ];
        }

        return $products;
    }
}
