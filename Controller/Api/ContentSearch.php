<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Content\ProviderPool;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * GET /horizon/api/v1/content/search?q=...&limit=10&type=post
 *
 * Keyword search over the store's editorial content (CMS pages, blog posts).
 * An empty query returns the most recent items.
 */
class ContentSearch extends AbstractAction
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
        $query = $this->getStringParam('q') ?? '';
        $limit = $this->getIntParam('limit', 10);
        $type = $this->getStringParam('type');

        return $result->setData($this->providerPool->search($query, $limit, $type));
    }
}
