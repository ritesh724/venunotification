<?php
namespace Ueg\VenueNotification\Controller\Adminhtml\Notification;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Ueg\VenueNotification\Helper\Data;

class Dismiss extends Action
{
    const ADMIN_RESOURCE = 'Magento_Backend::admin';

    /** @var JsonFactory */
    protected $resultJsonFactory;
    /** @var Data */
    protected $helper;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Data $helper
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $alertId = (int)$this->getRequest()->getParam('alert_id');
        $days    = (int)$this->getRequest()->getParam('days');

        if (!$alertId || !in_array($days, [1, 2, 3])) {
            return $result->setData(['success' => false, 'message' => 'Invalid params']);
        }

        $ok = $this->helper->dismissAlert($alertId, $days);
        return $result->setData([
            'success' => $ok,
            'message' => $ok ? __('Dismissed for %1 day(s).', $days) : __('Alert not found.')
        ]);
    }
}