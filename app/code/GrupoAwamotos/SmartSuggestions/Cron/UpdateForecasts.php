<?php

declare(strict_types=1);

namespace GrupoAwamotos\SmartSuggestions\Cron;

use GrupoAwamotos\SmartSuggestions\Api\ForecastServiceInterface;
use GrupoAwamotos\SmartSuggestions\Helper\Config;
use GrupoAwamotos\SmartSuggestions\Model\ResourceModel\SalesForecast as SalesForecastResource;
use Psr\Log\LoggerInterface;

/**
 * Cron job to update sales forecasts
 *
 * Runs daily at 23:00 to update forecast data for dashboard
 * Stores historical projections for accuracy analysis
 */
class UpdateForecasts
{
    private Config $config;
    private LoggerInterface $logger;
    private ForecastServiceInterface $forecastService;
    private SalesForecastResource $forecastResource;

    public function __construct(
        Config $config,
        LoggerInterface $logger,
        ForecastServiceInterface $forecastService,
        SalesForecastResource $forecastResource
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->forecastService = $forecastService;
        $this->forecastResource = $forecastResource;
    }

    /**
     * Execute forecast update cron
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $startTime = microtime(true);

            // Update daily forecast data
            $this->updateDailyForecast();

            // Update monthly forecast data
            $this->updateMonthlyForecast();

            // Calculate forecast accuracy (for historical projections)
            $this->calculateForecastAccuracy();

            $duration = round(microtime(true) - $startTime, 2);

            $this->logger->info(sprintf(
                'SmartSuggestions: Forecast update completed in %ss',
                $duration
            ));
        } catch (\Exception $e) {
            $this->logger->error('SmartSuggestions: Forecast update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Update daily forecast data
     */
    private function updateDailyForecast(): void
    {
        try {
            // Get daily sales trend (last 30 days + projections)
            $dailyData = $this->forecastService->getDailySalesTrend(30);

            if (empty($dailyData)) {
                $this->logger->warning('SmartSuggestions: No daily sales data available');
                return;
            }

            $connection = $this->forecastResource->getConnection();
            $tableName = $this->forecastResource->getMainTable();
            $now = date('Y-m-d H:i:s');

            $records = $this->buildDailyForecastRecords($dailyData, $now);

            // Upsert records
            foreach ($records as $record) {
                $connection->insertOnDuplicate(
                    $tableName,
                    $record,
                    array_keys($record)
                );
            }

            $this->logger->info(sprintf(
                'SmartSuggestions: Updated %d daily forecast records',
                count($records)
            ));
        } catch (\Exception $e) {
            $this->logger->error('SmartSuggestions: Daily forecast update failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Adapt the existing column-oriented trend contract without recalculating forecasts.
     * Build all records before any write, so invalid payloads cannot partially update data.
     */
    private function buildDailyForecastRecords(array $dailyData, string $now): array
    {
        $dates = $dailyData['dates'] ?? null;
        $sales = $dailyData['sales'] ?? null;
        $orders = $dailyData['orders'] ?? null;
        $projections = $dailyData['forecast'] ?? [];
        foreach ([$dates, $sales, $orders, $projections] as $column) {
            if (!is_array($column) || !array_is_list($column)) {
                throw new \UnexpectedValueException('Daily forecast columns must be lists.');
            }
        }
        if (count($dates) !== count($sales) || count($dates) !== count($orders)) {
            throw new \UnexpectedValueException('Daily forecast columns have different lengths.');
        }
        $validateDate = static function ($value): string {
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                throw new \UnexpectedValueException('Daily forecast date must be an ISO date.');
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new \UnexpectedValueException('Daily forecast date is invalid.');
            }
            return $value;
        };
        $finiteNumber = static function ($value): float {
            if (!is_numeric($value) || !is_finite((float) $value)) {
                throw new \UnexpectedValueException('Daily forecast value must be finite.');
            }
            return (float) $value;
        };
        $records = [];
        $seenActualDates = [];
        foreach ($dates as $index => $date) {
            $periodDate = $validateDate($date);
            if (isset($seenActualDates[$periodDate])) {
                throw new \UnexpectedValueException('Daily forecast dates must be unique.');
            }
            $seenActualDates[$periodDate] = true;
            $total = $finiteNumber($sales[$index]);
            $count = $finiteNumber($orders[$index]);
            if ($count < 0 || floor($count) !== $count || $count > 4294967295) {
                throw new \UnexpectedValueException('Daily forecast order count is invalid.');
            }
            $ticket = $count > 0 ? $total / $count : null;
            if (abs($total) > 9999999999.9999 || ($ticket !== null && abs($ticket) > 99999999.9999)) {
                throw new \UnexpectedValueException('Daily forecast amount exceeds storage precision.');
            }
            $records[] = [
                'period_type' => 'daily',
                'period_date' => $periodDate,
                'actual_sales' => $total,
                'orders_count' => (int) $count,
                'avg_ticket' => $ticket,
                'calculated_at' => $now
            ];
        }
        $seenProjectionDates = [];
        foreach ($projections as $projection) {
            if (!is_array($projection)) {
                throw new \UnexpectedValueException('Daily forecast projection must be a record.');
            }
            $periodDate = $validateDate($projection['date'] ?? null);
            if (isset($seenProjectionDates[$periodDate])) {
                throw new \UnexpectedValueException('Daily forecast projection dates must be unique.');
            }
            $seenProjectionDates[$periodDate] = true;
            $projected = $finiteNumber($projection['projected'] ?? null);
            if (abs($projected) > 9999999999.9999) {
                throw new \UnexpectedValueException('Daily projection exceeds storage precision.');
            }
            $records[] = [
                'period_type' => 'daily',
                'period_date' => $periodDate,
                'projected_sales' => $projected,
                // The current service returns point estimates, without bounds or confidence.
                'projection_low' => null,
                'projection_high' => null,
                'confidence' => null,
                'calculated_at' => $now
            ];
        }
        return $records;
    }

    /**
     * Update monthly forecast data
     */
    private function updateMonthlyForecast(): void
    {
        try {
            // Get current month projection
            $currentProjection = $this->forecastService->projectMonthClosing();

            // Get next month projection
            $nextMonthProjection = $this->forecastService->projectNextMonth();

            $connection = $this->forecastResource->getConnection();
            $tableName = $this->forecastResource->getMainTable();
            $now = date('Y-m-d H:i:s');

            // Current month
            if (!empty($currentProjection)) {
                $currentMonth = date('Y-m-01');
                $monthlyGoal = $this->config->getMonthlyGoal();

                $connection->insertOnDuplicate(
                    $tableName,
                    [
                        'period_type' => 'monthly',
                        'period_date' => $currentMonth,
                        'actual_sales' => $currentProjection['actual_sales'] ?? null,
                        'projected_sales' => $currentProjection['projection']['realistic'] ?? null,
                        'projection_low' => $currentProjection['projection']['pessimistic'] ?? null,
                        'projection_high' => $currentProjection['projection']['optimistic'] ?? null,
                        'target' => $monthlyGoal,
                        'orders_count' => null, // Would need separate query
                        'avg_ticket' => null,
                        'trend_factor' => $currentProjection['trend']['percentage'] ?? null,
                        'confidence' => 0.85,
                        'calculated_at' => $now
                    ],
                    ['actual_sales', 'projected_sales', 'projection_low', 'projection_high', 'trend_factor', 'calculated_at']
                );
            }

            // Next month
            if (!empty($nextMonthProjection)) {
                $nextMonth = date('Y-m-01', strtotime('+1 month'));

                $connection->insertOnDuplicate(
                    $tableName,
                    [
                        'period_type' => 'monthly',
                        'period_date' => $nextMonth,
                        'projected_sales' => $nextMonthProjection['projection'] ?? null,
                        'projection_low' => $nextMonthProjection['range']['min'] ?? null,
                        'projection_high' => $nextMonthProjection['range']['max'] ?? null,
                        'seasonal_index' => $nextMonthProjection['seasonal_factor'] ?? null,
                        'trend_factor' => $nextMonthProjection['growth_factor'] ?? null,
                        'confidence' => 0.75, // Lower confidence for next month
                        'calculated_at' => $now
                    ],
                    ['projected_sales', 'projection_low', 'projection_high', 'seasonal_index', 'trend_factor', 'calculated_at']
                );
            }

            $this->logger->info('SmartSuggestions: Updated monthly forecast records');
        } catch (\Exception $e) {
            $this->logger->error('SmartSuggestions: Monthly forecast update failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calculate forecast accuracy for past projections
     */
    private function calculateForecastAccuracy(): void
    {
        try {
            $connection = $this->forecastResource->getConnection();
            $tableName = $this->forecastResource->getMainTable();

            // Find records where we have both projection and actual values
            $select = $connection->select()
                ->from($tableName)
                ->where('projected_sales IS NOT NULL')
                ->where('actual_sales IS NOT NULL')
                ->where('period_date < ?', date('Y-m-d'))
                ->order('period_date DESC')
                ->limit(60); // Last ~2 months of daily data

            $records = $connection->fetchAll($select);

            if (empty($records)) {
                return;
            }

            $totalError = 0;
            $count = 0;

            foreach ($records as $record) {
                $projected = (float) $record['projected_sales'];
                $actual = (float) $record['actual_sales'];

                if ($actual > 0 && $projected > 0) {
                    $error = abs($actual - $projected) / $actual;
                    $totalError += $error;
                    $count++;
                }
            }

            if ($count > 0) {
                $mape = ($totalError / $count) * 100; // Mean Absolute Percentage Error
                $accuracy = max(0, 100 - $mape);

                $this->logger->info(sprintf(
                    'SmartSuggestions: Forecast accuracy: %.1f%% (MAPE: %.1f%%) based on %d records',
                    $accuracy,
                    $mape,
                    $count
                ));
            }
        } catch (\Exception $e) {
            $this->logger->error('SmartSuggestions: Accuracy calculation failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
