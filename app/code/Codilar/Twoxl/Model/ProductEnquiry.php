<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Model;

use Codilar\Twoxl\Model\ResourceModel\ProductEnquiry as ProductEnquiryResource;
use Magento\Framework\Model\AbstractModel;

/**
 * An enquiry raised from a product page.
 *
 * @method string getName()
 * @method string getEmail()
 * @method string|null getPhone()
 * @method string|null getSku()
 * @method string|null getMessage()
 * @method int getStatus()
 * @method int|null getStoreId()
 * @method string getCreatedAt()
 */
class ProductEnquiry extends AbstractModel
{
    public const STATUS_NEW = 1;
    public const STATUS_IN_PROGRESS = 2;
    public const STATUS_CLOSED = 3;

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_NEW => 'New',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_CLOSED => 'Closed',
    ];

    protected function _construct(): void
    {
        $this->_init(ProductEnquiryResource::class);
    }

    public function getStatusLabel(): string
    {
        return self::STATUSES[(int)$this->getStatus()] ?? '';
    }
}
