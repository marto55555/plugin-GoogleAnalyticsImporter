<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\GoogleAnalyticsImporter\Google;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Date;
use Piwik\Db;
use Piwik\Metrics;
use Piwik\Site;
use Piwik\Tracker\GoalManager;
use Psr\Log\LoggerInterface;

class GoogleAnalyticsQueryService
{
    const MAX_ATTEMPTS = 30;
    const MAX_BACKOFF_TIME = 60;
    const PING_MYSQL_EVERY = 25;

    private static $problematicMetrics = [
        'ga:users',
        'ga:hits',
    ];

    /**
     * @var \Google_Service_Analytics
     */
    private $gaService;

    /**
     * @var string
     */
    private $viewId;

    /**
     * @var array
     */
    private $mapping;

    /**
     * @var array
     */
    private $goalsMapping;

    /**
     * @var int
     */
    private $idSite;

    /**
     * @var callable
     */
    private $onQueryMade;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $currentBackoffTime = 1;

    private $pingMysqlEverySecs;

    /**
     * @var GoogleQueryObjectFactory
     */
    private $googleQueryObjectFactory;

    public function __construct(\Google_Service_AnalyticsReporting $gaService, $viewId, array $goalsMapping, $idSite,
                                GoogleQueryObjectFactory $googleQueryObjectFactory, LoggerInterface $logger)
    {
        $this->gaService = $gaService;
        $this->viewId = $viewId;
        $this->goalsMapping = $goalsMapping;
        $this->idSite = $idSite;
        $this->logger = $logger;
        $this->googleQueryObjectFactory = $googleQueryObjectFactory;
        $this->pingMysqlEverySecs = StaticContainer::get('GoogleAnalyticsImporter.pingMysqlEverySecs') ?: self::PING_MYSQL_EVERY;
        $this->mapping = $this->getMetricIndicesToGaMetrics();
    }

    public function query(Date $day, array $dimensions, array $metrics, array $options = [])
    {
        $mappings = $this->mapping;
        if (!empty($options['mappings'])) {
            $mappings = $options['mappings'] + $mappings;
        }

        $result = new DataTable();

        $gaMetricsToQuery = $this->getMappedMetricsToQuery($metrics, $mappings);

        $defaultRow = new Row();
        foreach ($gaMetricsToQuery as $name) {
            $defaultRow->setColumn($name, 0);
        }

        // detect the metric used to order result sets. we need to send this metric with each partial request to ensure correct order.
        $orderByMetric = $this->googleQueryObjectFactory->getOrderByMetric($gaMetricsToQuery, $options);

        foreach (array_chunk($gaMetricsToQuery, 9) as $chunk) {
            $chunkResponse = $this->gaBatchGet($day, array_values($chunk), array_merge(['dimensions' => $dimensions], $options), $orderByMetric);

            // some metric/date combinations seem to cause GA to return absolutely nothing (no rows + NULL row count).
            // in this case we remove the problematic metrics and try again.
            if ($chunkResponse->getReports()[0]->getData()->getRowCount() === null) {
                $chunk = array_diff($chunk, self::$problematicMetrics);
                if (empty($chunk)) {
                    continue;
                }

                $chunkResponse = $this->gaBatchGet($day, $chunk, array_merge(['dimensions' => $dimensions], $options), $orderByMetric);

                // the second request can still fail, in which case repeated requests tend to still fail. so we ignore this data. seems to only
                // happen for old data anyway.
                if ($chunkResponse->getReports()[0]->getData()->getRowCount() === null) {
                    continue;
                }
            }

            if ($this->onQueryMade) {
                $callable = $this->onQueryMade;
                $callable();
            }

            usleep(100 * 1000);

            $this->mergeResult($result, $chunkResponse, $dimensions, $chunk, $defaultRow);
        }

        $this->convertGaColumnsToMetricIndexes($result, $metrics, $mappings);

        return $result;
    }

    private function convertGaColumnsToMetricIndexes(DataTable $result, $metrics, $mappings)
    {
        $metricNames = [];
        foreach ($metrics as $metricIndex) {
            if (is_array($mappings[$metricIndex])) {
                $metricInfo = $mappings[$metricIndex];
                if (is_array($metricInfo['metric'])) {
                    $metricNames[$metricIndex] = $metricInfo['metric'][0];
                } else {
                    $metricNames[$metricIndex] = $metricInfo['metric'];
                }
            } else {
                $metricNames[$metricIndex] = $mappings[$metricIndex];
            }
        }

        foreach ($result->getRows() as $row) {
            $newColumns = ['label' => $row->getColumn('label')];
            foreach ($metrics as $metricIndex) {
                $gaMetricName = $metricNames[$metricIndex];
                $value = $row->getColumn($gaMetricName);

                if ($value !== false
                    && isset($mappings[$metricIndex]['calculate'])
                ) {
                    $fn = $mappings[$metricIndex]['calculate'];
                    $value = $fn($row);
                }

                if ($value !== false) {
                    $newColumns[$metricIndex] = $value;
                }
            }
            $row->setColumns($newColumns);
        }
        $result->setLabelsHaveChanged();
    }

    private function getMappedMetricsToQuery($metrics, $mappings)
    {
        $mappedMetricsInfo = [];
        foreach ($metrics as $index) {
            if (!isset($mappings[$index])) {
                throw new \Exception("Don't know how to map metric index ${index} to GA metric.");
            }

            $gaMetric = $mappings[$index];

            $metric = $gaMetric;
            if (isset($gaMetric['metric'])) {
                $metric = $gaMetric['metric'];
            }

            if (!is_array($metric)) {
                $metric = [$metric];
            }

            $mappedMetricsInfo[$index] = $metric;
        }

        $gaMetricsToQuery = [];
        foreach ($mappedMetricsInfo as $metricsList) {
            foreach ($metricsList as $gaMetric) {
                $gaMetricsToQuery[] = $gaMetric;
            }
        }
        $gaMetricsToQuery = array_unique($gaMetricsToQuery);
        return $gaMetricsToQuery;
    }

    private function mergeResult(DataTable $table, \Google_Service_AnalyticsReporting_GetReportsResponse $response, $gaDimensions, $metricsQueried, Row $defaultRow)
    {
        /** @var \Google_Service_AnalyticsReporting_Report $gaReport */
        foreach ($response->getReports() as $gaReport) {
            /** @var \Google_Service_AnalyticsReporting_ReportRow $gaRow */
            foreach ($gaReport->getData()->getRows() as $gaRow) {
                $tableRow = clone $defaultRow;

                // convert GA row which is just array of values w/ integer indexes to matomo row
                // mapping GA metric names => values
                $gaRowMetrics = $gaRow->getMetrics()[0]->getValues();
                foreach (array_values($metricsQueried) as $index => $metricName) {
                    $tableRow->setColumn($metricName, $gaRowMetrics[$index]);
                }

                // gather all dimensions to create the label column (we need to be able to find existing rows from dimensions
                // so we combine these dimensions into a single label)
                $label = [];
                foreach (array_values($gaDimensions) as $index => $dimension) {
                    $labelValue = $gaRow->dimensions[$index] == '(not set)' ? null : $gaRow->dimensions[$index];
                    $tableRow->setMetadata($dimension, $labelValue);

                    $label[$dimension] = $labelValue;
                }

                if (!empty($label)) {
                    $label = implode(',', $label); // so we can call getRowFromLabel()
                    $tableRow->setColumn('label', $label);
                }

                $existingRow = empty($gaDimensions) ? $table->getFirstRow() : $table->getRowFromLabel($label);
                if (!empty($existingRow)) {
                    $existingRow->sumRow($tableRow);
                } else {
                    $table->addRow($tableRow);
                }
            }
        }
    }

    public function getMetricIndicesToGaMetrics()
    {
        $goalSpecificMetrics = [];
        foreach ($this->goalsMapping as $idGoal => $gaIdGoal) {
            $goalSpecificMetrics = array_merge($goalSpecificMetrics, array_values($this->getGoalSpecificMetricIndicesToGametrics($gaIdGoal)));
        }
        if (Site::isEcommerceEnabledFor($this->idSite)) {
            $goalSpecificMetrics = array_merge($goalSpecificMetrics, array_values($this->getEcommerceGoalSpecificMetrics()));
        }
        $goalSpecificMetrics[] = 'ga:sessions'; // for nb_visits_converted

        return [
            // visit metrics
            Metrics::INDEX_NB_UNIQ_VISITORS => 'ga:users',
            Metrics::INDEX_NB_VISITS => 'ga:sessions',
            Metrics::INDEX_NB_ACTIONS => 'ga:hits',
            Metrics::INDEX_SUM_VISIT_LENGTH => [
                'metric' => 'ga:sessionDuration',
                'calculate' => function (Row $row) {
                    return floor($row->getColumn('ga:sessionDuration'));
                },
            ],
            Metrics::INDEX_BOUNCE_COUNT => 'ga:bounces',

            // TODO: goalConversionRateAll doesn't seem to include ecommerce orders. not sure how to make it accurate in this case...
            Metrics::INDEX_NB_VISITS_CONVERTED => [
                'metric' => ['ga:goalConversionRateAll', 'ga:sessions'],
                'calculate' => function (Row $row) {
                    return self::calculateConvertedVisits($row, 'ga:goalConversionRateAll');
                },
            ],

            // conversion aware
            Metrics::INDEX_NB_CONVERSIONS => [
                'metric' => ['ga:goalCompletionsAll', 'ga:transactions'],
                'calculate' => function (Row $row) {
                    return $row->getColumn('ga:goalCompletionsAll') + $row->getColumn('ga:transactions');
                },
            ],
            Metrics::INDEX_REVENUE => 'ga:totalValue',

            // goal specific
            Metrics::INDEX_GOALS => [
                'metric' => $goalSpecificMetrics,
                'calculate' => function ($metrics) {
                    return $this->createGoalSpecificMetricArray($metrics);
                },
            ],

            // actions
            Metrics::INDEX_PAGE_NB_HITS => 'ga:pageviews',
            Metrics::INDEX_PAGE_SUM_TIME_SPENT => [
                'metric' => 'ga:timeOnPage',
                'calculate' => function (Row $row) {
                    return round($row->getColumn('ga:timeOnPage'));
                },
            ],

            // events
            Metrics::INDEX_EVENT_NB_HITS => 'ga:totalEvents',
            Metrics::INDEX_EVENT_SUM_EVENT_VALUE => 'ga:eventValue',

            // actions (requires correct dimension)
            Metrics::INDEX_PAGE_EXIT_NB_UNIQ_VISITORS => 'ga:users',
            Metrics::INDEX_PAGE_EXIT_NB_VISITS => 'ga:exits',

            // actions (requires correct dimension)
            Metrics::INDEX_PAGE_ENTRY_NB_UNIQ_VISITORS => 'ga:users',
            Metrics::INDEX_PAGE_ENTRY_NB_VISITS => 'ga:entrances',
            Metrics::INDEX_PAGE_ENTRY_NB_ACTIONS => 'ga:hits',
            Metrics::INDEX_PAGE_ENTRY_SUM_VISIT_LENGTH => [
                'metric' => 'ga:sessionDuration',
                'calculate' => function (Row $row) {
                    return floor($row->getColumn('ga:sessionDuration'));
                },
            ],
            Metrics::INDEX_PAGE_ENTRY_BOUNCE_COUNT => 'ga:bounces',

            // actions (requires correct dimensions)
            Metrics::INDEX_PAGE_IS_FOLLOWING_SITE_SEARCH_NB_HITS => 'ga:hits',

            Metrics::INDEX_PAGE_SUM_TIME_GENERATION => 'ga:pageDownloadTime',
            Metrics::INDEX_PAGE_NB_HITS_WITH_TIME_GENERATION => 'ga:pageLoadSample',

            // ecommerce item metrics (requires correct dimensions)
            Metrics::INDEX_ECOMMERCE_ITEM_REVENUE => 'ga:itemRevenue',
            Metrics::INDEX_ECOMMERCE_ITEM_QUANTITY => 'ga:itemQuantity',
            Metrics::INDEX_ECOMMERCE_ITEM_PRICE => 'ga:revenuePerItem',
            Metrics::INDEX_ECOMMERCE_ORDERS => 'ga:uniquePurchases',
        ];
    }

    private function createGoalSpecificMetricArray(Row $row)
    {
        $result = [];
        foreach ($this->goalsMapping as $idGoal => $gaIdGoal) {
            $goalSpecificMetrics = $this->getGoalSpecificMetricIndicesToGametrics($gaIdGoal);

            $innerColumns = [];
            foreach ($goalSpecificMetrics as $index => $gaName) {
                if ($index == Metrics::INDEX_GOAL_NB_VISITS_CONVERTED
                    && $row->getColumn($gaName) !== false
                ) {
                    $value = self::calculateConvertedVisits($row, $gaName);
                } else {
                    $value = $row->getColumn($gaName);
                }

                if ($value !== false) {
                    $innerColumns[$index] = $value;
                }
            }
            $result[$idGoal] = $innerColumns;
        }

        if (Site::isEcommerceEnabledFor($this->idSite)) {
            $goalSpecificMetrics = $this->getEcommerceGoalSpecificMetrics();

            $innerColumns = [];
            foreach ($goalSpecificMetrics as $index => $gaName) {
                $value = $row->getColumn($gaName);
                if ($value !== false) {
                    $innerColumns[$index] = $value;
                }
            }

            $result[GoalManager::IDGOAL_ORDER] = $innerColumns;
        }

        return $result;
    }

    public function getEcommerceMetricIndicesToGaMetrics()
    {
        return [
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_SUBTOTAL => 'ga:transactionRevenue',
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_TAX => 'ga:transactionTax',
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_SHIPPING => 'ga:transactionShipping',
            Metrics::INDEX_GOAL_ECOMMERCE_ITEMS => 'ga:itemQuantity',
        ];
    }

    public function getGoalSpecificMetricIndicesToGametrics($gaIdGoal)
    {
        return [
            Metrics::INDEX_GOAL_NB_CONVERSIONS => "ga:goal{$gaIdGoal}Completions",
            Metrics::INDEX_GOAL_REVENUE => "ga:goal{$gaIdGoal}Value",

            // nb_visits_converted is calculated properly in createGoalSpecificMetricArray
            Metrics::INDEX_GOAL_NB_VISITS_CONVERTED => "ga:goal{$gaIdGoal}ConversionRate",
        ];
    }

    public function getEcommerceGoalSpecificMetrics()
    {
        return [
            Metrics::INDEX_GOAL_NB_CONVERSIONS => 'ga:transactions',
            Metrics::INDEX_GOAL_REVENUE => 'ga:transactionRevenue',
            Metrics::INDEX_GOAL_ECOMMERCE_ITEMS => 'ga:itemQuantity',
        ];
    }

    private function gaBatchGet(Date $date, $metricNamesChunk, $options, $orderByMetric)
    {
        if (!in_array($orderByMetric, $metricNamesChunk)) {
            $metricNamesChunk[] = $orderByMetric; // make sure the order by metric is included in this query so we can sort
        }

        if (!isset($options['orderBys'])) {
            $options['orderBys'][] = [
                'field' => $orderByMetric,
                'order' => 'descending',
            ];
        }

        $request = $this->googleQueryObjectFactory->make($this->viewId, $date, $metricNamesChunk, $options);

        $this->currentBackoffTime = 1;

        $attempts = 0;
        while ($attempts < self::MAX_ATTEMPTS) {
            try {
                $this->issuePointlessMysqlQuery();

                $result = $this->gaService->reports->batchGet($request);
                if (empty($result)) {
                    ++$attempts;
                    sleep(1);

                    $this->logger->info("Google Analytics API returned null for some reason, trying again...");

                    continue;
                }

                return $result;
            } catch (\Exception $ex) {
                $this->logger->debug("Google Analytics returned an error: {message}", [
                    'message' => $ex->getMessage(),
                ]);

                if ($ex->getCode() == 403 || $ex->getCode() == 429) {
                    if (strpos($ex->getMessage(), 'daily') !== false) {
                        throw new DailyRateLimitReached();
                    }

                    ++$attempts;

                    $this->logger->debug("Waiting {$this->currentBackoffTime}s before trying again...");
                    $this->sleep($this->currentBackoffTime);

                    $this->currentBackoffTime = min(self::MAX_BACKOFF_TIME, $this->currentBackoffTime * 2);
                } else if ($ex->getCode() >= 500) {
                    ++$attempts;
                    $this->logger->info("Google Analytics API returned error: {$ex->getMessage()}. Waiting one minute before trying again...");
                    $this->sleep(60);
                } else {
                    throw $ex;
                }
            }
        }

        throw new \Exception("Failed to reach GA after " . self::MAX_ATTEMPTS . " attempts. Restart the import later.");
    }

    private static function getQuotientFromPercentage($percentage)
    {
        if ($percentage === false) {
            return 0;
        }

        $quotient = trim($percentage);
        $quotient = rtrim($quotient, '%');
        $quotient = (float) $quotient;
        $quotient = $quotient / 100;
        return $quotient;
    }

    /**
     * @param callable $onQueryMade
     */
    public function setOnQueryMade($onQueryMade)
    {
        $this->onQueryMade = $onQueryMade;
    }

    private static function calculateConvertedVisits(Row $row, $gaName)
    {
        return floor(self::getQuotientFromPercentage($row->getColumn($gaName)) * $row->getColumn('ga:sessions'));
    }

    /**
     * Used to keep the mysql connection alive in case GA API makes us wait for too long.
     */
    private function issuePointlessMysqlQuery()
    {
        Db::fetchOne("SELECT COUNT(*) FROM `" . Common::prefixTable('option') . "`");
    }

    private function sleep($time)
    {
        $amountSlept = 0;
        while ($amountSlept < $time) {
            $timeToSleep = min($this->pingMysqlEverySecs, $time - $amountSlept);

            sleep($timeToSleep);
            $amountSlept += $timeToSleep;

            $this->issuePointlessMysqlQuery();
        }
    }
}