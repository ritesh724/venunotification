<?php
namespace Ueg\VenueNotification\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ThresholdType implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'dollar',     'label' => __('Dollar ($)')],
            ['value' => 'percentage', 'label' => __('Percentage (%)')],
        ];
    }
}