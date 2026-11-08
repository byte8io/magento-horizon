<?php

declare(strict_types=1);

namespace Byte8\Horizon;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

class Router implements RouterInterface
{
    /**
     * Map of URI paths to controller class names.
     */
    private const ROUTE_MAP = [
        'horizon/api/v1/health'      => Controller\Api\Health::class,
        'horizon/api/v1/products'    => Controller\Api\Products::class,
        'horizon/api/v1/categories'  => Controller\Api\Categories::class,
        'horizon/api/v1/inventory'   => Controller\Api\Inventory::class,
        'horizon/api/v1/orders'      => Controller\Api\Orders::class,
        'horizon/api/v1/customers'   => Controller\Api\Customers::class,
        'horizon/api/v1/reviews'     => Controller\Api\Reviews::class,
        'horizon/api/v1/shipping/estimate' => Controller\Api\ShippingEstimate::class,
        'horizon/api/v1/store-info'  => Controller\Api\StoreInfo::class,
        'horizon/api/v1/content/search' => Controller\Api\ContentSearch::class,
        'horizon/api/v1/ai-visibility' => Controller\Api\AiVisibility::class,
        'horizon/api/v1/admin/cms-page' => Controller\Api\Admin\CmsPageSave::class,
        'horizon/api/v1/admin/product' => Controller\Api\Admin\ProductSave::class,
        'horizon/api/v1/admin/products' => Controller\Api\Admin\ProductsSave::class,
        'horizon/api/v1/admin/category' => Controller\Api\Admin\CategorySave::class,
        'horizon/api/v1/admin/attribute-options' => Controller\Api\Admin\AttributeOptionsAdd::class,
        'horizon/api/v1/admin/attribute' => Controller\Api\Admin\AttributeCreate::class,
        'horizon/api/v1/admin/order/invoice' => Controller\Api\Admin\OrderInvoice::class,
        'horizon/api/v1/admin/order/shipment' => Controller\Api\Admin\OrderShipment::class,
        'horizon/api/v1/admin/order/fulfil' => Controller\Api\Admin\OrderFulfil::class,
        'horizon/api/v1/admin/order/comment' => Controller\Api\Admin\OrderComment::class,
        'horizon/api/v1/admin/order/hold' => Controller\Api\Admin\OrderHold::class,
        'horizon/api/v1/admin/order/cancel' => Controller\Api\Admin\OrderCancel::class,
        'horizon/api/v1/admin/customer' => Controller\Api\Admin\CustomerSave::class,
        'horizon/api/v1/admin/customer/address' => Controller\Api\Admin\CustomerAddressSave::class,
        'horizon/api/v1/admin/customer/newsletter' => Controller\Api\Admin\CustomerNewsletter::class,
    ];

    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    public function match(RequestInterface $request): ?\Magento\Framework\App\ActionInterface
    {
        $path = trim($request->getPathInfo(), '/');

        // Exact match (e.g. horizon/api/v1/products)
        if (isset(self::ROUTE_MAP[$path])) {
            $request->setModuleName('horizon');
            $request->setControllerName('api');
            $request->setActionName(basename(str_replace('\\', '/', self::ROUTE_MAP[$path])));
            return $this->actionFactory->create(self::ROUTE_MAP[$path]);
        }

        // Dynamic routes with identifier: /horizon/api/v1/products/{id} and /horizon/api/v1/orders/{id}
        if (preg_match('#^horizon/api/v1/products/(.+)$#', $path, $matches)) {
            $request->setModuleName('horizon');
            $request->setControllerName('api');
            $request->setActionName('ProductDetail');
            $request->setParam('identifier', $matches[1]);
            return $this->actionFactory->create(Controller\Api\ProductDetail::class);
        }

        if (preg_match('#^horizon/api/v1/orders/(.+)$#', $path, $matches)) {
            $request->setModuleName('horizon');
            $request->setControllerName('api');
            $request->setActionName('OrderDetail');
            $request->setParam('identifier', $matches[1]);
            return $this->actionFactory->create(Controller\Api\OrderDetail::class);
        }

        if (preg_match('#^horizon/api/v1/content/([a-z]+)/(.+)$#', $path, $matches)) {
            $request->setModuleName('horizon');
            $request->setControllerName('api');
            $request->setActionName('ContentDetail');
            $request->setParam('type', $matches[1]);
            $request->setParam('identifier', rtrim($matches[2], '/'));
            return $this->actionFactory->create(Controller\Api\ContentDetail::class);
        }

        return null;
    }
}
