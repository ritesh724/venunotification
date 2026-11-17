<?php

namespace Ueg\VenueNotification\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Ueg\ApiBase\Model\ResourceModel\Venue\CollectionFactory;
use Ueg\VenueNotification\Model\ResourceModel\CheapestWholeseller\CollectionFactory as CheapestCollectionFactory;
use Ueg\VenueNotification\Model\CheapestWholesellerFactory;

class Data extends AbstractHelper
{
    protected $venueCollectionFactory;
    protected $resourceConnection;
    protected $cheapestCollectionFactory;
    protected $cheapestWholesellerFactory;

    public function __construct(
        Context $context,
        CollectionFactory $venueCollectionFactory,
        ResourceConnection $resourceConnection,
        CheapestCollectionFactory $cheapestCollectionFactory,
        CheapestWholesellerFactory $cheapestWholesellerFactory
    ) {
        $this->venueCollectionFactory        = $venueCollectionFactory;
        $this->resourceConnection            = $resourceConnection;
        $this->cheapestCollectionFactory     = $cheapestCollectionFactory;
        $this->cheapestWholesellerFactory    = $cheapestWholesellerFactory;
        parent::__construct($context);
    }

    public function getConfigValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            $field,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getConfigValueDynamicRow()
    {
        $data = $this->scopeConfig->getValue(
            'apibase/general/dynamic_field',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        $data = json_decode((string)$data, true) ?: [];
        $newData = [];
        foreach ($data as $key) {
            if (!empty($key['venue'])) {
                $newData[$key['venue']] = $key['trader']; // 'wholeseller' | 'retailer'
            }
        }
        return $newData;
    }

    /**
     * Retailer threshold config (percentage or dollar), relation.
     */
    public function getRetailerNotificationConfig()
    {
        return [
            'enabled'    => (bool)$this->getConfigValue('venuenotification/retailer/enabled'),
            'type'       => $this->getConfigValue('venuenotification/retailer/threshold_type') ?: 'percentage', // dollar|percentage
            'value'      => (float)$this->getConfigValue('venuenotification/retailer/threshold_value'),
            'relation'   => $this->getConfigValue('venuenotification/retailer/relation') ?: 'less', // less|greater
            'dismiss_ttl_days' => (int)$this->getConfigValue('venuenotification/retailer/dismiss_days') ?: 1,
        ];
    }

    public function getWholesalerNotificationConfig()
    {
        return [
            'enabled' => (bool)$this->getConfigValue('venuenotification/wholesaler/enabled'),
        ];
    }

    /**
     * Build the effective threshold price for retailer based on type & relation.
     * Example: retailer 5.08, by 10% → target 4.57 (less) or 5.59 (greater)
     */
    public function calculateRetailerThreshold(float $retailerPrice, array $cfg): float
    {
        $adj = 0.0;
        if ($cfg['type'] === 'percentage') {
            $adj = $retailerPrice * ($cfg['value'] / 100);
        } else {
            $adj = $cfg['value'];
        }
        return ($cfg['relation'] === 'less')
            ? ($retailerPrice - $adj)
            : ($retailerPrice + $adj);
    }

    /**
     * Flatten nested venue_records structures.
     *
     * Converts: {"apmex":"9.99","aurbitrage":{"dillon-gage":"3937.36"}}
     * Into:     {"apmex":"9.99","aurbitrage-dillon-gage":"3937.36"}
     *
     * Arrays/objects deeper than one level and null values are dropped.
     */
    protected function flattenVenueRecords(array $records): array
    {
        $flat = [];
        foreach ($records as $venue => $price) {
            if (is_array($price) || is_object($price)) {
                // One level of nesting only
                foreach ((array)$price as $innerVenue => $innerPrice) {
                    if (is_array($innerPrice) || is_object($innerPrice) || $innerPrice === null) {
                        continue;
                    }
                    $innerVenue = (string)$innerVenue;
                    // Store both the hyphenated form and the bare inner key so
                    // either way of keying venueTypes will resolve.
                    $flat[$venue . '-' . $innerVenue] = $innerPrice;
                    if (!array_key_exists($innerVenue, $flat)) {
                        $flat[$innerVenue] = $innerPrice;
                    }
                }
                continue;
            }
            if ($price === null) {
                continue;
            }
            $flat[$venue] = $price;
        }
        return $flat;
    }

    /**
     * Normalize a raw price value (string|int|float) into a float.
     * Strips %, commas, and whitespace. Returns null if not numeric.
     */
    protected function normalizePrice($raw): ?float
    {
        if (is_array($raw) || is_object($raw) || $raw === null) {
            return null;
        }
        $clean = str_replace(['%', ',', ' '], '', (string)$raw);
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        return (float)$clean;
    }

    /**
     * Main scan: builds/updates alerts for BOTH retailers and wholesalers.
     */
    public function checkCheapestDataAndStore()
    {
        $venueTypes   = $this->getConfigValueDynamicRow();
        $wholeCfg     = $this->getWholesalerNotificationConfig();
        $retailCfg    = $this->getRetailerNotificationConfig();
        $venueCollection = $this->venueColectionFactory->create()
            ->addFieldToSelect(['venue_records', 'product_id']);

        foreach ($venueCollection as $row) {
            $productId    = (int)$row->getData('product_id');
            $venueRecords = json_decode((string)$row->getData('venue_records'), true);
            if (!is_array($venueRecords) || empty($venueRecords)) {
                continue;
            }

            $venueRecords = $this->flattenVenueRecords($venueRecords);
            if (empty($venueRecords)) {
                continue;
            }

            $msgPrice = (float)$this->getProductAttributeValue($productId, 'price');
            if ($msgPrice <= 0) {
                continue;
            }

            $cheapestWholesaler = null;
            $lowestWholesale    = null;
            $cheapestRetailer   = null;
            $lowestRetail       = null;

            foreach ($venueRecords as $venue => $price) {
                $priceVal = $this->normalizePrice($price);
                if ($priceVal === null) {
                    continue;
                }

                $trader = $venueTypes[$venue] ?? '';
                if ($trader === '') {
                    // Fallback: try the bare inner key (e.g. "dillon-gage")
                    // when the flattened "aurbitrage-dillon-gage" isn't configured.
                    if (strpos((string)$venue, '-') !== false) {
                        $parts  = explode('-', (string)$venue);
                        $bare   = end($parts);
                        $trader = $venueTypes[$bare] ?? '';
                    }
                }

                if ($trader === 'wholeseller') {
                    if ($lowestWholesale === null || $priceVal < $lowestWholesale) {
                        $lowestWholesale    = $priceVal;
                        $cheapestWholesaler = $venue;
                    }
                } elseif ($trader === 'retailer') {
                    if ($lowestRetail === null || $priceVal < $lowestRetail) {
                        $lowestRetail     = $priceVal;
                        $cheapestRetailer = $venue;
                    }
                }
            }

            // ---------- WHOLESALER ALERT ----------
            if ($wholeCfg['enabled'] && $cheapestWholesaler !== null) {
                if ($msgPrice < $lowestWholesale) {
                    $this->upsertAlert(
                        $productId,
                        'wholesaler',
                        $cheapestWholesaler,
                        $lowestWholesale,
                        $msgPrice,
                        null,
                        null
                    );
                } else {
                    $this->deactivateAlert($productId, 'wholesaler');
                }
            }

            // ---------- RETAILER ALERT ----------
            if ($retailCfg['enabled'] && $cheapestRetailer !== null && $retailCfg['value'] > 0) {
                $threshold = $this->calculateRetailerThreshold($lowestRetail, $retailCfg);
                $hit = ($retailCfg['relation'] === 'less')
                    ? ($msgPrice < $threshold)
                    : ($msgPrice > $threshold);

                if ($hit) {
                    $this->upsertAlert(
                        $productId,
                        'retailer',
                        $cheapestRetailer,
                        $lowestRetail,
                        $msgPrice,
                        $retailCfg['type'],
                        $retailCfg['value']
                    );
                } else {
                    $this->deactivateAlert($productId, 'retailer');
                }
            }
        }
    }

    /**
     * Insert or update an alert row.
     * Resets is_mail_send when price or threshold changed (so a new email is allowed).
     */
    protected function upsertAlert(
        int $productId,
        string $type,
        string $venue,
        float $cheapestPrice,
        float $msgPrice,
        ?string $thresholdType,
        ?float $thresholdValue
    ) {
        $model = $this->cheapestWholesellerFactory->create()
            ->getCollection()
            ->addFieldToFilter('product_id', $productId)
            ->addFieldToFilter('notify_type', $type)
            ->getFirstItem();

        $isNew = !$model->getId();
        if ($isNew) {
            $model = $this->cheapestWholesellerFactory->create();
            $model->setProductId($productId);
            $model->setNotifyType($type);
            $model->setNotifyCount(0);
            $model->setIsMailSend(0);
            $model->setCreatedAt(date('Y-m-d H:i:s'));
        }

        // If the price changed → reset mail flag so a fresh notification can go out
        $lastPrice = $model->getData('cheapest_price');
        if ($lastPrice !== null && (float)$lastPrice !== $cheapestPrice) {
            $model->setIsMailSend(0);
        }

        if ($type === 'wholesaler') {
            $model->setData('cheapest_wholesaler', $venue);
        } else {
            $model->setData('cheapest_retailer', $venue);
        }

        $model->setData('cheapest_price', $cheapestPrice);
        $model->setData('msg_price', $msgPrice);
        $model->setData('threshold_type', $thresholdType);
        $model->setData('threshold_value', $thresholdValue);
        $model->setData('is_active', 1);
        $model->setData('updated_at', date('Y-m-d H:i:s'));
        $model->save();
    }

    protected function deactivateAlert(int $productId, string $type)
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

    /**
     * Send ONE consolidated email per run for all eligible alerts.
     * Skips alerts already mailed in this cycle (is_mail_send = 1) and dismissed rows.
     */
    public function sendConsolidatedNotificationEmail()
    {
        $collection = $this->cheapestCollectionFactory->create()
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('is_mail_send', 0);

        // Skip dismissed
        $now = date('Y-m-d H:i:s');
        $collection->getSelect()->where(
            '(dismissed_until IS NULL OR dismissed_until <= ?)',
            $now
        );

        if (!$collection->getSize()) {
            return;
        }

        $lines = [];
        $ids   = [];
        foreach ($collection as $item) {
            $ids[] = $item->getId();
            $lines[] = sprintf(
                "[%s] Product #%d — %s (%s) | MSG: %.2f | Cheapest: %.2f",
                strtoupper($item->getData('notify_type')),
                $item->getData('product_id'),
                $item->getData('cheapest_wholesaler') ?: $item->getData('cheapest_retailer'),
                $item->getData('threshold_type')
                    ? $item->getData('threshold_type') . ' ' . $item->getData('threshold_value')
                    : 'direct',
                (float)$item->getData('msg_price'),
                (float)$item->getData('cheapest_price')
            );
        }

        //$to      = $this->getConfigValue('venuenotification/general/email_to') ?: 'mskilledrk2@yopmail.com';
        $to      = 'mskilledrk2@yopmail.com';
        $subject = 'Venue Price Alert — ' . count($ids) . ' product(s)';
        $body    = "The following products need attention:\n\n" . implode("\n", $lines);

        try {
            $mail = new \Zend_Mail('UTF-8');
            $mail->setBodyText($body)
                 ->setFrom('noreply@example.com')
                 ->addTo($to)
                 ->setSubject($subject);
            $mail->send();
        } catch (\Exception $e) {
            // log & bail
            return;
        }

        // Mark sent
        foreach ($ids as $id) {
            $row = $this->cheapestWholesellerFactory->create()->load($id);
            $row->setIsMailSend(1);
            $row->setMailSentAt(date('Y-m-d H:i:s'));
            $row->setLastNotifiedAt(date('Y-m-d H:i:s'));
            $row->setLastNotifiedPrice($row->getData('cheapest_price'));
            $row->setNotifyCount((int)$row->getData('notify_count') + 1);
            $row->save();
        }
    }

    /**
     * Called daily (or before each cron) to reset is_mail_send for a new day.
     * Only resets rows whose mail_sent_at is older than "today".
     */
    public function resetDailyMailFlag()
    {
        $conn = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ueg_cheapest_wholeseller');
        $conn->update(
            $table,
            ['is_mail_send' => 0],
            ['mail_sent_at IS NULL OR DATE(mail_sent_at) < CURDATE()']
        );
    }

    /**
     * Dismiss a retailer alert for N days (day = 24h, cron cycle aware).
     */
    public function dismissAlert(int $alertId, int $days, int $cronIntervalHours = 6)
    {
        $row = $this->cheapestWholesellerFactory->create()->load($alertId);
        if (!$row->getId()) {
            return false;
        }
        $seconds = ($days * 24 * 3600) - ($cronIntervalHours * 3600);
        $row->setData('dismissed_until', date('Y-m-d H:i:s', time() + max($seconds, 0)));
        $row->setIsMailSend(1);
        $row->save();
        return true;
    }

    public function getProductAttributeValue($productId, $attributeCode)
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeTable = $this->resourceConnection->getTableName('catalog_product_entity_decimal');
        $select = $connection->select()
            ->from($attributeTable, ['value'])
            ->where('entity_id = ?', $productId)
            ->where('attribute_id = ?', $this->getProductAttributeId($attributeCode))
            ->limit(1);
        return $connection->fetchOne($select);
    }

    public function getProductAttributeId($attributeCode)
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $select = $connection->select()
            ->from($attributeTable, ['attribute_id'])
            ->where('attribute_code = ?', $attributeCode)
            ->where('entity_type_id = ?', 4)
            ->limit(1);
        return $connection->fetchOne($select) ?: null;
    }
}