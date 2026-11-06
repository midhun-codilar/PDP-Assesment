<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;

/**
 * Puts the real ivory sofa photos on the parent and on the Ivory variant.
 *
 * Both were seeded with the dining table placeholders that stood in before sofa photography
 * existed, so picking Ivory on the PDP swapped the sofa out for a table. Only products still
 * carrying a placeholder are touched; a gallery that already holds an ivory file is left alone.
 *
 * Runs outside PatchApplier's transaction on purpose. Product::getIdentities() reads the area
 * code, and the commit callbacks that reach it are deferred to the outermost commit - which
 * PatchApplier performs after apply() has returned and emulateAreaCode() has already put the
 * area back. Without the wrapping transaction each save commits inside the emulation instead.
 */
class FixSofaVariantImages implements DataPatchInterface, NonTransactionableInterface
{
    private const IMPORT_DIR = 'import/codilar_twoxl';

    private const GLOB = '127933-IVORY_*.jpg';

    /** Parent first, so the default gallery and the Ivory variant stay in step. */
    private const SKUS = ['127933', '127933-IVORY'];

    public function __construct(
        private readonly State $appState,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Filesystem $filesystem
    ) {
    }

    public function apply(): self
    {
        $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, function (): void {
            foreach (self::SKUS as $sku) {
                try {
                    $this->replaceGallery($sku);
                } catch (NoSuchEntityException) {
                    // nothing to fix on an install without the sofa
                }
            }
        });

        return $this;
    }

    private function replaceGallery(string $sku): void
    {
        $product = $this->productRepository->get($sku, true, 0, true);

        if ($this->alreadyFixed($product)) {
            return;
        }

        $product->setMediaGalleryEntries([]);
        $this->productRepository->save($product);

        $product = $this->productRepository->get($sku, true, 0, true);
        $this->attachImages($product);
        $this->productRepository->save($product);
    }

    /**
     * True once the gallery holds the ivory photos rather than a placeholder.
     */
    private function alreadyFixed(ProductInterface $product): bool
    {
        foreach ((array)$product->getMediaGalleryEntries() as $entry) {
            if (str_contains((string)$entry->getFile(), 'wideshot')) {
                return true;
            }
        }

        return false;
    }

    private function attachImages(ProductInterface $product): void
    {
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $media->create(self::IMPORT_DIR);

        $files = glob(__DIR__ . '/../../Fixtures/images/' . self::GLOB) ?: [];
        sort($files, SORT_NATURAL);

        foreach ($files as $index => $file) {
            $relative = self::IMPORT_DIR . '/' . basename($file);
            $media->getDriver()->filePutContents(
                $media->getAbsolutePath($relative),
                (string)file_get_contents($file)
            );
            $product->addImageToMediaGallery(
                $media->getAbsolutePath($relative),
                $index === 0 ? ['image', 'small_image', 'thumbnail'] : null,
                false,
                false
            );
        }
    }

    public static function getDependencies(): array
    {
        return [ConvertNiecelToConfigurable::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
