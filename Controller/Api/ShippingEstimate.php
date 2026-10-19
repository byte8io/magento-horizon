<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Service\ShippingEstimateService;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * GET /horizon/api/v1/shipping/estimate?country=GB&postcode=W1S&sku=24-MB02&qty=1[&region=&city=]
 */
class ShippingEstimate extends AbstractAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        private readonly ShippingEstimateService $shippingEstimateService
    ) {
        parent::__construct($request, $jsonFactory, $config);
    }

    protected function handleRequest(Json $result): Json
    {
        $country = $this->getStringParam('country');
        if ($country === null || !preg_match('/^[A-Za-z]{2}$/', $country)) {
            return $result->setHttpResponseCode(400)->setData([
                'error' => 'Query parameter "country" (ISO 3166-1 alpha-2) is required',
                'code' => 'BAD_REQUEST',
            ]);
        }

        $qty = (float) ($this->request->getParam('qty') ?? 1);

        $data = $this->shippingEstimateService->estimate(
            $this->getStringParam('sku'),
            $qty > 0 ? min($qty, 1000.0) : 1.0,
            $country,
            $this->getStringParam('postcode'),
            $this->getStringParam('region'),
            $this->getStringParam('city')
        );

        return $result->setData($data);
    }
}
