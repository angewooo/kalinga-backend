<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseApiController;
use App\Models\RealTimeSensor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SensorController extends BaseApiController
{
    protected function getModel(): string
    {
        return RealTimeSensor::class;
    }

    protected function getValidationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'status' => 'required|string|in:active,inactive'
        ];
    }

    /**
    /**
 * Handle sensor data webhook - ULTRA SIMPLE VERSION  
 */
public function handleSensorWebhook(Request $request)
{
    // Ultra simple - no dependencies, no validation, no logging
    return response()->json(['status' => 'ok', 'message' => 'sensor webhook works']);
}
    public function index(Request $request)
    {
        $query = RealTimeSensor::query();
        $query = $this->applyFilters($query, $request, $this->getSearchableFields());

        $params = $this->getPaginationParams($request);
        $sensors = $query->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return $this->successResponse($sensors);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->getValidationRules());
        $sensor = RealTimeSensor::create($validated);

        $this->logActivity('Created sensor', ['sensor_id' => $sensor->id]);
        return $this->successResponse($sensor, 'Sensor created successfully', 201);
    }

    public function show(int $id)
    {
        $sensor = $this->getResourceWithRelations($id);
        return $this->successResponse($sensor);
    }

    public function update(Request $request, int $id)
    {
        $sensor = $this->getResourceWithRelations($id);
        $validated = $request->validate($this->getValidationRules());
        $sensor->update($validated);

        $this->logActivity('Updated sensor', ['sensor_id' => $sensor->id]);
        return $this->successResponse($sensor, 'Sensor updated successfully');
    }

    public function destroy(int $id)
    {
        $sensor = $this->getResourceWithRelations($id);
        $sensor->delete();

        $this->logActivity('Deleted sensor', ['sensor_id' => $id]);
        return $this->successResponse(null, 'Sensor deleted successfully');
    }
}