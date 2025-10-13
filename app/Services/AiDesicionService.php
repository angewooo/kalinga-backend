<?php

namespace App\Services;

use App\Models\AiModel;
use App\Models\AiDecision;
use App\Models\ModelRetraining;
use App\Models\PredictionAccuracy;
use App\Models\HistoricalDemand;
use App\Models\RequestEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class AiDecisionService
{
    /**
     * Make AI decision for resource allocation
     * 
     * @param RequestEntry $request
     * @param int $aiModelId
     * @return AiDecision
     */
    public function makeDecision(RequestEntry $request, int $aiModelId = 1)
    {
        DB::beginTransaction();
        
        try {
            $aiModel = AiModel::findOrFail($aiModelId);

            // Gather input data for decision
            $inputData = $this->prepareInputData($request);

            // Run decision algorithm
            $decisionResult = $this->runDecisionAlgorithm($aiModel, $inputData);

            // Create decision record
            $decision = AiDecision::create([
                'ai_model_id' => $aiModel->id,
                'request_entry_id' => $request->id,
                'decision_type' => 'resource_allocation',
                'input_data' => json_encode($inputData),
                'output_data' => json_encode($decisionResult),
                'confidence_score' => $decisionResult['confidence'],
                'decision_timestamp' => now(),
                'is_overridden' => false,
                'override_reason' => null
            ]);

            DB::commit();

            Log::info("AI decision made", [
                'decision_id' => $decision->id,
                'model' => $aiModel->model_name,
                'confidence' => $decisionResult['confidence']
            ]);

            return $decision;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("AI decision failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrain AI model with new data
     * 
     * @param int $aiModelId
     * @param array $trainingData
     * @return ModelRetraining
     */
    public function retrainModel(int $aiModelId, array $trainingData = [])
    {
        DB::beginTransaction();
        
        try {
            $aiModel = AiModel::findOrFail($aiModelId);

            // Gather training data if not provided
            if (empty($trainingData)) {
                $trainingData = $this->gatherTrainingData($aiModel);
            }

            // Calculate new accuracy metrics
            $newAccuracy = $this->calculateModelAccuracy($aiModel);

            // Create retraining record
            $retraining = ModelRetraining::create([
                'ai_model_id' => $aiModel->id,
                'training_data' => json_encode($trainingData),
                'previous_accuracy' => $aiModel->accuracy,
                'new_accuracy' => $newAccuracy,
                'improvement_percentage' => $newAccuracy - $aiModel->accuracy,
                'retrain_timestamp' => now(),
                'status' => 'completed',
                'notes' => 'Model retrained with ' . count($trainingData) . ' data points'
            ]);

            // Update model accuracy
            $aiModel->update([
                'accuracy' => $newAccuracy,
                'last_updated' => now(),
                'version' => $aiModel->version + 1
            ]);

            DB::commit();

            Log::info("Model retrained", [
                'model_id' => $aiModel->id,
                'old_accuracy' => $retraining->previous_accuracy,
                'new_accuracy' => $newAccuracy
            ]);

            return $retraining;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Model retraining failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Evaluate AI model performance
     * 
     * @param int $aiModelId
     * @param int $days
     * @return array
     */
    public function evaluatePerformance(int $aiModelId, int $days = 30)
    {
        try {
            $aiModel = AiModel::findOrFail($aiModelId);

            // Get recent predictions
            $accuracies = PredictionAccuracy::where('ai_model_id', $aiModelId)
                ->where('evaluation_date', '>=', now()->subDays($days))
                ->get();

            if ($accuracies->isEmpty()) {
                return [
                    'model_id' => $aiModelId,
                    'model_name' => $aiModel->model_name,
                    'message' => 'No evaluation data available for the specified period'
                ];
            }

            // Calculate performance metrics
            $avgMape = $accuracies->avg('mape');
            $avgAbsoluteError = $accuracies->avg('absolute_error');
            $withinConfidenceCount = $accuracies->where('within_confidence_interval', true)->count();
            $totalCount = $accuracies->count();

            // Get decisions made
            $decisions = AiDecision::where('ai_model_id', $aiModelId)
                ->where('decision_timestamp', '>=', now()->subDays($days))
                ->get();

            return [
                'model_id' => $aiModelId,
                'model_name' => $aiModel->model_name,
                'algorithm' => $aiModel->algorithm_name,
                'evaluation_period_days' => $days,
                'performance_metrics' => [
                    'average_accuracy' => round(100 - $avgMape, 2),
                    'average_mape' => round($avgMape, 2),
                    'average_absolute_error' => round($avgAbsoluteError, 2),
                    'confidence_interval_accuracy' => round(($withinConfidenceCount / $totalCount) * 100, 2),
                    'total_predictions' => $totalCount
                ],
                'decision_statistics' => [
                    'total_decisions' => $decisions->count(),
                    'average_confidence' => round($decisions->avg('confidence_score'), 2),
                    'overridden_decisions' => $decisions->where('is_overridden', true)->count(),
                    'by_decision_type' => $decisions->groupBy('decision_type')->map->count()
                ],
                'retraining_history' => ModelRetraining::where('ai_model_id', $aiModelId)
                    ->orderBy('retrain_timestamp', 'desc')
                    ->limit(5)
                    ->get()
                    ->map(function($retraining) {
                        return [
                            'date' => $retraining->retrain_timestamp,
                            'previous_accuracy' => $retraining->previous_accuracy,
                            'new_accuracy' => $retraining->new_accuracy,
                            'improvement' => $retraining->improvement_percentage
                        ];
                    })
            ];

        } catch (Exception $e) {
            Log::error("Performance evaluation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Override AI decision with human judgment
     * 
     * @param int $decisionId
     * @param string $reason
     * @param array $newOutput
     * @return AiDecision
     */
    public function overrideDecision(int $decisionId, string $reason, array $newOutput = [])
    {
        try {
            $decision = AiDecision::findOrFail($decisionId);

            $decision->update([
                'is_overridden' => true,
                'override_reason' => $reason,
                'output_data' => !empty($newOutput) ? json_encode($newOutput) : $decision->output_data
            ]);

            Log::info("AI decision overridden", [
                'decision_id' => $decisionId,
                'reason' => $reason
            ]);

            return $decision;

        } catch (Exception $e) {
            Log::error("Decision override failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Prepare input data for AI decision
     * 
     * @param RequestEntry $request
     * @return array
     */
    private function prepareInputData(RequestEntry $request)
    {
        $hospital = $request->requestingHospital;

        // Get historical demand patterns
        $historicalAvg = HistoricalDemand::where('hospital_id', $hospital->id)
            ->where('resource_type', $request->resource_type)
            ->where('date', '>=', now()->subDays(30))
            ->avg('demand_quantity') ?? 0;

        return [
            'hospital_id' => $hospital->id,
            'hospital_location' => [
                'latitude' => $hospital->latitude,
                'longitude' => $hospital->longitude
            ],
            'resource_type' => $request->resource_type,
            'quantity_needed' => $request->quantity_needed,
            'urgency_level' => $request->urgency_level,
            'historical_average_demand' => round($historicalAvg, 2),
            'day_of_week' => now()->dayOfWeek,
            'hour_of_day' => now()->hour,
            'is_weekend' => now()->isWeekend(),
            'timestamp' => now()->toISOString()
        ];
    }

    /**
     * Run decision algorithm
     * 
     * @param AiModel $model
     * @param array $inputData
     * @return array
     */
    private function runDecisionAlgorithm(AiModel $model, array $inputData)
    {
        // Simplified decision algorithm
        // In production, this would call actual ML model API

        $urgencyWeight = match($inputData['urgency_level']) {
            'critical' => 1.5,
            'high' => 1.2,
            'normal' => 1.0,
            default => 0.8
        };

        // Calculate confidence based on historical data availability
        $confidence = $inputData['historical_average_demand'] > 0 ? 85 : 60;

        // Adjust confidence based on urgency
        $confidence = min(95, $confidence * $urgencyWeight);

        // Determine recommended action
        $recommendedAction = $this->determineRecommendedAction($inputData, $urgencyWeight);

        return [
            'recommended_action' => $recommendedAction,
            'confidence' => round($confidence, 2),
            'priority_score' => round($inputData['quantity_needed'] * $urgencyWeight, 2),
            'estimated_fulfillment_time' => $this->estimateFulfillmentTime($inputData),
            'alternative_options' => $this->generateAlternatives($inputData),
            'reasoning' => $this->generateReasoning($inputData, $recommendedAction)
        ];
    }

    /**
     * Determine recommended action
     * 
     * @param array $inputData
     * @param float $urgencyWeight
     * @return string
     */
    private function determineRecommendedAction(array $inputData, float $urgencyWeight)
    {
        if ($urgencyWeight >= 1.5) {
            return 'immediate_allocation';
        } elseif ($urgencyWeight >= 1.2) {
            return 'priority_allocation';
        } else {
            return 'standard_allocation';
        }
    }

    /**
     * Estimate fulfillment time
     * 
     * @param array $inputData
     * @return string
     */
    private function estimateFulfillmentTime(array $inputData)
    {
        $baseTime = match($inputData['urgency_level']) {
            'critical' => 30,
            'high' => 60,
            'normal' => 120,
            default => 180
        };

        return $baseTime . ' minutes';
    }

    /**
     * Generate alternative options
     * 
     * @param array $inputData
     * @return array
     */
    private function generateAlternatives(array $inputData)
    {
        return [
            'partial_fulfillment' => 'Deliver ' . floor($inputData['quantity_needed'] * 0.7) . ' units immediately',
            'split_delivery' => 'Split into 2 deliveries from different sources',
            'wait_for_restock' => 'Wait for scheduled restock in 2 hours'
        ];
    }

    /**
     * Generate reasoning for decision
     * 
     * @param array $inputData
     * @param string $action
     * @return string
     */
    private function generateReasoning(array $inputData, string $action)
    {
        return sprintf(
            "Based on %s urgency level and historical demand of %.2f units, recommending %s to optimize resource distribution and response time.",
            $inputData['urgency_level'],
            $inputData['historical_average_demand'],
            str_replace('_', ' ', $action)
        );
    }

    /**
     * Gather training data for model retraining
     * 
     * @param AiModel $model
     * @return array
     */
    private function gatherTrainingData(AiModel $model)
    {
        // Get recent completed requests with their outcomes
        $completedRequests = RequestEntry::where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(90))
            ->with(['resourceAllocations.assignment.deliveryPerformance'])
            ->get();

        return $completedRequests->map(function($request) {
            return [
                'input' => [
                    'resource_type' => $request->resource_type,
                    'quantity' => $request->quantity_needed,
                    'urgency' => $request->urgency_level
                ],
                'output' => [
                    'success' => $request->status === 'completed',
                    'completion_time' => $request->requested_at->diffInMinutes($request->updated_at)
                ]
            ];
        })->toArray();
    }

    /**
     * Calculate model accuracy
     * 
     * @param AiModel $model
     * @return float
     */
    private function calculateModelAccuracy(AiModel $model)
    {
        $recentAccuracies = PredictionAccuracy::where('ai_model_id', $model->id)
            ->where('evaluation_date', '>=', now()->subDays(30))
            ->get();

        if ($recentAccuracies->isEmpty()) {
            return $model->accuracy ?? 0;
        }

        $avgMape = $recentAccuracies->avg('mape');
        return max(0, 100 - $avgMape);
    }

    /**
     * Get AI decision statistics
     * 
     * @param array $filters
     * @return array
     */
    public function getDecisionStatistics(array $filters = [])
    {
        $query = AiDecision::query();

        if (isset($filters['model_id'])) {
            $query->where('ai_model_id', $filters['model_id']);
        }

        if (isset($filters['start_date'])) {
            $query->where('decision_timestamp', '>=', $filters['start_date']);
        }

        $decisions = $query->get();

        return [
            'total_decisions' => $decisions->count(),
            'average_confidence' => round($decisions->avg('confidence_score'), 2),
            'by_decision_type' => $decisions->groupBy('decision_type')->map->count(),
            'overridden_count' => $decisions->where('is_overridden', true)->count(),
            'override_rate' => $decisions->count() > 0 
                ? round(($decisions->where('is_overridden', true)->count() / $decisions->count()) * 100, 2)
                : 0,
            'high_confidence_decisions' => $decisions->where('confidence_score', '>=', 80)->count()
        ];
    }
}