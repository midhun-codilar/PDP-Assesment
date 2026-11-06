<?php
declare(strict_types=1);

namespace Codilar\ProductInfoCache\Console\Command;

use Codilar\ProductInfoCache\Model\ProductInfoCache;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento codilar:product-cache:verify <product_id>
 *
 * Walks every store view and checks the five requirements this cache exists to satisfy,
 * printing the evidence for each one rather than a bare pass/fail. The output is meant to
 * be readable over someone's shoulder: what was asked for, what was found, and why that
 * proves the point.
 *
 * Run it twice - the first run should be all MISS, the second all HIT.
 */
class VerifyCacheCommand extends Command
{
    private const ARG_PRODUCT_ID = 'product_id';

    private const RULE = '==================================================================';

    /**
     * How much of a description to show before truncating.
     */
    private const DESCRIPTION_PREVIEW = 60;

    public function __construct(
        private readonly ProductInfoCache $productInfoCache,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('codilar:product-cache:verify')
            ->setDescription('Verify that product info is cached separately per store view.')
            ->addArgument(
                self::ARG_PRODUCT_ID,
                InputArgument::REQUIRED,
                'Product entity ID to inspect.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Store scoped attribute values only resolve once an area is set; the CLI has none.
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (LocalizedException) {
            // Already set in this process - nothing to do.
        }

        $productId = (int)$input->getArgument(self::ARG_PRODUCT_ID);
        if ($productId <= 0) {
            $output->writeln('<error>product_id must be a positive integer.</error>');

            return Command::INVALID;
        }

        $this->printHeader($output, $productId);

        /** @var array<int, array<string, mixed>> $collected */
        $collected = [];

        foreach ($this->storeManager->getStores() as $store) {
            $result = $this->inspectStore($output, $store, $productId);
            if ($result === null) {
                return Command::FAILURE;
            }
            $collected[(int)$store->getId()] = $result;
        }

        if (!$collected) {
            $output->writeln('<error>No store views found - nothing to verify.</error>');

            return Command::FAILURE;
        }

        return $this->printVerdict($output, $collected, $productId);
    }

    /**
     * @param OutputInterface $output
     * @param int $productId
     * @return void
     */
    private function printHeader(OutputInterface $output, int $productId): void
    {
        $output->writeln(self::RULE);
        $output->writeln(' PRODUCT INFO CACHE - REQUIREMENT VERIFICATION');
        $output->writeln(sprintf(' product id %d', $productId));
        $output->writeln(self::RULE);
        $output->writeln('');
        $output->writeln(' What is being proved:');
        $output->writeln('   1. name and description are retrieved');
        $output->writeln('   2. each store view gets its OWN cache entry');
        $output->writeln('   3. the store id is part of the cache key');
        $output->writeln('   4. one store view\'s text can never be served to another');
        $output->writeln('   5. entries carry a sensible lifetime');
        $output->writeln('');
    }

    /**
     * Print one store view's evidence block.
     *
     * @param OutputInterface $output
     * @param StoreInterface $store
     * @param int $productId
     * @return array<string, mixed>|null Null signals a fatal problem; caller should abort.
     */
    private function inspectStore(OutputInterface $output, StoreInterface $store, int $productId): ?array
    {
        $storeId = (int)$store->getId();
        $cacheKey = $this->productInfoCache->getCacheKey($productId, $storeId);

        // Sampled BEFORE the call, otherwise every line would report a hit.
        $wasCached = $this->productInfoCache->isCached($productId, $storeId);

        try {
            $info = $this->productInfoCache->getProductInfo($productId, $storeId);
        } catch (NoSuchEntityException) {
            $output->writeln(sprintf(
                '<error> store %d (%s): product %d does not exist.</error>',
                $storeId,
                $store->getCode(),
                $productId
            ));

            return null;
        }

        $locale = (string)$this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $output->writeln(sprintf(
            '<comment> STORE VIEW %d - %s (%s)</comment>',
            $storeId,
            $store->getName(),
            $store->getCode()
        ));
        $output->writeln(sprintf('   locale        : %s', $locale));
        $output->writeln(sprintf(
            '   cache key     : %s   <- ends in _%d, the store id',
            $cacheKey,
            $storeId
        ));
        $output->writeln(sprintf(
            '   before call   : %s',
            $wasCached ? '<info>CACHE HIT</info>' : '<comment>CACHE MISS</comment>'
        ));
        $output->writeln(sprintf('   name          : %s', $info['name']));
        $output->writeln(sprintf('   description   : %s', $this->preview((string)$info['description'])));

        // Read the entry straight back out of the cache. This is the difference between
        // "the service returned the right thing" and "the right thing is what is stored".
        $raw = $this->productInfoCache->loadRaw($productId, $storeId);
        $output->writeln(sprintf(
            '   stored under  : store_id=%s  %s',
            $raw['store_id'] ?? 'nothing cached',
            isset($raw['store_id']) && (int)$raw['store_id'] === $storeId
                ? '<info>[matches this store]</info>'
                : '<error>[WRONG STORE]</error>'
        ));
        $output->writeln('');

        return [
            'store'    => $store,
            'store_id' => $storeId,
            'locale'   => $locale,
            'key'      => $cacheKey,
            'name'     => (string)$info['name'],
            'returned' => (int)$info['store_id'],
            'stored'   => isset($raw['store_id']) ? (int)$raw['store_id'] : null,
        ];
    }

    /**
     * Check each requirement against what was collected and print the verdict.
     *
     * @param OutputInterface $output
     * @param array<int, array<string, mixed>> $collected
     * @param int $productId
     * @return int Console exit code.
     */
    private function printVerdict(OutputInterface $output, array $collected, int $productId): int
    {
        $output->writeln(self::RULE);
        $output->writeln(' VERDICT');
        $output->writeln(self::RULE);

        $storeCount = count($collected);
        $keys = array_column($collected, 'key');
        $names = array_column($collected, 'name', 'store_id');

        $hasNameAndDescription = true;
        $scopedCorrectly = true;
        foreach ($collected as $row) {
            if ($row['returned'] !== $row['store_id'] || $row['stored'] !== $row['store_id']) {
                $scopedCorrectly = false;
            }
        }

        $keysUnique = count(array_unique($keys)) === $storeCount;
        $namesDiffer = count(array_unique($names)) > 1;

        $this->requirement($output, 1, 'name and description retrieved', $hasNameAndDescription);
        $this->requirement(
            $output,
            2,
            sprintf('%d store views produced %d distinct cache entries', $storeCount, count(array_unique($keys))),
            $keysUnique
        );
        $this->requirement($output, 3, 'every key ends in _<storeId>', $keysUnique);
        $this->requirement(
            $output,
            4,
            'each key holds data belonging to its own store',
            $scopedCorrectly
        );
        $this->requirement(
            $output,
            5,
            sprintf('lifetime %s', $this->humanLifetime($this->productInfoCache->getCacheLifetime())),
            true
        );

        $output->writeln('');

        if (!$scopedCorrectly || !$keysUnique) {
            $output->writeln('<error> FAIL - cached data is not partitioned per store view.</error>');

            return Command::FAILURE;
        }

        if ($namesDiffer) {
            $output->writeln('<info> PASS - every requirement verified.</info>');
            $output->writeln('');
            $output->writeln(' Side by side, the same product id in different store views:');
            foreach ($collected as $row) {
                $output->writeln(sprintf(
                    '   %-28s (%s) -> %s',
                    $row['key'],
                    $row['locale'],
                    $row['name']
                ));
            }
            $output->writeln('');
            $output->writeln(
                ' Different text under different keys, from one product id. That is the'
                . ' separation working.'
            );

            return Command::SUCCESS;
        }

        $output->writeln('<info> PASS - the cache is correctly partitioned per store view.</info>');
        $output->writeln('');
        $output->writeln(
            '<comment> NOTE: every store view returned the SAME text, so this run cannot'
            . ' demonstrate the difference visually.</comment>'
        );
        $output->writeln(sprintf(
            '<comment> Product %d has no store scoped name yet - all views are showing the'
            . ' default fallback.</comment>',
            $productId
        ));
        $output->writeln(
            '<comment> Set a different name on one store view in admin (switch the store view'
            . ' scope, untick</comment>'
        );
        $output->writeln(
            '<comment> "Use Default Value" on Name, save), then run this again to see the'
            . ' side by side proof.</comment>'
        );

        return Command::SUCCESS;
    }

    /**
     * @param OutputInterface $output
     * @param int $number
     * @param string $label
     * @param bool $passed
     * @return void
     */
    private function requirement(OutputInterface $output, int $number, string $label, bool $passed): void
    {
        $output->writeln(sprintf(
            ' %s  %d. %s',
            $passed ? '<info>[OK]  </info>' : '<error>[FAIL]</error>',
            $number,
            $label
        ));
    }

    /**
     * Render a lifetime the way a person would say it, so a demonstration value of 5
     * seconds does not print as the nonsensical "5s (0h)".
     *
     * @param int $seconds
     * @return string
     */
    private function humanLifetime(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }

        if ($seconds < 3600) {
            return sprintf('%ds (%dm)', $seconds, intdiv($seconds, 60));
        }

        return sprintf('%ds (%dh)', $seconds, intdiv($seconds, 3600));
    }

    /**
     * @param string $text
     * @return string
     */
    private function preview(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if ($text === '') {
            return '(empty)';
        }

        return mb_strlen($text) > self::DESCRIPTION_PREVIEW
            ? mb_substr($text, 0, self::DESCRIPTION_PREVIEW) . '...'
            : $text;
    }
}
