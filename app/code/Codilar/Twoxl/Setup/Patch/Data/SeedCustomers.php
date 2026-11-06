<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Two sample customers, one per region, so the storefront can be exercised logged in.
 *
 * They reuse the names and addresses from SeedProductEnquiries, which keeps the demo data
 * coherent: the people who raised the enquiries are the people who hold accounts.
 *
 * The shared password below is a development convenience and has no business on a production
 * install - drop this patch, or change PASSWORD, before the catalogue goes live.
 *
 * Area code is emulated because saving a customer invalidates caches, and the tags are built
 * from the current area; without one the save fails with "Area code is not set".
 */
class SeedCustomers implements DataPatchInterface
{
    /** Development only. See the class comment. */
    private const PASSWORD = 'Codilar@2XL1';

    /** General. */
    private const GROUP_ID = 1;

    private const SAMPLES = [
        [
            'email' => 'rahul.menon@example.com',
            'firstname' => 'Rahul',
            'lastname' => 'Menon',
            'website' => 'in',
            'store' => 'in_en',
        ],
        [
            'email' => 'layla.m@example.com',
            'firstname' => 'Layla',
            'lastname' => 'Al Mansoori',
            'website' => 'base',
            'store' => 'ae_en',
        ],
    ];

    public function __construct(
        private readonly State $appState,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function apply(): self
    {
        $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, function (): void {
            foreach (self::SAMPLES as $sample) {
                $this->createIfMissing($sample);
            }
        });

        return $this;
    }

    /**
     * @param array<string, string> $sample
     */
    private function createIfMissing(array $sample): void
    {
        try {
            $websiteId = (int)$this->storeManager->getWebsite($sample['website'])->getId();
            $storeId = (int)$this->storeManager->getStore($sample['store'])->getId();
        } catch (NoSuchEntityException) {
            // region not present on this install
            return;
        }

        if ($this->exists($sample['email'], $websiteId)) {
            return;
        }

        /** @var CustomerInterface $customer */
        $customer = $this->customerFactory->create();
        $customer->setEmail($sample['email']);
        $customer->setFirstname($sample['firstname']);
        $customer->setLastname($sample['lastname']);
        $customer->setWebsiteId($websiteId);
        $customer->setStoreId($storeId);
        $customer->setGroupId(self::GROUP_ID);

        // Hashing here rather than going through AccountManagement keeps the welcome email
        // out of a setup run, which has no mail transport configured.
        $this->customerRepository->save($customer, $this->encryptor->getHash(self::PASSWORD, true));
    }

    private function exists(string $email, int $websiteId): bool
    {
        try {
            $this->customerRepository->get($email, $websiteId);

            return true;
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    public static function getDependencies(): array
    {
        return [AddIndiaWebsite::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
