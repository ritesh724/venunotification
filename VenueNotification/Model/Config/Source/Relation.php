<?php
namespace Ueg\VenueNotification\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Relation implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'lt', 'label' => __('Less than (<)')],
            ['value' => 'gt', 'label' => __('Greater than (>)')],
        ];
    }
}