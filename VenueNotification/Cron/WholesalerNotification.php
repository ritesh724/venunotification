<?php
namespace Ueg\VenueNotification\Cron;

use Ueg\VenueNotification\Helper\Data;

class WholesalerNotification
{
    /** @var Data */
    protected $data;

    public function __construct(Data $data)
    {
        $this->data = $data;
    }

    public function execute()
    {
        // 1) Reset daily mail flag (new day = new email possible)
        $this->data->resetDailyMailFlag();

        // 2) Scan venues and create/update alerts for BOTH types
        $this->data->checkCheapestDataAndStore();

        // 3) Send ONE consolidated email for all eligible alerts
        $this->data->sendConsolidatedNotificationEmail();
    }
}