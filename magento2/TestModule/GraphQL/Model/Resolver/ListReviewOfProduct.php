<?php

declare(strict_types=1);

namespace TestModule\GraphQL\Model\Resolver;

use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Review\Model\ResourceModel\Review\Collection as ReviewCollection;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory as ReviewCollectionFactory;

class ListReviewOfProduct implements ResolverInterface
{
    /**
     * @var ReviewCollectionFactory
     */
    private $reviewCollectionFactory;

    /**
     * @param ReviewCollectionFactory $reviewCollectionFactory
     */
    public function __construct(ReviewCollectionFactory $reviewCollectionFactory)
    {
        $this->reviewCollectionFactory = $reviewCollectionFactory;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        $this->validateInput($args);
        $data = [];

        /** @var ReviewCollection */
        $reviewCollection = $this->reviewCollectionFactory->create();
        $reviewCollection->addFieldToFilter('main_table.entity_pk_value', $args['productId'])
            ->setPageSize($args['pageSize'])
            ->setCurPage($args['currentPage']);

        $reviewCollection->getSelect()->joinLeft(
            ['rating' => $reviewCollection->getTable('rating_option_vote')],
            'main_table.review_id = rating.review_id',
            ['rating' => 'value']
        );

        $data = [
            'items' => $reviewCollection->getData(),
            'page_info' => [
                'page_size' => $reviewCollection->getPageSize(),
                'current_page' => $reviewCollection->getCurPage(),
                'total_pages' => $reviewCollection->getLastPageNumber()
            ]
        ];

        return $data;
    }

    /**
     * @throws GraphQlInputException
     */
    private function validateInput(array $input)
    {
        if ($input['currentPage'] < 1) {
            throw new GraphQlInputException(__('currentPage value must be greater than 1'));
        }

        if ($input['pageSize'] < 1) {
            throw new GraphQlInputException(__('pageSize value must be greater than 1'));
        }

        if (empty($input['productId'])) {
            throw new GraphQlInputException(__('customerId value must not be empty'));
        }
    }
}
