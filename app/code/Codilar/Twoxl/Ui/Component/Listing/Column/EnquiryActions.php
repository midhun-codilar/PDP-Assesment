<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Edit and Delete links on each row of the enquiry grid.
 */
class EnquiryActions extends Column
{
    private const EDIT_PATH = 'twoxl_admin/enquiry/edit';
    private const DELETE_PATH = 'twoxl_admin/enquiry/delete';

    /**
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['entity_id'])) {
                continue;
            }

            $id = (int)$item['entity_id'];
            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(self::EDIT_PATH, ['entity_id' => $id]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(self::DELETE_PATH, ['entity_id' => $id]),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete enquiry #%1', $id),
                        'message' => __('Are you sure you want to delete this enquiry?'),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
