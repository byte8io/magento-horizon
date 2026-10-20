<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Service\ProductService;

class Products extends AbstractAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        private readonly ProductService $productService
    ) {
        parent::__construct($request, $jsonFactory, $config);
    }

    protected function handleRequest(Json $result): Json
    {
        $query = $this->getStringParam('q') ?? '*';
        $limit = min($this->getIntParam('limit', 10), 50);
        $page = max($this->getIntParam('page', 1), 1);
        $categoryId = $this->getStringParam('category_id');
        $productType = $this->getStringParam('product_type');
        $inStockOnly = $this->getBoolParam('in_stock_only', false);
        $sort = $this->getStringParam('sort');

        $data = $this->productService->search(
            $query,
            $limit,
            $page,
            $categoryId !== null ? (int) $categoryId : null,
            $productType,
            $inStockOnly,
            $sort
        );

        return $result->setData($data);
    }
}
