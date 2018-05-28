<?php
declare(strict_types=1);

namespace Domain\Model\PurchaseOrder;

use Common\Aggregate;
use Common\AggregateId;
use Domain\Model\Product\ProductId;
use Domain\Model\ReceiptNote\ReceiptQuantity;
use Domain\Model\Supplier\SupplierId;
use InvalidArgumentException;
use LogicException;

final class PurchaseOrder extends Aggregate
{
    /**
     * @var PurchaseOrderId
     */
    private $purchaseOrderId;

    /**
     * @var SupplierId
     */
    private $supplierId;

    /**
     * @var Line[]
     */
    private $lines = [];

    /**
     * @var bool
     */
    private $placed = false;

    private function __construct(PurchaseOrderId $purchaseOrderId, SupplierId $supplierId)
    {
        $this->supplierId = $supplierId;
        $this->purchaseOrderId = $purchaseOrderId;
    }

    public static function create(PurchaseOrderId $purchaseOrderId, SupplierId $supplierId): PurchaseOrder
    {
        return new self($purchaseOrderId, $supplierId);
    }

    public function addLine(ProductId $productId, OrderedQuantity $quantity): void
    {
        foreach ($this->lines as $line) {
            if ($line->productId()->equals($productId)) {
                throw new InvalidArgumentException('You cannot add the same product twice.');
            }
        }

        $lineNumber = \count($this->lines) + 1;

        $this->lines[] = new Line($lineNumber, $productId, $quantity);
    }

    public function place(): void
    {
        if ($this->placed) {
            throw new LogicException('This purchase order has already been placed.');
        }

        if (\count($this->lines) < 1) {
            throw new LogicException('To place a purchase order, it has to have at least one line.');
        }

        $this->placed = true;

        $this->recordThat(new PurchaseOrderPlaced($this->purchaseOrderId));
    }

    public function id(): AggregateId
    {
        return $this->purchaseOrderId;
    }

    public function purchaseOrderId(): PurchaseOrderId
    {
        return $this->purchaseOrderId;
    }

    public function supplierId(): SupplierId
    {
        return $this->supplierId;
    }

    /**
     * @return Line[]
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function processReceipt(ProductId $productId, ReceiptQuantity $quantity): void
    {
        foreach ($this->lines as $line) {
            if ($line->productId()->equals($productId)) {
                $line->processReceipt($quantity);
            }
        }

        if ($this->isFullyDelivered()) {
            $this->recordThat(new PurchaseOrderCompleted($this->purchaseOrderId));
        }
    }

    public function undoReceipt(ProductId $productId, ReceiptQuantity $quantity): void
    {
        $wasFullyReceived = $this->isFullyDelivered();

        foreach ($this->lines as $line) {
            if ($line->productId()->equals($productId)) {
                $line->undoReceipt($quantity);
            }
        }

        if ($wasFullyReceived && !$this->isFullyDelivered()) {
            $this->recordThat(new PurchaseOrderReopened($this->purchaseOrderId));
        }
    }

    public function isFullyDelivered(): bool
    {
        foreach ($this->lines as $line) {
            if (!$line->isFullyDelivered()) {
                return false;
            }
        }

        return true;
    }

    public function lineForProduct(ProductId $productId): Line
    {
        foreach ($this->lines as $line) {
            if ($line->productId()->equals($productId)) {
                return $line;
            }
        }

        throw new \RuntimeException(sprintf(
            'Purchase order "%s" has no line for product "%s"',
            (string)$this->purchaseOrderId,
            (string)$productId
        ));
    }
}
