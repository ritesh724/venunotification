<?php
namespace Ueg\VenueNotification\Model;

use Magento\Framework\Model\AbstractModel;

class CheapestWholeseller extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Ueg\VenueNotification\Model\ResourceModel\CheapestWholeseller::class);
    }

    public function loadByProductId($productId)
    {
        return $this->load($productId, 'product_id');
    }

    public function loadByProductIdAndType($productId, $type)
    {
        $collection = $this->getCollection()
            ->addFieldToFilter('product_id', $productId)
            ->addFieldToFilter('notify_type', $type)
            ->setPageSize(1);
        $item = $collection->getFirstItem();
        return $item->getId() ? $this->load($item->getId()) : $this;
    }
}