<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Service\ReviewService;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * GET /horizon/api/v1/reviews?sku=…&limit=5&page=1
 *
 * Approved reviews for one product plus the aggregate rating. Shape matches
 * the gateway's `ReviewsResult`.
 */
class Reviews extends AbstractAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        private readonly ReviewService $reviewService
    ) {
        parent::__construct($request, $jsonFactory, $config);
    }

    protected function handleRequest(Json $result): Json
    {
        $sku = $this->getStringParam('sku');
        if ($sku === null) {
            return $result->setHttpResponseCode(400)->setData([
                'error' => 'Product SKU is required',
                'code' => 'MISSING_SKU',
            ]);
        }

        $limit = min($this->getIntParam('limit', 5), ReviewService::MAX_LIMIT);
        $page = max($this->getIntParam('page', 1), 1);

        $data = $this->reviewService->getReviews($sku, $limit, $page);
        if ($data === null) {
            return $result->setHttpResponseCode(404)->setData([
                'error' => "Product not found: {$sku}",
                'code' => 'NOT_FOUND',
            ]);
        }

        return $result->setData($data);
    }
}
