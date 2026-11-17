<?php

namespace Ueg\VenueNotification\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CheapestWholeseller extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('ueg_cheapest_wholeseller', 'id');
    }
}