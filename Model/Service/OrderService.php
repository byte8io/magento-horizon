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
        $history = $this->historyFacts($order);

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
            'payment' => $this->paymentSummary($order, $paymentMethod),
            'invoices' => $this->formatInvoices($order),
            'refunds' => $this->formatRefunds($order),
            'canceled_at' => $history['canceled_at'],
            'last_comment' => $history['last_comment'],
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
     * Payment position of the order, so an assistant can answer "is it paid?"
     * without seeing card or account details. `status` is one of: paid,
     * partially_paid, unpaid, awaiting_payment, payment_review, refunded,
     * partially_refunded, canceled, free.
     *
     * @return array<string, mixed>
     */
    private function paymentSummary(OrderInterface $order, string $methodTitle): array
    {
        $grandTotal = (float) $order->getGrandTotal();
        $paid = (float) ($order->getTotalPaid() ?? 0);
        $due = (float) ($order->getTotalDue() ?? 0);
        $refunded = (float) ($order->getTotalRefunded() ?? 0);
        $state = (string) $order->getState();

        $paidAt = null;
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ((int) $invoice->getState() === \Magento\Sales\Model\Order\Invoice::STATE_PAID
                && ($paidAt === null || $invoice->getCreatedAt() > $paidAt)
            ) {
                $paidAt = $invoice->getCreatedAt();
            }
        }

        if ($state === Order::STATE_CANCELED) {
            $status = 'canceled';
        } elseif ($grandTotal <= 0.009) {
            $status = 'free';
        } elseif ($refunded > 0 && $refunded >= $paid - 0.009) {
            $status = 'refunded';
        } elseif ($refunded > 0) {
            $status = 'partially_refunded';
        } elseif ($due <= 0.009 && $paid >= $grandTotal - 0.009) {
            $status = 'paid';
        } elseif ($paid > 0) {
            $status = 'partially_paid';
        } elseif ($state === Order::STATE_PAYMENT_REVIEW) {
            $status = 'payment_review';
        } elseif ($state === Order::STATE_NEW || $state === Order::STATE_PENDING_PAYMENT) {
            $status = 'awaiting_payment';
        } else {
            $status = 'unpaid';
        }

        $payment = $order->getPayment();
        $refundedOnline = $payment ? (float) ($payment->getBaseAmountRefundedOnline() ?? 0) : 0.0;
        $refundedBase = $payment ? (float) ($payment->getBaseAmountRefunded() ?? 0) : 0.0;

        return [
            'method' => $methodTitle,
            'status' => $status,
            'total_paid' => $paid,
            'total_due' => $due,
            'total_refunded' => $refunded,
            'refunded_online' => $refundedOnline,
            'refunded_offline' => max(0.0, round($refundedBase - $refundedOnline, 2)),
            'paid_at' => $paidAt,
        ];
    }

    /**
     * Invoices raised on the order (number, date, state, amount) — enough for
     * "where is my invoice?"; the document itself stays in the account area.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatInvoices(OrderInterface $order): array
    {
        $states = [
            \Magento\Sales\Model\Order\Invoice::STATE_OPEN => 'open',
            \Magento\Sales\Model\Order\Invoice::STATE_PAID => 'paid',
            \Magento\Sales\Model\Order\Invoice::STATE_CANCELED => 'canceled',
        ];
        $out = [];
        foreach ($order->getInvoiceCollection() as $invoice) {
            $out[] = [
                'increment_id' => (string) $invoice->getIncrementId(),
                'created_at' => (string) $invoice->getCreatedAt(),
                'state' => $states[(int) $invoice->getState()] ?? 'unknown',
                'grand_total' => (float) $invoice->getGrandTotal(),
            ];
        }
        return $out;
    }

    /**
     * Credit memos (refunds): date, amount, what it covered. Whether the money
     * went back online or was arranged offline is on the payment summary.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatRefunds(OrderInterface $order): array
    {
        $states = [
            \Magento\Sales\Model\Order\Creditmemo::STATE_OPEN => 'open',
            \Magento\Sales\Model\Order\Creditmemo::STATE_REFUNDED => 'refunded',
            \Magento\Sales\Model\Order\Creditmemo::STATE_CANCELED => 'canceled',
        ];
        $out = [];
        foreach ($order->getCreditmemosCollection() as $memo) {
            $items = [];
            foreach ($memo->getAllItems() as $item) {
                $orderItem = $item->getOrderItem();
                if ((float) $item->getQty() <= 0 || ($orderItem && $orderItem->getParentItemId())) {
                    continue;
                }
                $items[] = ['sku' => (string) $item->getSku(), 'qty' => (float) $item->getQty()];
            }
            $out[] = [
                'increment_id' => (string) $memo->getIncrementId(),
                'created_at' => (string) $memo->getCreatedAt(),
                'state' => $states[(int) $memo->getState()] ?? 'unknown',
                'grand_total' => (float) $memo->getGrandTotal(),
                'shipping_amount' => (float) ($memo->getShippingAmount() ?? 0),
                'adjustment_positive' => (float) ($memo->getAdjustmentPositive() ?? 0),
                'adjustment_negative' => (float) ($memo->getAdjustmentNegative() ?? 0),
                'items' => $items,
            ];
        }
        return $out;
    }

    /**
     * Facts from the status history: when the order was cancelled and the most
     * recent comment the merchant chose to make visible to the customer.
     * Internal (admin-only) comments are never exposed.
     *
     * @return array{canceled_at: ?string, last_comment: ?string}
     */
    private function historyFacts(OrderInterface $order): array
    {
        $canceledAt = null;
        $lastComment = null;
        $isCanceled = $order->getState() === Order::STATE_CANCELED;
        foreach ($order->getStatusHistories() ?? [] as $entry) {
            $createdAt = (string) $entry->getCreatedAt();
            if ($isCanceled
                && $entry->getStatus() === $order->getStatus()
                && ($canceledAt === null || $createdAt > $canceledAt)
            ) {
                $canceledAt = $createdAt;
            }
            $comment = trim((string) $entry->getComment());
            if ($comment !== '' && (int) $entry->getIsVisibleOnFront() === 1
                && ($lastComment === null || $createdAt > $lastComment['at'])
            ) {
                $lastComment = ['at' => $createdAt, 'text' => $comment];
            }
        }
        if ($isCanceled && $canceledAt === null) {
            $canceledAt = (string) $order->getUpdatedAt();
        }
        return ['canceled_at' => $canceledAt, 'last_comment' => $lastComment['text'] ?? null];
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
