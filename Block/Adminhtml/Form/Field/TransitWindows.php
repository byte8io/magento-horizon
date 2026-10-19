<?php

declare(strict_types=1);

namespace Byte8\Horizon\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Dynamic rows: carrier → optional method code → min/max transit days.
 */
class TransitWindows extends AbstractFieldArray
{
    private ?CarrierColumn $carrierRenderer = null;

    protected function _prepareToRender(): void
    {
        $this->addColumn('carrier', [
            'label' => __('Carrier'),
            'renderer' => $this->getCarrierRenderer(),
        ]);
        $this->addColumn('method', [
            'label' => __('Method code (blank = all methods)'),
            'class' => 'validate-alphanum-with-spaces',
        ]);
        $this->addColumn('min_days', [
            'label' => __('Min days'),
            'class' => 'required-entry validate-digits',
            'size' => 3,
        ]);
        $this->addColumn('max_days', [
            'label' => __('Max days'),
            'class' => 'required-entry validate-digits',
            'size' => 3,
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add transit time');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $carrier = $row->getData('carrier');
        if ($carrier !== null && $carrier !== '') {
            $options['option_' . $this->getCarrierRenderer()->calcOptionHash($carrier)] = 'selected="selected"';
        }
        $row->setData('option_extra_attrs', $options);
    }

    /**
     * @throws LocalizedException
     */
    private function getCarrierRenderer(): CarrierColumn
    {
        if ($this->carrierRenderer === null) {
            $this->carrierRenderer = $this->getLayout()->createBlock(
                CarrierColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->carrierRenderer;
    }
}
