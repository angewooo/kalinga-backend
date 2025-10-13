<?php

namespace App\Services;

use App\Models\ForecastResult;
use App\Models\HistoricalDemand;
use App\Models\AiModel;
use App\Models\PredictionAccuracy;
use App\Models\AutomatedAction;
use App\Models\Hospital;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Carbon\Carbon;

class ForecastingService
{
    /**
     * Generate demand forecast for a hospital
     * 
     * @param int $hospitalId
     * @param string $resourceType
     * @param int $forecastPeriodDays
     * @param int $aiModelId
     * @return ForecastResult
     */
    public function generateForecast(int $hospitalId, string $resourceType, int $forecastPeriodDays = 7, int $aiModelId = 1)
    {
        DB::beginTransaction();
        
        try {
            $hospital = Hospital::findOrFail($hospitalId);
            $aiModel = AiModel::findOrFail($aiModelId);

            // Get historical data for analysis
            $historicalData = HistoricalDemand::where('hospital_id', $hospitalId)
                ->where('resource_type', $resourceType)
                ->where('date', '>=', now()->subDays(90))
                ->orderBy('date', 'asc')
                ->get();

            if ($historicalData->count() < 7) {
                throw new Exception("Insufficient historical data for forecasting (minimum 7 days required)");
            }

            // Calculate forecast using moving average
            $prediction = $this->calculateMovingAverage($historicalData, $forecastPeriodDays);

            // Apply seasonal adjustments
            $adjustedPrediction = $this->applySeasonalAdjustment($prediction, $historicalData);

            // Calculate confidence interval
            $standardDeviation = $this->calculateStandardDeviation($historicalData);
            $confidenceInterval = [
                'lower' => max(0, $adjustedPrediction - (1.96 * $standardDeviation)),
                'upper' => $adjustedPrediction + (1.96 * $standardDeviation)
            ];

            // Create forecast result
            $forecast = ForecastResult::create([
                'hospital_id' => $hospitalId,
                'resource_type' => $resourceType,
                'ai_model_id' => $aiModelId,
                'historical_demand_id' => $historicalData->last()->id,
                'forecast_date' => now(),
                'prediction_horizon' => $forecastPeriodDays,
                'predicted_demand' => round($adjustedPrediction, 2),
                'confidence_interval_lower' => round($confidenceInterval['lower'], 2),
                'confidence_interval_upper' => round($confidenceInterval['upper'], 2),
                'accuracy_score' => null, // Will be calculated after actual data is available
                'notes' => "Forecast generated using {$aiModel->algorithm_name}",
                'created_at' => now()
            ]);

            // Check if automated actions are needed
            $this->evaluateAutomatedActions($forecast, $hospital);

            DB::commit();

            Log::info("Forecast generated successfully", [
                'forecast_id' => $forecast->id,
                'hospital_id' => $hospitalId,
                'predicted_demand' => $adjustedPrediction
            ]);

            return $forecast;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Forecast generation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Calculate prediction accuracy for completed forecasts
     * 
     * @param ForecastResult $forecast
     * @param float $actualDemand
     * @return PredictionAccuracy
     */
    public function calculateAccuracy(ForecastResult $forecast, float $actualDemand)
    {
        try {
            // Calculate various accuracy metrics
            $absoluteError = abs($forecast->predicted_demand - $actualDemand);
            $percentageError = ($absoluteError / max($actualDemand, 1)) * 100;
            $mape = min($percentageError, 100); // Cap at 100%

            // Check if within confidence interval
            $withinInterval = ($actualDemand >= $forecast->confidence_interval_lower) && 
                            ($actualDemand <= $forecast->confidence_interval_upper);

            // Calculate accuracy score (100 - MAPE)
            $accuracyScore = max(0, 100 - $mape);

            // Create accuracy record
            $accuracy = PredictionAccuracy::create([
                'forecast_result_id' => $forecast->id,
                'ai_model_id' => $forecast->ai_model_id,
                'actual_demand' => $actualDemand,
                'predicted_demand' => $forecast->predicted_demand,
                'absolute_error' => round($absoluteError, 2),
                'percentage_error' => round($percentageError, 2),
                'mape' => round($mape, 2),
                'within_confidence_interval' => $withinInterval,
                'evaluation_date' => now()
            ]);

            // Update forecast with accuracy score
            $forecast->update([
                'accuracy_score' => round($accuracyScore, 2)
            ]);

            // Update AI model performance metrics
            $this->updateModelPerformance($forecast->ai_model_id);

            Log::info("Prediction accuracy calculated", [
                'forecast_id' => $forecast->id,
                'accuracy_score' => $accuracyScore,
                'mape' => $mape
            ]);

            return $accuracy;

        } catch (Exception $e) {
            Log::error("Accuracy calculation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Trigger automated actions based on forecast
     * 
     * @param ForecastResult $forecast
     * @return array
     */
    public function triggerAutomatedActions(ForecastResult $forecast)
    {
        try {
            $hospital = Hospital::find($forecast->hospital_id);
            $actions = [];

            // Check if predicted demand exceeds critical threshold
            $currentStock = $hospital->hospitalResources()
                ->where('resource_type', $forecast->resource_type)
                ->value('current_quantity') ?? 0;

            $predictedShortfall = $forecast->predicted_demand - $currentStock;

            if ($predictedShortfall > 0) {
                // Create restock action
                $action = AutomatedAction::create([
                    'forecast_result_id' => $forecast->id,
                    'action_type' => 'restock_recommendation',
                    'action_details' => json_encode([
                        'resource_type' => $forecast->resource_type,
                        'recommended_quantity' => ceil($predictedShortfall * 1.2), // 20% buffer
                        'urgency' => $predictedShortfall > ($forecast->predicted_demand * 0.5) ? 'high' : 'medium',
                        'reason' => 'Predicted demand exceeds current stock'
                    ]),
                    'triggered_at' => now(),
                    'status' => 'pending',
                    'priority' => $predictedShortfall > ($forecast->predicted_demand * 0.5) ? 'high' : 'medium'
                ]);

                $actions[] = $action;
            }

            // Check if demand is significantly higher than usual (spike detection)
            $avgHistoricalDemand = HistoricalDemand::where('hospital_id', $forecast->hospital_id)
                ->where('resource_type', $forecast->resource_type)
                ->where('date', '>=', now()->subDays(30))
                ->avg('demand_quantity');

            if ($forecast->predicted_demand > ($avgHistoricalDemand * 1.5)) {
                // Create alert action
                $action = AutomatedAction::create([
                    'forecast_result_id' => $forecast->id,
                    'action_type' => 'demand_spike_alert',
                    'action_details' => json_encode([
                        'resource_type' => $forecast->resource_type,
                        'predicted_demand' => $forecast->predicted_demand,
                        'average_demand' => round($avgHistoricalDemand, 2),
                        'increase_percentage' => round((($forecast->predicted_demand / $avgHistoricalDemand) - 1) * 100, 2),
                        'alert_message' => 'Unusual demand spike detected'
                    ]),
                    'triggered_at' => now(),
                    'status' => 'pending',
                    'priority' => 'critical'
                ]);

                $actions[] = $action;
            }

            Log::info("Automated actions triggered", [
                'forecast_id' => $forecast->id,
                'actions_count' => count($actions)
            ]);

            return $actions;

        } catch (Exception $e) {
            Log::error("Automated action trigger failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Calculate moving average from historical data
     * 
     * @param $historicalData
     * @param int $period
     * @return float
     */
    private function calculateMovingAverage($historicalData, int $period = 7)
    {
        $recentData = $historicalData->take(-$period);
        return $recentData->avg('demand_quantity');
    }

    /**
     * Apply seasonal adjustment to prediction
     * 
     * @param float $basePrediction
     * @param $historicalData
     * @return float
     */
    private function applySeasonalAdjustment(float $basePrediction, $historicalData)
    {
        // Calculate day-of-week pattern
        $dayOfWeek = now()->dayOfWeek;
        
        $dayPattern = $historicalData
            ->filter(function ($record) use ($dayOfWeek) {
                return Carbon::parse($record->date)->dayOfWeek === $dayOfWeek;
            })
            ->avg('demand_quantity');

        $overallAverage = $historicalData->avg('demand_quantity');

        if ($overallAverage > 0 && $dayPattern > 0) {
            $seasonalFactor = $dayPattern / $overallAverage;
            return $basePrediction * $seasonalFactor;
        }

        return $basePrediction;
    }

    /**
     * Calculate standard deviation for confidence intervals
     * 
     * @param $historicalData
     * @return float
     */
    private function calculateStandardDeviation($historicalData)
    {
        $values = $historicalData->pluck('demand_quantity')->toArray();
        $mean = array_sum($values) / count($values);
        
        $variance = array_sum(array_map(function ($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values)) / count($values);

        return sqrt($variance);
    }

    /**
     * Evaluate if automated actions are needed
     * 
     * @param ForecastResult $forecast
     * @param Hospital $hospital
     */
    private function evaluateAutomatedActions(ForecastResult $forecast, Hospital $hospital)
    {
        $currentStock = $hospital->hospitalResources()
            ->where('resource_type', $forecast->resource_type)
            ->value('current_quantity') ?? 0;

        if ($forecast->predicted_demand > $currentStock) {
            $this->triggerAutomatedActions($forecast);
        }
    }

    /**
     * Update AI model performance metrics
     * 
     * @param int $aiModelId
     */
    private function updateModelPerformance(int $aiModelId)
    {
        $accuracies = PredictionAccuracy::where('ai_model_id', $aiModelId)
            ->where('evaluation_date', '>=', now()->subDays(30))
            ->get();

        if ($accuracies->count() > 0) {
            $avgMape = $accuracies->avg('mape');
            $avgAccuracy = 100 - $avgMape;

            AiModel::where('id', $aiModelId)->update([
                'accuracy' => round($avgAccuracy, 2),
                'last_updated' => now()
            ]);
        }
    }

    /**
     * Get forecasting statistics
     * 
     * @param array $filters
     * @return array
     */
    public function getForecastingStatistics(array $filters = [])
    {
        $query = ForecastResult::query();

        if (isset($filters['hospital_id'])) {
            $query->where('hospital_id', $filters['hospital_id']);
        }

        if (isset($filters['resource_type'])) {
            $query->where('resource_type', $filters['resource_type']);
        }

        if (isset($filters['start_date'])) {
            $query->where('forecast_date', '>=', $filters['start_date']);
        }

        $forecasts = $query->with('predictionAccuracy')->get();

        return [
            'total_forecasts' => $forecasts->count(),
            'average_accuracy' => $forecasts->whereNotNull('accuracy_score')->avg('accuracy_score'),
            'forecasts_with_accuracy' => $forecasts->whereNotNull('accuracy_score')->count(),
            'by_resource_type' => $forecasts->groupBy('resource_type')->map->count(),
            'by_hospital' => $forecasts->groupBy('hospital_id')->map->count(),
            'automated_actions_triggered' => AutomatedAction::whereIn('forecast_result_id', $forecasts->pluck('id'))->count()
        ];
    }
}