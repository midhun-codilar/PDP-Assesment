<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Setup\Patch\Data;

use Codilar\Twoxl\Model\ProductEnquiry;
use Codilar\Twoxl\Model\ProductEnquiryFactory;
use Codilar\Twoxl\Model\ResourceModel\ProductEnquiry as ProductEnquiryResource;
use Codilar\Twoxl\Model\ResourceModel\ProductEnquiry\CollectionFactory;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A couple of sample enquiries so the new table is not empty while the UI is built.
 */
class SeedProductEnquiries implements DataPatchInterface
{
    private const SAMPLES = [
        [
            'name' => 'Rahul Menon',
            'email' => 'rahul.menon@example.com',
            'phone' => '+91 98200 11223',
            'sku' => '127933',
            'message' => 'Do you deliver the Niecel sectional to Bengaluru, and is the red boucle in stock?',
            'status' => ProductEnquiry::STATUS_NEW,
            'store' => 'in_en',
        ],
        [
            'name' => 'Layla Al Mansoori',
            'email' => 'layla.m@example.com',
            'phone' => '+971 50 123 4567',
            'sku' => '130917',
            'message' => 'Looking for eight of the Tresa dining tables for an office fit-out. Trade pricing?',
            'status' => ProductEnquiry::STATUS_IN_PROGRESS,
            'store' => 'ae_en',
        ],
    ];

    public function __construct(
        private readonly ProductEnquiryFactory $enquiryFactory,
        private readonly ProductEnquiryResource $enquiryResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function apply(): self
    {
        foreach (self::SAMPLES as $sample) {
            if ($this->exists($sample['email'], (string)$sample['sku'])) {
                continue;
            }

            /** @var ProductEnquiry $enquiry */
            $enquiry = $this->enquiryFactory->create();
            $enquiry->addData([
                'name' => $sample['name'],
                'email' => $sample['email'],
                'phone' => $sample['phone'],
                'sku' => $sample['sku'],
                'message' => $sample['message'],
                'status' => $sample['status'],
                'store_id' => $this->storeId((string)$sample['store']),
            ]);
            $this->enquiryResource->save($enquiry);
        }

        return $this;
    }

    private function exists(string $email, string $sku): bool
    {
        return (bool)$this->collectionFactory->create()
            ->addFieldToFilter('email', $email)
            ->addFieldToFilter('sku', $sku)
            ->getSize();
    }

    private function storeId(string $code): ?int
    {
        try {
            return (int)$this->storeManager->getStore($code)->getId();
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return null;
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
