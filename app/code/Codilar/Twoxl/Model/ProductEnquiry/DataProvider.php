<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Model\ProductEnquiry;

use Codilar\Twoxl\Model\ResourceModel\ProductEnquiry\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Feeds the admin edit form with the record being edited.
 */
class DataProvider extends AbstractDataProvider
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $loadedData = null;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getData(): array
    {
        if ($this->loadedData === null) {
            $this->loadedData = [];
            foreach ($this->collection->getItems() as $enquiry) {
                $this->loadedData[$enquiry->getId()] = $enquiry->getData();
            }
        }

        return $this->loadedData;
    }
}
