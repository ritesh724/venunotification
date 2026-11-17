<?php

namespace Ueg\VenueNotification\Model\ResourceModel\CheapestWholeseller;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ueg\VenueNotification\Model\CheapestWholeseller as Model;
use Ueg\VenueNotification\Model\ResourceModel\CheapestWholeseller as ResourceModel;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}