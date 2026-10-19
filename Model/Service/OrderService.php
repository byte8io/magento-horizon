<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class OrderService
{
    private const STATES_NOT_DELIVERABLE = [Order::STATE_CANCELED, Order::STATE_CLOSED, Order::STATE_HOLDED];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly DeliveryWindowResolver $deliveryWindows
    ) {
    }

    /**
     * Search orders with filters.
     * Returns shape matching Rust OrdersResult.
     */
    public function search(
        ?string $status = null,
        ?string $customerEmail = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        int $limit = 20,
        int $page = 1
    ): array {
        if ($status !== null) {
            $this->searchCriteriaBuilder->addFilter('status', $status);
        }
        if ($customerEmail !== null) {
            $this->searchCriteriaBuilder->addFilter('customer_email', '%' . $customerEmail . '%', 'like');
        }
        if ($fromDate !== null) {
            $this->searchCriteriaBuilder->addFilter('created_at', $fromDate . ' 00:00:00', 'gteq');
        }
        if ($toDate !== null) {
            $this->searchCriteriaBuilder->addFilter('created_at', $toDate . ' 23:59:59', 'lteq');
        }

        $sortOrder = $this->sortOrderBuilder
            ->setField('created_at')
            ->setDescendingDirection()
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->setSortOrders([$sortOrder])
            ->setPageSize($limit)
            ->setCurrentPage($page)
            ->create();

        $result = $this->orderRepository->getList($searchCriteria);

        $orders = [];
        foreach ($result->getItems() as $order) {
            $orders[] = [
                'entity_id' => (int) $order->getEntityId(),
                'increment_id' => $order->getIncrementId() ?? '',
                'status' => $order->getStatus() ?? '',
                'state' => $order->getState() ?? '',
                'grand_total' => (float) $order->getGrandTotal(),
                'currency' => $order->getOrderCurrencyCode() ?? '',
                'customer_email' => $order->getCustomerEmail() ?? '',
                'customer_name' => trim(
                    ($order->getCustomerFirstname() ?? '') . ' ' . ($order->getCustomerLastname() ?? '')
                ),
                'item_count' => (int) $order->getTotalItemCount(),
                'created_at' => $order->getCreatedAt() ?? '',
                'updated_at' => $order->getUpdatedAt() ?? '',
            ];
        }

        return [
            'orders' => $orders,
            'total_count' => (int) $result->getTotalCount(),
            'page' => $page,
            'page_size' => $limit,
        ];
    }

    /**
     * Get order detail by increment ID or entity ID.
     * Returns shape matching Rust OrderDetail.
     */
    public function getDetail(string $identifier): ?array
    {
        $order = null;

        // Try increment_id first (most common), then entity_id
        $this->searchCriteriaBuilder->addFilter('increment_id', $identifier);
        $searchCriteria = $this->searchCriteriaBuilder->setPageSize(1)->create();
        $result = $this->orderRepository->getList($searchCriteria);

        if ($result->getTotalCount() > 0) {
            $items = $result->getItems();
            $order = reset($items);
        }

        // Fall back to entity_id
        if ($order === null && is_numeric($identifier)) {
            try {
                $order = $this->orderRepository->get((int) $identifier);
            } catch (\Exception $e) {
                return null;
            }
        }

        if ($order === null) {
            return null;
        }

        // Items
        $items = [];
        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId()) {
                continue; // Skip child items of configurables
            }
            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty_ordered' => (float) $item->getQtyOrdered(),
                'qty_shipped' => (float) $item->getQtyShipped(),
                'qty_refunded' => (float) $item->getQtyRefunded(),
                'price' => (float) $item->getPrice(),
                'row_total' => (float) $item->getRowTotal(),
            ];
        }

        $shipments = $this->formatShipments($order);
        $fulfilment = $this->fulfilmentStatus($items, $shipments);
        $shippedAt = $shipments !== [] ? end($shipments)['shipped_at'] : null;

        // Addresses
        $shippingAddress = $this->formatAddress($order->getShippingAddress());
        $billingAddress = $this->formatAddress($order->getBillingAddress());

        // Payment
        $payment = $order->getPayment();
        $paymentMethod = $payment ? ($payment->getAdditionalInformation('method_title') ?? $payment->getMethod()) : '';

        return [
            'entity_id' => (int) $order->getEntityId(),
            'increment_id' => $order->getIncrementId() ?? '',
            'status' => $order->getStatus() ?? '',
            'state' => $order->getState() ?? '',
            'grand_total' => (float) $order->getGrandTotal(),
            'subtotal' => (float) $order->getSubtotal(),
            'tax_amount' => (float) $order->getTaxAmount(),
            'shipping_amount' => (float) $order->getShippingAmount(),
            'discount_amount' => (float) ($order->getDiscountAmount() ?? 0),
            'currency' => $order->getOrderCurrencyCode() ?? '',
            'customer_email' => $order->getCustomerEmail() ?? '',
            'customer_name' => trim(
                ($order->getCustomerFirstname() ?? '') . ' ' . ($order->getCustomerLastname() ?? '')
            ),
            'items' => $items,
            'shipping_address' => $shippingAddress,
            'billing_address' => $billingAddress,
            'payment_method' => $paymentMethod,
            'shipping_method' => $order->getShippingDescription() ?? '',
            'shipping_method_code' => $order->getShippingMethod() ?? '',
            'created_at' => $order->getCreatedAt() ?? '',
            'updated_at' => $order->getUpdatedAt() ?? '',
            'shipments' => $shipments,
            'fulfilment_status' => $fulfilment,
            'shipped_at' => $shippedAt,
            'delivery_estimate' => $this->deliveryEstimate($order, $fulfilment, $shippedAt),
        ];
    }

    /**
     * @return array<int, array{increment_id:string,shipped_at:string,carrier_code:?string,carrier_title:?string,tracking:array,items:array}>
     */
    private function formatShipments(OrderInterface $order): array
    {
        if (!$order instanceof Order) {
            return [];
        }
        $shipments = [];
        foreach ($order->getShipmentsCollection() as $shipment) {
            $tracking = [];
            $carrierCode = null;
            $carrierTitle = null;
            foreach ($shipment->getTracks() as $track) {
                $carrierCode ??= $track->getCarrierCode();
                $carrierTitle ??= $track->getTitle();
                $tracking[] = [
                    'number' => (string) $track->getTrackNumber(),
                    'title' => (string) $track->getTitle(),
                    'carrier_code' => (string) $track->getCarrierCode(),
                ];
            }
            $items = [];
            foreach ($shipment->getAllItems() as $item) {
                $items[] = ['sku' => (string) $item->getSku(), 'qty' => (float) $item->getQty()];
            }
            $shipments[] = [
                'increment_id' => (string) $shipment->getIncrementId(),
                'shipped_at' => (string) $shipment->getCreatedAt(),
                'carrier_code' => $carrierCode,
                'carrier_title' => $carrierTitle,
                'tracking' => $tracking,
                'items' => $items,
            ];
        }
        usort($shipments, static fn (array $a, array $b): int => strcmp($a['shipped_at'], $b['shipped_at']));
        return $shipments;
    }

    /**
     * not_shipped | partially_shipped | shipped — ignoring virtual lines and refunded qty.
     */
    private function fulfilmentStatus(array $items, array $shipments): string
    {
        $ordered = 0.0;
        $shipped = 0.0;
        foreach ($items as $item) {
            $ordered += max(0.0, $item['qty_ordered'] - $item['qty_refunded']);
            $shipped += $item['qty_shipped'];
        }
        if ($shipments === [] || $shipped <= 0.0) {
            return 'not_shipped';
        }
        return $shipped + 0.0001 >= $ordered ? 'shipped' : 'partially_shipped';
    }

    /**
     * Server-side projection of when the order should arrive, so the model
     * never does calendar arithmetic. Null when the order can't be delivered
     * (cancelled/closed/on hold) — the status explains that on its own.
     */
    private function deliveryEstimate(OrderInterface $order, string $fulfilment, ?string $shippedAt): ?array
    {
        if (in_array((string) $order->getState(), self::STATES_NOT_DELIVERABLE, true)) {
            return null;
        }

        $method = $order instanceof Order ? $order->getShippingMethod(true) : null;
        $carrierCode = $method ? (string) $method->getData('carrier_code') : '';
        $methodCode = $method ? (string) $method->getData('method') : null;
        if ($carrierCode === '') {
            return null; // virtual order or no shipping
        }

        $window = $this->deliveryWindows->transitWindow(
            $carrierCode,
            $methodCode !== '' ? $methodCode : null,
            (string) $order->getShippingDescription()
        );

        $today = $this->deliveryWindows->today();
        $handlingDays = $this->deliveryWindows->handlingDays();
        $estimate = [
            'today' => $today,
            'basis' => $fulfilment === 'not_shipped' ? 'order_placed' : 'shipped',
            'basis_date' => $fulfilment === 'not_shipped' ? (string) $order->getCreatedAt() : (string) $shippedAt,
            'handling_days' => $handlingDays,
            'transit_min_days' => $window['min_days'] ?? null,
            'transit_max_days' => $window['max_days'] ?? null,
            'business_days' => $window['business_days'] ?? $this->deliveryWindows->isBusinessDays(),
            'source' => $window['source'] ?? null,
            'earliest' => null,
            'latest' => null,
            'is_late' => false,
        ];

        if ($window === null) {
            return $estimate;
        }

        $range = $this->deliveryWindows->project(
            $estimate['basis_date'],
            $estimate['basis'] === 'order_placed' ? $handlingDays : 0,
            $window
        );
        $estimate['earliest'] = $range['earliest'];
        $estimate['latest'] = $range['latest'];
        $estimate['is_late'] = $today > $range['latest'];

        return $estimate;
    }

    private function formatAddress(?\Magento\Sales\Api\Data\OrderAddressInterface $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'firstname' => $address->getFirstname() ?? '',
            'lastname' => $address->getLastname() ?? '',
            'street' => $address->getStreet() ?? [],
            'city' => $address->getCity() ?? '',
            'region' => $address->getRegion() ?? '',
            'postcode' => $address->getPostcode() ?? '',
            'country_id' => $address->getCountryId() ?? '',
            'telephone' => $address->getTelephone() ?? '',
        ];
    }
}
