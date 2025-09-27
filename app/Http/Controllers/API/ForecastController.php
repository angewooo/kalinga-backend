<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseApiController;
use App\Models\ForecastResult;
use Illuminate\Http\Request;

class ForecastController extends BaseApiController
{
    /**
     * Return the model class name.
     */
    protected function getModel(): string
    {
        return ForecastResult::class;
    }

    /**
     * Validation rules for creating/updating forecast results.
     */
    protected function getValidationRules($id = null): array
    {
        return [
            'model_id' => 'required|exists:ai_models,id',
            'forecast_date' => 'required|date',
            'predicted_value' => 'required|numeric',
            'confidence_interval' => 'nullable|string',
        ];
    }

    /**
     * Example: List all forecasts.
     */
    public function index()
    {
        $forecasts = ForecastResult::all();
        return $this->successResponse($forecasts);
    }

    /**
     * Example: Store a forecast result.
     */
    public function store(Request $request)
    {
        $validated = $this->validateRequest($request);
        $forecast = ForecastResult::create($validated);

        return $this->successResponse($forecast, 'Forecast saved successfully.');
    }
}
