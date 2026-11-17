<?php
namespace Ueg\VenueNotification\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Ueg\ApiBase\Model\ResourceModel\Venue\CollectionFactory as VenueCollectionFactory;
use Ueg\ApiBase\Helper\Data as ApiBaseHelper;
use Ueg\VenueNotification\Model\CheapestWholesellerFactory;
use Ueg\VenueNotification\Model\ResourceModel\CheapestWholeseller\CollectionFactory as CheapestCollectionFactory;

class Data extends AbstractHelper
{
    const XML_PATH_ENABLED_WS       = 'venuenotification/wholesaler/enabled';
    const XML_PATH_ENABLED_RT       = 'venuenotification/retailer/enabled';
    const XML_PATH_RT_RELATION      = 'venuenotification/retailer/relation';
    const XML_PATH_RT_THRESH_TYPE   = 'venuenotification/retailer/threshold_type';
    const XML_PATH_RT_THRESH_VALUE  = 'venuenotification/retailer/threshold_value';
    const XML_PATH_CRON_INTERVAL    = 'venuenotification/general/cron_interval_hours';
    const XML_PATH_EMAIL_TO         = 'venuenotification/general/email_to';
    const XML_PATH_EMAIL_SENDER     = 'venuenotification/general/email_sender';
    const XML_PATH_EMAIL_TEMPLATE   = 'venuenotification/general/email_template';

    protected $venueCollectionFactory;
    protected $cheapestWholesellerFactory;
    protected $cheapestCollectionFactory;
    protected $resourceConnection;
    protected $apiBaseHelper;
    protected $venueRoleMap = null;

    public function __construct(
        Context $context,
        VenueCollectionFactory $venueCollectionFactory,
        CheapestWholesellerFactory $cheapestWholesellerFactory,
        CheapestCollectionFactory $cheapestCollectionFactory,
        ResourceConnection $resourceConnection,
        ApiBaseHelper $apiBaseHelper
    ) {
        $this->venueCollectionFactory = $venueCollectionFactory;
        $this->cheapestWholesellerFactory = $cheapestWholesellerFactory;
        $this->cheapestCollectionFactory = $cheapestCollectionFactory;
        $this->resourceConnection = $resourceConnection;
        $this->apiBaseHelper = $apiBaseHelper;
        parent::__construct($context);
    }

    /* ============ CONFIG ============ */

    public function getConfigValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue($field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isWholesalerEnabled()
    {
        return (bool)$this->getConfigValue(self::XML_PATH_ENABLED_WS);
    }

    public function isRetailerEnabled()
    {
        return (bool)$this->getConfigValue(self::XML_PATH_ENABLED_RT);
    }

    public function getCronIntervalHours()
    {
        $h = (int)$this->getConfigValue(self::XML_PATH_CRON_INTERVAL);
        return $h > 0 ? $h : 6;
    }

    /** Relation: 'lt' (<) or 'gt' (>) */
    public function getRetailerRelation()
    {
        return $this->getConfigValue(self::XML_PATH_RT_RELATION) ?: 'lt';
    }

    /** Threshold type: 'dollar' | 'percentage' — chosen by ADMIN */
    public function getRetailerThresholdType()
    {
        return $this->getConfigValue(self::XML_PATH_RT_THRESH_TYPE) ?: 'dollar';
    }

    /** Threshold value e.g. 10 */
    public function getRetailerThresholdValue()
    {
        return (float)$this->getConfigValue(self::XML_PATH_RT_THRESH_VALUE);
    }

    public function getVenueRoleMap()
    {
        if ($this->venueRoleMap !== null) {
            return $this->venueRoleMap;
        }
        $json = $this->getConfigValue('apibase/general/dynamic_field');
        $map = [];
        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (!empty($row['venue'])) {
                        $map[$row['venue']] = strtolower(trim($row['trader'] ?? ''));
                    }
                }
            }
        }
        return $this->venueRoleMap = $map;
    }

    public function getConfigValueDynamicRow()
    {
        return $this->getVenueRoleMap();
    }

    /* ============ MAIN SCAN ============ */

    public function checkCheapestDataAndStore()
    {
        $roleMap = $this->getVenueRoleMap();
        $wsEnabled = $this->isWholesalerEnabled();
        $rtEnabled = $this->isRetailerEnabled();

        if (!$wsEnabled && !$rtEnabled) {
            return;
        }

        $collection = $this->venueCollectionFactory->create()
            ->addFieldToSelect(['product_id', 'venue_records']);

        foreach ($collection as $venueRow) {
            $productId = (int)$venueRow->getData('product_id');
            if (!$productId) {
                continue;
            }

            $records = json_decode($venueRow->getData('venue_records'), true);
            if (!is_array($records) || empty($records)) {
                continue;
            }

            $msgPrice = $this->getProductPrice($productId);
            if ($msgPrice === null) {
                continue;
            }

            $cheapestWholesaler = null;
            $cheapestWholesalerPrice = null;
            $cheapestRetailer = null;
            $cheapestRetailerActualPrice = null;

            foreach ($records as $venue => $priceRaw) {
                $role = $roleMap[$venue] ?? '';
                $parsedPremium = $this->parsePrice($priceRaw);
                if ($parsedPremium === null) {
                    continue;
                }

                if ($role === 'wholeseller' || $role === 'wholesaler') {
                    if ($cheapestWholesalerPrice === null || $parsedPremium < $cheapestWholesalerPrice) {
                        $cheapestWholesalerPrice = $parsedPremium;
                        $cheapestWholesaler = $venue;
                    }
                } elseif ($role === 'retailer') {
                    // Convert raw premium to actual retail price (spot * weight + premium)
                    $actualPrice = $this->getActualRetailerPrice($productId, $venue, $priceRaw);
                    if ($actualPrice === null) {
                        continue;
                    }
                    if ($cheapestRetailerActualPrice === null || $actualPrice < $cheapestRetailerActualPrice) {
                        $cheapestRetailerActualPrice = $actualPrice;
                        $cheapestRetailer = $venue;
                    }
                }
            }

            /* ---------- WHOLESALER ---------- */
            if ($wsEnabled && $cheapestWholesaler !== null && $msgPrice < $cheapestWholesalerPrice) {
                $this->upsertAlert(
                    $productId, 'wholesaler',
                    $cheapestWholesaler, null,
                    $cheapestWholesalerPrice, $msgPrice,
                    null, null
                );
            } else {
                $this->deactivateAlert($productId, 'wholesaler');
            }

            /* ---------- RETAILER ---------- */
            if ($rtEnabled && $cheapestRetailer !== null && $cheapestRetailerActualPrice !== null) {
                $threshold = $this->calculateRetailerThreshold($cheapestRetailerActualPrice);

                if ($threshold === null) {
                    $this->deactivateAlert($productId, 'retailer');
                    continue;
                }

                $relation = $this->getRetailerRelation();
                $trigger = ($relation === 'lt')
                    ? ($msgPrice < $threshold)
                    : ($msgPrice > $threshold);

                if ($trigger) {
                    $this->upsertAlert(
                        $productId, 'retailer',
                        null, $cheapestRetailer,
                        $cheapestRetailerActualPrice, $msgPrice,
                        $this->getRetailerThresholdType(),   // ← admin ka choice
                        $this->getRetailerThresholdValue()
                    );
                } else {
                    $this->deactivateAlert($productId, 'retailer');
                }
            } else {
                $this->deactivateAlert($productId, 'retailer');
            }
        }
    }

    /**
     * Retailer ka ACTUAL price ($) nikaalo.
     * Har venue ke premium ko product ke calc type ($ ya %) ke hisaab se
     * dollar me convert karke spot * metalWeight + dollarPremium = actualPrice
     */
    protected function getActualRetailerPrice($productId, $venue, $rawPremium)
    {
        $sign = $this->apiBaseHelper->findSign($productId, $venue);

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productFactory = $objectManager->get(\Magento\Catalog\Model\ProductFactory::class);
        $product = $productFactory->create()->load($productId);

        if (!$product->getId()) {
            return null;
        }

        $spotPrice = (float)$this->apiBaseHelper->getSpotPriceByProduct($productId);

        $metalWeight = 1.0;
        $watchlistMetal = $product->getData('watchlist_add_metal');
        if ($watchlistMetal) {
            $decoded = is_string($watchlistMetal) ? json_decode($watchlistMetal, true) : $watchlistMetal;
            if (is_array($decoded) && isset($decoded[$venue])) {
                $metalWeight = (float)$decoded[$venue];
            }
        }

        $premiumStr = trim((string)$rawPremium);
        $premiumVal = (float)preg_replace('/[^0-9.\-]/', '', $premiumStr);

        $dollarPremium = $premiumVal;
        if ($sign === '%' || str_contains($premiumStr, '%')) {
            $converted = $this->apiBaseHelper->convertToDollar(
                $premiumVal . '-%',
                $productId,
                $venue
            );
            $dollarPremium = (float)$converted;
        }

        return round(($spotPrice * $metalWeight) + $dollarPremium, 2);
    }

    /**
     * ★★★ Ulises ka requirement:
     * Admin config se relation (</>) aur threshold_type ($/%) dono dynamic.
     * threshold = cheapestRetailerPrice ± value   (dollar mode)
     * threshold = cheapestRetailerPrice * (1 ± value/100)  (percentage mode)
     */
    protected function calculateRetailerThreshold($cheapestRetailerPrice)
    {
        $type  = $this->getRetailerThresholdType();   // 'dollar' | 'percentage'
        $value = $this->getRetailerThresholdValue();
        $relation = $this->getRetailerRelation();     // 'lt' | 'gt'

        if ($value <= 0) {
            return null;
        }

        if ($type === 'percentage') {
            $factor = $value / 100;
            return ($relation === 'lt')
                ? $cheapestRetailerPrice * (1 - $factor)
                : $cheapestRetailerPrice * (1 + $factor);
        }

        // dollar
        return ($relation === 'lt')
            ? $cheapestRetailerPrice - $value
            : $cheapestRetailerPrice + $value;
    }

    /* ============ UPSERT / DEACTIVATE ============ */

    protected function upsertAlert(
        $productId, $type, $wholesaler, $retailer,
        $cheapestPrice, $msgPrice, $thresholdType, $thresholdValue
    ) {
        $model = $this->cheapestWholesellerFactory->create()
            ->getCollection()
            ->addFieldToFilter('product_id', $productId)
            ->addFieldToFilter('notify_type', $type)
            ->getFirstItem();

        if (!$model->getId()) {
            $model = $this->cheapestWholesellerFactory->create();
            $model->setData([
                'product_id'   => $productId,
                'notify_type'  => $type,
                'is_mail_send' => 0,
                'notify_count' => 0,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        $prevMsgPrice = (float)$model->getData('msg_price');
        $priceChanged = (abs($prevMsgPrice - (float)$msgPrice) > 0.0001);
        if ($priceChanged) {
            $model->setData('is_mail_send', 0);
            $model->setData('notify_count', 0);
        }

        $model->setData('cheapest_wholesaler', $wholesaler);
        $model->setData('cheapest_retailer', $retailer);
        $model->setData('cheapest_price', $cheapestPrice);
        $model->setData('msg_price', $msgPrice);
        $model->setData('threshold_type', $thresholdType);
        $model->setData('threshold_value', $thresholdValue);
        $model->setData('is_active', 1);
        $model->setData('updated_at', date('Y-m-d H:i:s'));
        $model->save();
    }

    protected function deactivateAlert($productId, $type)
    {
        $model = $this->cheapestWholesellerFactory->create()
            ->getCollection()
            ->addFieldToFilter('product_id', $productId)
            ->addFieldToFilter('notify_type', $type)
            ->getFirstItem();

        if ($model->getId() && (int)$model->getData('is_active') === 1) {
            $model->setData('is_active', 0);
            $model->setData('updated_at', date('Y-m-d H:i:s'));
            $model->save();
        }
    }

    /* ============ DISMISS ============ */

    public function dismissAlert($alertId, $days)
    {
        $days = max(1, min(3, (int)$days));
        $intervalHours = $this->getCronIntervalHours();
        $suppressHours = ($days * 24) - $intervalHours;
        if ($suppressHours < 1) {
            $suppressHours = $days * 24;
        }

        $model = $this->cheapestWholesellerFactory->create()->load($alertId);
        if (!$model->getId()) {
            return false;
        }

        $model->setData('dismissed_until', date('Y-m-d H:i:s', strtotime("+{$suppressHours} hours")));
        $model->setData('updated_at', date('Y-m-d H:i:s'));
        $model->save();
        return true;
    }

    /* ============ EMAIL ============ */

    public function sendConsolidatedNotificationEmail()
    {
        $now = date('Y-m-d H:i:s');

        $collection = $this->cheapestCollectionFactory->create()
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('is_mail_send', 0)
            ->addFieldToFilter(
                ['dismissed_until', 'dismissed_until'],
                [['null' => true], ['lteq' => $now]]
            );

        if (!$collection->getSize()) {
            return;
        }

        $rows = [];
        $ids  = [];
        foreach ($collection as $item) {
            $ids[] = $item->getId();
            $rows[] = [
                'product_id' => $item->getData('product_id'),
                'type'       => $item->getData('notify_type'),
                'venue'      => $item->getData('notify_type') === 'wholesaler'
                                ? $item->getData('cheapest_wholesaler')
                                : $item->getData('cheapest_retailer'),
                'cheapest'   => (float)$item->getData('cheapest_price'),
                'msg_price'  => (float)$item->getData('msg_price'),
                'threshold'  => $item->getData('threshold_type')
                                ? $item->getData('threshold_type') . ': ' . $item->getData('threshold_value')
                                : '-',
            ];
        }

        $this->sendEmail($rows, count($rows));

        foreach ($ids as $id) {
            $model = $this->cheapestWholesellerFactory->create()->load($id);
            if ($model->getId()) {
                $model->setData('is_mail_send', 1);
                $model->setData('mail_sent_at', $now);
                $model->setData('last_notified_at', $now);
                $model->setData('last_notified_price', $model->getData('msg_price'));
                $model->setData('notify_count', (int)$model->getData('notify_count') + 1);
                $model->save();
            }
        }
    }

    public function resetDailyMailFlag()
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ueg_cheapest_wholeseller');
        $today = date('Y-m-d');

        $connection->update(
            $table,
            ['is_mail_send' => 0],
            [
                'is_mail_send = ?' => 1,
                'mail_sent_at IS NOT NULL',
                'DATE(mail_sent_at) < ?' => $today
            ]
        );
    }

    protected function sendEmail(array $rows, $count)
    {
        $to = $this->getConfigValue(self::XML_PATH_EMAIL_TO);
        if (!$to) {
            return;
        }

        $sender = $this->getConfigValue(self::XML_PATH_EMAIL_SENDER) ?: 'general';
        $templateId = $this->getConfigValue(self::XML_PATH_EMAIL_TEMPLATE)
            ?: 'venuenotification_general_email_template';

        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $transportBuilder = $objectManager->get(\Magento\Framework\Mail\Template\TransportBuilder::class);
            $storeManager = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
            $storeId = $storeManager->getStore()->getId();

            $transport = $transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions([
                    'area'  => \Magento\Framework\App\Area::AREA_FRONTEND,
                    'store' => $storeId
                ])
                ->setTemplateVars([
                    'alert_count' => $count,
                    'alerts'      => $rows,
                ])
                ->setFrom($sender)
                ->addTo(array_map('trim', explode(',', $to)))
                ->getTransport();

            $transport->sendMessage();
        } catch (\Exception $e) {
            $this->_logger->error('VenueNotification mail error: ' . $e->getMessage());
        }
    }

    /* ============ HELPERS ============ */

    protected function parsePrice($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim(str_replace(['$', ',', '%'], '', (string)$value));
        return is_numeric($value) ? (float)$value : null;
    }

    public function getProductPrice($productId)
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity_decimal');
        $attrId = $this->getProductAttributeId('price');

        if (!$attrId) {
            return null;
        }

        $select = $connection->select()
            ->from($table, ['value'])
            ->where('entity_id = ?', $productId)
            ->where('attribute_id = ?', $attrId)
            ->limit(1);

        $value = $connection->fetchOne($select);
        return ($value === false || $value === null) ? null : (float)$value;
    }

    public function getProductAttributeId($attributeCode)
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('eav_attribute');

        $select = $connection->select()
            ->from($table, ['attribute_id'])
            ->where('attribute_code = ?', $attributeCode)
            ->where('entity_type_id = ?', 4)
            ->limit(1);

        return $connection->fetchOne($select) ?: null;
    }
}