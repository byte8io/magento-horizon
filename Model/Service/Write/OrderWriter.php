<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service\Write;

use Byte8\Horizon\Controller\Api\Admin\WriteException;
use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Service\OrderService;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\Order\Email\Sender\ShipmentSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\ScopeInterface;

/**
 * Order actions an agent may take with the `sales:write` scope: invoice,
 * ship, fulfil (both), comment, hold / unhold and cancel. Money never moves
 * backwards here — refunds and credit memos stay a human action — and every
 * method returns the order's position afterwards so the agent can verify.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class OrderWriter
{
    public const CAPTURE_ONLINE = 'online';
    public const CAPTURE_OFFLINE = 'offline';
    public const CAPTURE_NONE = 'none';

    private const SUMMARY_FIELDS = ['state', 'status', 'payment_status', 'fulfilment_status', 'total_paid', 'total_due'];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly InvoiceService $invoiceService,
        private readonly ShipmentFactory $shipmentFactory,
        private readonly TransactionFactory $transactionFactory,
        private readonly InvoiceSender $invoiceSender,
        private readonly ShipmentSender $shipmentSender,
        private readonly OrderCommentSender $commentSender,
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly OrderService $orderService,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function invoice(array $body, bool $dryRun): array
    {
        $order = $this->load($body['order'] ?? null);
        $before = $this->summary($order);
        $plan = $this->planInvoice($order, $body);
        $notify = $this->flag($body, 'notify');
        $comment = $this->text($body, 'comment');

        if (!$dryRun) {
            $invoice = $this->applyInvoice($plan['invoice'], $plan['capture'], $comment, $notify);
            $this->sendInvoice($invoice, $notify);
            $order = $this->reload($order);
            $plan['document']['increment_id'] = (string) $invoice->getIncrementId();
            $after = $this->summary($order);
        } else {
            $after = $this->predictInvoice($before, $plan);
        }

        $changes = $this->diff($before, $after);
        $changes[] = ['field' => 'invoice', 'before' => null, 'after' => $plan['document']];
        if ($comment !== null) {
            $changes[] = ['field' => 'comment', 'before' => null, 'after' => $comment];
        }
        return $this->result('invoice', $dryRun, !$dryRun, $order, $after, $changes, [
            'invoice' => $plan['document'],
            'shipment' => null,
            'comment' => $comment,
            'notified' => !$dryRun && $notify,
            'failed_step' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function ship(array $body, bool $dryRun): array
    {
        $order = $this->load($body['order'] ?? null);
        $before = $this->summary($order);
        $this->assertPaidForShipping($order);
        $plan = $this->planShipment($order, $body);
        $notify = $this->flag($body, 'notify');
        $comment = $this->text($body, 'comment');

        if (!$dryRun) {
            $shipment = $this->applyShipment($plan['shipment'], $comment, $notify);
            $this->sendShipment($shipment, $notify);
            $order = $this->reload($order);
            $plan['document']['increment_id'] = (string) $shipment->getIncrementId();
            $after = $this->summary($order);
        } else {
            $after = $this->predictShipment($before, $order, $plan);
        }

        $changes = $this->diff($before, $after);
        $changes[] = ['field' => 'shipment', 'before' => null, 'after' => $plan['document']];
        if ($comment !== null) {
            $changes[] = ['field' => 'comment', 'before' => null, 'after' => $comment];
        }
        return $this->result('ship', $dryRun, !$dryRun, $order, $after, $changes, [
            'invoice' => null,
            'shipment' => $plan['document'],
            'comment' => $comment,
            'notified' => !$dryRun && $notify,
            'failed_step' => null,
        ]);
    }

    /**
     * Invoice then ship in one call. With an offline (or no) capture both
     * documents are written in one database transaction, so a refused
     * shipment leaves no invoice behind. An online capture cannot be undone
     * at the payment provider, so that path is sequential: a shipment failure
     * after a successful capture is reported as `failed_step: shipment` and
     * the invoice stands.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function fulfil(array $body, bool $dryRun): array
    {
        $order = $this->load($body['order'] ?? null);
        $before = $this->summary($order);
        $invoicePlan = $this->planInvoice($order, $body);
        // Validate the shipment up front (items, tracking, state) so an
        // impossible shipment is refused before any money is captured.
        $shipmentPlan = $this->planShipment($order, $body);
        $notify = $this->flag($body, 'notify');
        $comment = $this->text($body, 'comment');
        $failedStep = null;

        if (!$dryRun) {
            $atomic = $invoicePlan['capture'] !== self::CAPTURE_ONLINE;
            $connection = $this->resource->getConnection('sales');
            if ($atomic) {
                $connection->beginTransaction();
            }
            try {
                $invoice = $this->applyInvoice($invoicePlan['invoice'], $invoicePlan['capture'], $comment, $notify);
                $invoicePlan['document']['increment_id'] = (string) $invoice->getIncrementId();
                // The invoice may have paid the order in memory; the shipping
                // guard looks at that position, not the stale database row.
                $this->assertPaidForShipping($invoice->getOrder());
                $shipment = $this->applyShipment($shipmentPlan['shipment'], null, $notify);
                $shipmentPlan['document']['increment_id'] = (string) $shipment->getIncrementId();
                if ($atomic) {
                    $connection->commit();
                }
            } catch (\Throwable $e) {
                if ($atomic) {
                    $connection->rollBack();
                    throw $e;
                }
                if (!isset($invoice)) {
                    throw $e;
                }
                $failedStep = 'shipment';
                $shipment = null;
                $shipmentPlan['document'] = null;
            }
            $this->sendInvoice($invoice, $notify);
            if ($shipment !== null) {
                $this->sendShipment($shipment, $notify);
            }
            $order = $this->reload($order);
            $after = $this->summary($order);
        } else {
            $after = $this->predictShipment($this->predictInvoice($before, $invoicePlan), $order, $shipmentPlan);
        }

        $changes = $this->diff($before, $after);
        $changes[] = ['field' => 'invoice', 'before' => null, 'after' => $invoicePlan['document']];
        if ($shipmentPlan['document'] !== null) {
            $changes[] = ['field' => 'shipment', 'before' => null, 'after' => $shipmentPlan['document']];
        }
        if ($comment !== null) {
            $changes[] = ['field' => 'comment', 'before' => null, 'after' => $comment];
        }
        return $this->result('fulfil', $dryRun, !$dryRun, $order, $after, $changes, [
            'invoice' => $invoicePlan['document'],
            'shipment' => $shipmentPlan['document'],
            'comment' => $comment,
            'notified' => !$dryRun && $notify,
            'failed_step' => $failedStep,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function comment(array $body, bool $dryRun): array
    {
        $order = $this->load($body['order'] ?? null);
        $before = $this->summary($order);
        $comment = $this->text($body, 'comment');
        if ($comment === null || $comment === '') {
            throw new WriteException('"comment" is required', 'VALIDATION', 400);
        }
        if (mb_strlen($comment) > 2000) {
            throw new WriteException('"comment" is limited to 2000 characters', 'VALIDATION', 400);
        }
        $notify = $this->flag($body, 'notify');
        // An emailed comment is by definition visible to the customer.
        $visible = $notify || $this->flag($body, 'visible_to_customer');

        if (!$dryRun) {
            $history = $order->addCommentToStatusHistory($comment, false, $visible);
            $history->setIsCustomerNotified($notify);
            $this->orderRepository->save($order);
            if ($notify) {
                $this->commentSender->send($order, true, $comment);
            }
            $order = $this->reload($order);
        }
        $after = $this->summary($order);
        $changes = [[
            'field' => 'comment',
            'before' => null,
            'after' => ['text' => $comment, 'visible_to_customer' => $visible, 'notify' => $notify],
        ]];
        return $this->result('comment', $dryRun, !$dryRun, $order, $after, $changes, [
            'invoice' => null,
            'shipment' => null,
            'comment' => $comment,
            'notified' => !$dryRun && $notify,
            'failed_step' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function hold(array $body, bool $dryRun, bool $hold): array
    {
        $order = $this->load($body['order'] ?? null);
        $before = $this->summary($order);
        $comment = $this->text($body, 'comment');
        if ($hold && !$order->canHold()) {
            throw new WriteException(
                sprintf('Order %s cannot be put on hold in state "%s"', $order->getIncrementId(), $order->getState()),
                'CANNOT_HOLD',
                422
            );
        }
        if (!$hold && !$order->canUnhold()) {
            throw new WriteException(
                sprintf('Order %s is not on hold (state "%s")', $order->getIncrementId(), $order->getState()),
                'NOT_ON_HOLD',
                422
            );
        }

        if (!$dryRun) {
            $hold ? $order->hold() : $order->unhold();
            if ($comment !== null && $comment !== '') {
                $order->addCommentToStatusHistory($comment, false, false);
            }
            $this->orderRepository->save($order);
            $order = $this->reload($order);
            $after = $this->summary($order);
        } else {
            $after = $before;
            if ($hold) {
                $after['state'] = Order::STATE_HOLDED;
                $after['status'] = $order->getConfig()->getStateDefaultStatus(Order::STATE_HOLDED);
            } else {
                $after['state'] = (string) $order->getHoldBeforeState();
                $after['status'] = (string) $order->getHoldBeforeStatus();
            }
        }
        $changes = $this->diff($before, $after);
        if ($comment !== null && $comment !== '') {
            $changes[] = ['field' => 'comment', 'before' => null, 'after' => $comment];
        }
        return $this->result($hold ? 'hold' : 'unhold', $dryRun, !$dryRun, $order, $after, $changes, [
            'invoice' => null,
            'shipment' => null,
            'comment' => $comment,
            'notified' => false,
            'failed_step' => null,
        ]);
    }

    /**
     * Cancel an order no money has been taken for. Anything paid needs a
     * credit memo, which Horizon does not issue.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function cancel(array $body, bool $dryRun): array
    {
        $order = $this->load($body['order'] ?? null);
        $before = $this->summary($order);
        $comment = $this->text($body, 'comment');
        $notify = $this->flag($body, 'notify');

        if ((float) $order->getTotalPaid() > 0.009 || $this->hasPaidInvoice($order)) {
            throw new WriteException(
                sprintf(
                    'Order %s has %s %.2f paid; cancelling it needs a refund (credit memo), which is a human action in admin',
                    $order->getIncrementId(),
                    $order->getOrderCurrencyCode(),
                    (float) $order->getTotalPaid()
                ),
                'REFUND_REQUIRED',
                422
            );
        }
        if (!$order->canCancel()) {
            throw new WriteException(
                sprintf('Order %s cannot be cancelled: %s', $order->getIncrementId(), $this->whyNotCancel($order)),
                'CANNOT_CANCEL',
                422
            );
        }

        if (!$dryRun) {
            $order->cancel();
            if ($comment !== null && $comment !== '') {
                $history = $order->addCommentToStatusHistory($comment, false, $notify);
                $history->setIsCustomerNotified($notify);
            }
            $this->orderRepository->save($order);
            if ($notify && $comment !== null && $comment !== '') {
                $this->commentSender->send($order, true, $comment);
            }
            $order = $this->reload($order);
            $after = $this->summary($order);
        } else {
            $after = $before;
            $after['state'] = Order::STATE_CANCELED;
            $after['status'] = $order->getConfig()->getStateDefaultStatus(Order::STATE_CANCELED);
            $after['payment_status'] = 'canceled';
        }
        $changes = $this->diff($before, $after);
        if ($comment !== null && $comment !== '') {
            $changes[] = ['field' => 'comment', 'before' => null, 'after' => $comment];
        }
        return $this->result('cancel', $dryRun, !$dryRun, $order, $after, $changes, [
            'invoice' => null,
            'shipment' => null,
            'comment' => $comment,
            'notified' => !$dryRun && $notify && $comment !== null && $comment !== '',
            'failed_step' => null,
        ]);
    }

    // ----------------------------------------------------------------- plans

    /**
     * @param array<string, mixed> $body
     * @return array{invoice: Invoice, capture: string, document: array<string, mixed>}
     */
    private function planInvoice(Order $order, array $body): array
    {
        if (!$order->canInvoice()) {
            throw new WriteException(
                sprintf('Order %s cannot be invoiced: %s', $order->getIncrementId(), $this->whyNotInvoice($order)),
                'CANNOT_INVOICE',
                422
            );
        }
        $qtys = $this->itemQtys($order, $body, 'invoice');
        $capture = $this->captureCase($order, $body);
        /** @var Invoice $invoice */
        $invoice = $this->invoiceService->prepareInvoice($order, $qtys);
        if ((float) $invoice->getTotalQty() <= 0) {
            throw new WriteException('Nothing left to invoice on this order', 'CANNOT_INVOICE', 422);
        }
        $items = [];
        foreach ($invoice->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if ((float) $item->getQty() <= 0 || ($orderItem && $orderItem->getParentItemId())) {
                continue;
            }
            $items[] = ['sku' => (string) $item->getSku(), 'qty' => (float) $item->getQty()];
        }
        return [
            'invoice' => $invoice,
            'capture' => $capture,
            'document' => [
                'increment_id' => null,
                'grand_total' => round((float) $invoice->getGrandTotal(), 2),
                'capture' => $capture,
                'items' => $items,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{shipment: Shipment, document: array<string, mixed>}
     */
    private function planShipment(Order $order, array $body): array
    {
        if (!$order->canShip()) {
            throw new WriteException(
                sprintf('Order %s cannot be shipped: %s', $order->getIncrementId(), $this->whyNotShip($order)),
                'CANNOT_SHIP',
                422
            );
        }
        $qtys = $this->itemQtys($order, $body, 'ship');
        if ($qtys === []) {
            foreach ($order->getAllItems() as $orderItem) {
                if ($orderItem->getParentItemId() || $orderItem->getIsVirtual()) {
                    continue;
                }
                if ((float) $orderItem->getQtyToShip() > 0) {
                    $qtys[(int) $orderItem->getId()] = (float) $orderItem->getQtyToShip();
                }
            }
        }
        $tracks = $this->tracks($body);
        /** @var Shipment $shipment */
        $shipment = $this->shipmentFactory->create($order, $qtys, $tracks);
        if ((float) $shipment->getTotalQty() <= 0) {
            throw new WriteException('Nothing left to ship on this order', 'CANNOT_SHIP', 422);
        }
        $items = [];
        foreach ($shipment->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if ((float) $item->getQty() <= 0 || ($orderItem && $orderItem->getParentItemId())) {
                continue;
            }
            $items[] = ['sku' => (string) $item->getSku(), 'qty' => (float) $item->getQty()];
        }
        return [
            'shipment' => $shipment,
            'document' => [
                'increment_id' => null,
                'tracking' => array_map(
                    static fn (array $t): array => ['number' => $t['number'], 'carrier' => $t['carrier_code'], 'title' => $t['title']],
                    $tracks
                ),
                'items' => $items,
            ],
        ];
    }

    private function applyInvoice(Invoice $invoice, string $capture, ?string $comment, bool $notify): Invoice
    {
        $invoice->setRequestedCaptureCase(match ($capture) {
            self::CAPTURE_ONLINE => Invoice::CAPTURE_ONLINE,
            self::CAPTURE_OFFLINE => Invoice::CAPTURE_OFFLINE,
            default => Invoice::NOT_CAPTURE,
        });
        if ($comment !== null && $comment !== '') {
            $invoice->addComment($comment, $notify, false);
        }
        $invoice->setCustomerNoteNotify($notify);
        try {
            $invoice->register();
        } catch (LocalizedException $e) {
            throw new WriteException('Magento refused the invoice: ' . $e->getMessage(), 'CANNOT_INVOICE', 422);
        }
        $invoice->getOrder()->setCustomerNoteNotify($notify);
        $invoice->getOrder()->setIsInProcess(true);
        $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder())->save();
        return $invoice;
    }

    private function applyShipment(Shipment $shipment, ?string $comment, bool $notify): Shipment
    {
        if ($comment !== null && $comment !== '') {
            $shipment->addComment($comment, $notify, false);
        }
        $shipment->setCustomerNoteNotify($notify);
        try {
            $shipment->register();
        } catch (LocalizedException $e) {
            throw new WriteException('Magento refused the shipment: ' . $e->getMessage(), 'CANNOT_SHIP', 422);
        }
        $shipment->getOrder()->setCustomerNoteNotify($notify);
        $shipment->getOrder()->setIsInProcess(true);
        $this->transactionFactory->create()->addObject($shipment)->addObject($shipment->getOrder())->save();
        return $shipment;
    }

    private function sendInvoice(Invoice $invoice, bool $notify): void
    {
        if ($notify) {
            $this->invoiceSender->send($invoice);
        }
    }

    private function sendShipment(Shipment $shipment, bool $notify): void
    {
        if ($notify) {
            $this->shipmentSender->send($shipment);
        }
    }

    // --------------------------------------------------------------- helpers

    public function load(mixed $identifier): Order
    {
        $identifier = trim((string) ($identifier ?? ''));
        if ($identifier === '') {
            throw new WriteException('"order" (increment id or entity id) is required', 'VALIDATION', 400);
        }
        $this->searchCriteriaBuilder->addFilter('increment_id', $identifier);
        $list = $this->orderRepository->getList($this->searchCriteriaBuilder->setPageSize(1)->create());
        $order = null;
        if ($list->getTotalCount() > 0) {
            $items = $list->getItems();
            $order = reset($items);
        } elseif (is_numeric($identifier)) {
            try {
                $order = $this->orderRepository->get((int) $identifier);
            } catch (NoSuchEntityException) {
                $order = null;
            }
        }
        if (!$order instanceof Order) {
            throw new WriteException(sprintf('No order "%s"', $identifier), 'NOT_FOUND', 404);
        }
        return $order;
    }

    private function reload(Order $order): Order
    {
        $this->searchCriteriaBuilder->addFilter('entity_id', (int) $order->getEntityId());
        $list = $this->orderRepository->getList($this->searchCriteriaBuilder->setPageSize(1)->create());
        $items = $list->getItems();
        $fresh = reset($items);
        return $fresh instanceof Order ? $fresh : $order;
    }

    /**
     * The order's position as the agent should see it.
     *
     * @return array<string, mixed>
     */
    private function summary(Order $order): array
    {
        $detail = $this->orderService->getDetail((string) $order->getIncrementId()) ?? [];
        $payment = $detail['payment'] ?? [];
        return [
            'increment_id' => (string) $order->getIncrementId(),
            'entity_id' => (int) $order->getEntityId(),
            'state' => (string) ($detail['state'] ?? $order->getState()),
            'status' => (string) ($detail['status'] ?? $order->getStatus()),
            'payment_status' => (string) ($payment['status'] ?? 'unknown'),
            'fulfilment_status' => (string) ($detail['fulfilment_status'] ?? 'not_shipped'),
            'total_paid' => round((float) ($payment['total_paid'] ?? $order->getTotalPaid()), 2),
            'total_due' => round((float) ($payment['total_due'] ?? $order->getTotalDue()), 2),
            'currency' => (string) $order->getOrderCurrencyCode(),
            'invoices' => array_map(static fn (array $i): string => $i['increment_id'], $detail['invoices'] ?? []),
            'shipments' => array_map(static fn (array $s): string => $s['increment_id'], $detail['shipments'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array{invoice: Invoice, capture: string, document: array<string, mixed>} $plan
     * @return array<string, mixed>
     */
    private function predictInvoice(array $before, array $plan): array
    {
        $after = $before;
        $invoice = $plan['invoice'];
        $order = $invoice->getOrder();
        $isGateway = (bool) $order->getPayment()?->getMethodInstance()->isGateway();
        $pays = $plan['capture'] !== self::CAPTURE_NONE && ($plan['capture'] === self::CAPTURE_OFFLINE || !$isGateway || $plan['capture'] === self::CAPTURE_ONLINE);
        if ($pays) {
            $after['total_paid'] = round($before['total_paid'] + (float) $invoice->getGrandTotal(), 2);
            $after['total_due'] = round(max(0.0, (float) $order->getGrandTotal() - $after['total_paid']), 2);
            $after['payment_status'] = $after['total_due'] <= 0.009 ? 'paid' : 'partially_paid';
        }
        if ($before['state'] === Order::STATE_NEW) {
            $after['state'] = Order::STATE_PROCESSING;
            $after['status'] = $order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING);
        }
        return $after;
    }

    /**
     * @param array<string, mixed> $before
     * @param array{shipment: Shipment, document: array<string, mixed>} $plan
     * @return array<string, mixed>
     */
    private function predictShipment(array $before, Order $order, array $plan): array
    {
        $after = $before;
        $toShip = 0.0;
        foreach ($order->getAllItems() as $item) {
            if ($item->getParentItemId() || $item->getIsVirtual()) {
                continue;
            }
            $toShip += (float) $item->getQtyToShip();
        }
        $shipping = (float) $plan['shipment']->getTotalQty();
        $allShipped = $shipping + 0.0001 >= $toShip;
        $after['fulfilment_status'] = $allShipped ? 'shipped' : 'partially_shipped';
        if ($allShipped && $after['payment_status'] === 'paid') {
            $after['state'] = Order::STATE_COMPLETE;
            $after['status'] = $order->getConfig()->getStateDefaultStatus(Order::STATE_COMPLETE);
        } elseif ($before['state'] === Order::STATE_NEW) {
            $after['state'] = Order::STATE_PROCESSING;
            $after['status'] = $order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING);
        }
        return $after;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<int, array{field: string, before: mixed, after: mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (self::SUMMARY_FIELDS as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[] = ['field' => $field, 'before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
            }
        }
        return $changes;
    }

    /**
     * @param array<string, mixed> $after
     * @param array<int, array<string, mixed>> $changes
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function result(string $action, bool $dryRun, bool $saved, Order $order, array $after, array $changes, array $extra): array
    {
        return [
            'action' => $action,
            'dry_run' => $dryRun,
            'saved' => $saved,
            'noop' => false,
            'entity_type' => 'order',
            'entity_id' => (string) $order->getIncrementId(),
            'order' => $after,
        ] + $extra + ['changes' => $changes];
    }

    /**
     * `items: [{sku, qty}]` → [order item id => qty] for parent-level items;
     * empty when the body names no items (= everything that is left).
     *
     * @param array<string, mixed> $body
     * @return array<int, float>
     */
    private function itemQtys(Order $order, array $body, string $purpose): array
    {
        if (!array_key_exists('items', $body) || $body['items'] === null) {
            return [];
        }
        if (!is_array($body['items']) || $body['items'] === []) {
            throw new WriteException('"items" must be a non-empty array of {sku, qty}; omit it to include everything that is left', 'VALIDATION', 400);
        }
        $bySku = [];
        foreach ($order->getAllItems() as $orderItem) {
            if (!$orderItem->getParentItemId()) {
                $bySku[mb_strtolower((string) $orderItem->getSku())] = $orderItem;
            }
        }
        $qtys = [];
        foreach ($body['items'] as $index => $row) {
            $sku = mb_strtolower(trim((string) ($row['sku'] ?? '')));
            if ($sku === '' || !isset($bySku[$sku])) {
                throw new WriteException(sprintf('items[%d]: order %s has no line with SKU "%s"', $index, $order->getIncrementId(), $row['sku'] ?? ''), 'VALIDATION', 400);
            }
            if (!isset($row['qty']) || !is_numeric($row['qty']) || (float) $row['qty'] <= 0) {
                throw new WriteException(sprintf('items[%d]: "qty" must be a positive number', $index), 'VALIDATION', 400);
            }
            $orderItem = $bySku[$sku];
            $left = $purpose === 'invoice' ? (float) $orderItem->getQtyToInvoice() : (float) $orderItem->getQtyToShip();
            if ((float) $row['qty'] > $left + 0.0001) {
                throw new WriteException(
                    sprintf('items[%d]: only %s of SKU "%s" left to %s, %s requested', $index, $this->qty($left), $orderItem->getSku(), $purpose, $this->qty((float) $row['qty'])),
                    'VALIDATION',
                    422
                );
            }
            $qtys[(int) $orderItem->getId()] = (float) $row['qty'];
        }
        return $qtys;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function captureCase(Order $order, array $body): string
    {
        $payment = $order->getPayment();
        $canOnline = $payment !== null && $payment->canCapture();
        $allowOnline = $this->config->isOnlineCaptureAllowed();
        $requested = $this->text($body, 'capture');
        if ($requested === null) {
            return $canOnline && $allowOnline ? self::CAPTURE_ONLINE : self::CAPTURE_OFFLINE;
        }
        $requested = strtolower($requested);
        if (!in_array($requested, [self::CAPTURE_ONLINE, self::CAPTURE_OFFLINE, self::CAPTURE_NONE], true)) {
            throw new WriteException('"capture" must be online, offline or none', 'VALIDATION', 400);
        }
        if ($requested === self::CAPTURE_ONLINE && !$canOnline) {
            throw new WriteException(
                sprintf('Payment method "%s" cannot capture online; use capture: offline if the money was taken another way', $payment?->getMethod() ?? 'unknown'),
                'CAPTURE_UNAVAILABLE',
                422
            );
        }
        if ($requested === self::CAPTURE_ONLINE && !$allowOnline) {
            throw new WriteException('Online capture by agents is switched off for this store (Agent Writes → Allow Online Capture)', 'CAPTURE_DISABLED', 403);
        }
        return $requested;
    }

    /**
     * `tracking`: strings or {number, carrier, title}; `carrier` / `carrier_title`
     * at the top level are defaults for every entry.
     *
     * @param array<string, mixed> $body
     * @return array<int, array{number: string, carrier_code: string, title: string}>
     */
    private function tracks(array $body): array
    {
        $raw = $body['tracking'] ?? [];
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new WriteException('"tracking" must be an array of tracking numbers or {number, carrier, title}', 'VALIDATION', 400);
        }
        $defaultCarrier = strtolower(trim((string) ($this->text($body, 'carrier') ?? 'custom')));
        $defaultTitle = $this->text($body, 'carrier_title');
        $out = [];
        foreach ($raw as $index => $entry) {
            $number = is_array($entry) ? trim((string) ($entry['number'] ?? '')) : trim((string) $entry);
            if ($number === '') {
                throw new WriteException(sprintf('tracking[%d]: "number" is required', $index), 'VALIDATION', 400);
            }
            $carrier = is_array($entry) && !empty($entry['carrier']) ? strtolower(trim((string) $entry['carrier'])) : $defaultCarrier;
            if (preg_match('/^[a-z0-9_]{1,32}$/', $carrier) !== 1) {
                throw new WriteException(sprintf('tracking[%d]: "carrier" must be a carrier code such as ups, dhl, fedex, usps or custom', $index), 'VALIDATION', 400);
            }
            $title = is_array($entry) && !empty($entry['title']) ? trim((string) $entry['title']) : ($defaultTitle ?? $this->carrierTitle($carrier));
            $out[] = ['number' => $number, 'carrier_code' => $carrier, 'title' => $title];
        }
        return $out;
    }

    private function carrierTitle(string $code): string
    {
        $title = (string) $this->scopeConfig->getValue('carriers/' . $code . '/title', ScopeInterface::SCOPE_STORE);
        return $title !== '' ? $title : ucfirst($code);
    }

    private function assertPaidForShipping(Order $order): void
    {
        if ($this->config->isShipUnpaidAllowed()) {
            return;
        }
        if ((float) $order->getTotalDue() > 0.009) {
            throw new WriteException(
                sprintf(
                    'Order %s still has %s %.2f due; invoice it first (create_invoice or fulfil_order), or the merchant can allow shipping unpaid orders under Agent Writes',
                    $order->getIncrementId(),
                    $order->getOrderCurrencyCode(),
                    (float) $order->getTotalDue()
                ),
                'UNPAID',
                422
            );
        }
    }

    private function hasPaidInvoice(Order $order): bool
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ((int) $invoice->getState() === Invoice::STATE_PAID) {
                return true;
            }
        }
        return false;
    }

    private function whyNotInvoice(Order $order): string
    {
        return match (true) {
            $order->canUnhold() => 'it is on hold',
            $order->isPaymentReview() => 'its payment is under review',
            $order->isCanceled() => 'it is cancelled',
            in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true) => 'it is ' . $order->getState(),
            default => 'everything is already invoiced',
        };
    }

    private function whyNotShip(Order $order): string
    {
        return match (true) {
            $order->canUnhold() => 'it is on hold',
            $order->isPaymentReview() => 'its payment is under review',
            $order->isCanceled() => 'it is cancelled',
            $order->getIsVirtual() => 'it is virtual (nothing to ship)',
            in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true) => 'it is ' . $order->getState(),
            default => 'everything is already shipped',
        };
    }

    private function whyNotCancel(Order $order): string
    {
        return match (true) {
            $order->canUnhold() => 'it is on hold (unhold it first)',
            $order->isPaymentReview() => 'its payment is under review',
            $order->isCanceled() => 'it is already cancelled',
            in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true) => 'it is ' . $order->getState(),
            default => 'all items are already invoiced',
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function text(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_scalar($body[$key])) {
            throw new WriteException(sprintf('"%s" must be a string', $key), 'VALIDATION', 400);
        }
        $value = trim((string) $body[$key]);
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function flag(array $body, string $key): bool
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return false;
        }
        return filter_var($body[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? throw new WriteException(sprintf('"%s" must be a boolean', $key), 'VALIDATION', 400);
    }

    private function qty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }
}
