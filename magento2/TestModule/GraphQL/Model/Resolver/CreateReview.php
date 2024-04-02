<?php

declare(strict_types=1);

namespace TestModule\GraphQL\Model\Resolver;

use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Review\Model\Rating;
use Magento\Review\Model\RatingFactory;
use Magento\Review\Model\ResourceModel\Rating\Option\Vote\Collection as OptionVoteCollection;
use Magento\Review\Model\ResourceModel\Rating\Option\Vote\CollectionFactory as OptionVoteCollectionFactory;
use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;

class CreateReview implements ResolverInterface
{
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var RatingFactory
     */
    private $ratingFactory;

    /**
     * @var ReviewFactory
     */
    private $reviewFactory;

    /**
     * @var OptionVoteCollectionFactory
     */
    private $ratingOptionCollectionFactory;

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param ReviewFactory $reviewFactory
     * @param RatingFactory $ratingFactory
     * @param OptionVoteCollectionFactory $ratingOptionCollectionFactory
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ReviewFactory $reviewFactory,
        RatingFactory $ratingFactory,
        OptionVoteCollectionFactory $ratingOptionCollectionFactory
    ) {
        $this->productRepository             = $productRepository;
        $this->reviewFactory                 = $reviewFactory;
        $this->ratingFactory                 = $ratingFactory;
        $this->ratingOptionCollectionFactory = $ratingOptionCollectionFactory;
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
        $input = $args['input'];
        $this->validateInput($input, $context);

        $customerId = (int) $context->getUserId();
        $sku = $input['sku'];
        $ratings = $input['ratings'];
        $data = [
            'nickname' => $input['nickname'],
            'title' => $input['title'],
            'detail' => $input['details'],
        ];
        if (!empty($input['email'])) {
            $data['email'] = $input['email'];
        }
        $store = $context->getExtensionAttributes()->getStore();
        $review = $this->addReview($data, $ratings, $sku, $customerId, (int) $store->getId());

        return [
            'success' => true,
            'item' => $review
        ];
    }

    /**
     * @throws GraphQlInputException
     * @throws GraphQlAuthorizationException
     */
    private function validateInput(array $input, $context)
    {
        if (false === $context->getExtensionAttributes()->getIsCustomer()) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        if (empty($input['sku'])) {
            throw new GraphQlInputException(__('sku must not be empty'));
        }
        if (empty($input['title'])) {
            throw new GraphQlInputException(__('title must not be empty'));
        }
    }

    private function addReview(
        array $data,
        array $ratings,
        string $sku,
        ?int $customerId,
        int $storeId,
        string $email = null,
        bool $isFeatured = false
    ): Review {
        $review = $this->reviewFactory->create()->setData($data);
        $review->unsetData('review_id');
        $productId = $this->getProductIdBySku($sku);
        $review->setEntityId($review->getEntityIdByCode(Review::ENTITY_PRODUCT_CODE))
            ->setEntityPkValue($productId)
            ->setStatusId(Review::STATUS_PENDING)
            ->setCustomerId($customerId)
            ->setStoreId($storeId)
            ->setStores([$storeId]);

        if (!empty($email)) {
            $review->setEmail($email);
        }
        $review->setData('is_featured', $isFeatured);

        $review->save();
        $this->addReviewRatingVotes($ratings, (int) $review->getId(), $customerId, $productId);
        $review->aggregate();
        $votesCollection = $this->getReviewRatingVotes((int) $review->getId(), $storeId);
        $review->setData('rating_votes', $votesCollection);
        $review->setData('sku', $sku);

        return $review;
    }

    private function getProductIdBySku(string $sku): ?int
    {
        try {
            $product = $this->productRepository->get($sku, false, null, true);

            return (int) $product->getId();
        } catch (NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(__('Could not find a product with SKU "%sku"', ['sku' => $sku]));
        }
    }

    private function addReviewRatingVotes(array $ratings, int $reviewId, ?int $customerId, int $productId): void
    {
        foreach ($ratings as $option) {
            $ratingId = $option['id'];
            $optionId = $option['value_id'];
            /** @var Rating $ratingModel */
            $ratingModel = $this->ratingFactory->create();
            $ratingModel->setRatingId(base64_decode($ratingId))
                ->setReviewId($reviewId)
                ->setCustomerId($customerId)
                ->addOptionVote($optionId, $productId);
        }
    }

    private function getReviewRatingVotes(int $reviewId, int $storeId): OptionVoteCollection
    {
        /** @var OptionVoteCollection $votesCollection */
        $votesCollection = $this->ratingOptionCollectionFactory->create();
        $votesCollection->setReviewFilter($reviewId)->setStoreFilter($storeId)->addRatingInfo($storeId);

        return $votesCollection;
    }
}
