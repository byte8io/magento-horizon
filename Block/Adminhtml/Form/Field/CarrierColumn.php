<?php

declare(strict_types=1);

namespace Byte8\Horizon\Block\Adminhtml\Form\Field;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;
use Magento\Shipping\Model\Config as ShippingConfig;

/**
 * Carrier dropdown for the Transit Times rows (all installed carriers, so a
 * merchant can prepare a window before switching a carrier on).
 */
class CarrierColumn extends Select
{
    public function __construct(
        Context $context,
        private readonly ShippingConfig $shippingConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            foreach ($this->shippingConfig->getAllCarriers() as $code => $carrier) {
                $title = (string) ($this->scopeConfig->getValue(sprintf('carriers/%s/title', $code)) ?: $code);
                $this->addOption((string) $code, sprintf('%s (%s)', $title, $code));
            }
        }
        return parent::_toHtml();
    }
}
