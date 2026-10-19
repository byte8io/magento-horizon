<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Service\StoreInfoService;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * GET /horizon/api/v1/store-info — contact details, shipping destinations,
 * active carriers / payment methods and the merchant's policy pages as text.
 */
class StoreInfo extends AbstractAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        private readonly StoreInfoService $storeInfoService
    ) {
        parent::__construct($request, $jsonFactory, $config);
    }

    protected function handleRequest(Json $result): Json
    {
        return $result->setData($this->storeInfoService->getStoreInfo());
    }
}
