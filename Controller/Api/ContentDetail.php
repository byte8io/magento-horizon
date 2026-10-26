<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Content\ProviderPool;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * GET /horizon/api/v1/content/{type}/{identifier}
 *
 * One page or post as plain text.
 */
class ContentDetail extends AbstractAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        private readonly ProviderPool $providerPool
    ) {
        parent::__construct($request, $jsonFactory, $config);
    }

    protected function handleRequest(Json $result): Json
    {
        $type = $this->getStringParam('type');
        $identifier = $this->getStringParam('identifier');
        if ($type === null || $identifier === null) {
            return $result->setHttpResponseCode(400)->setData([
                'error' => 'Content type and identifier are required',
                'code' => 'BAD_REQUEST',
            ]);
        }

        $item = $this->providerPool->get($type, $identifier);
        if ($item === null) {
            return $result->setHttpResponseCode(404)->setData([
                'error' => "Content not found: {$type}/{$identifier}",
                'code' => 'NOT_FOUND',
            ]);
        }

        return $result->setData($item);
    }
}
