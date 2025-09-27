<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseApiController;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class VehicleController extends BaseApiController
{
    protected function getModel(): string
    {
        return Vehicle::class;
    }

    protected function getValidationRules(): array
    {
        return [
            'plate_number' => 'required|string|max:20|unique:vehicles,plate_number',
            'type' => 'required|string|max:50',
            'status' => 'required|string|in:available,unavailable'
        ];
    }

    public function index(Request $request)
    {
        $query = Vehicle::query();
        $query = $this->applyFilters($query, $request, $this->getSearchableFields());

        $params = $this->getPaginationParams($request);
        $vehicles = $query->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return $this->successResponse($vehicles);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->getValidationRules());
        $vehicle = Vehicle::create($validated);

        $this->logActivity('Created vehicle', ['vehicle_id' => $vehicle->id]);
        return $this->successResponse($vehicle, 'Vehicle created successfully', 201);
    }

    public function show(int $id)
    {
        $vehicle = $this->getResourceWithRelations($id);
        return $this->successResponse($vehicle);
    }

    public function update(Request $request, int $id)
    {
        $vehicle = $this->getResourceWithRelations($id);
        $validated = $request->validate($this->getValidationRules());
        $vehicle->update($validated);

        $this->logActivity('Updated vehicle', ['vehicle_id' => $vehicle->id]);
        return $this->successResponse($vehicle, 'Vehicle updated successfully');
    }

    public function destroy(int $id)
    {
        $vehicle = $this->getResourceWithRelations($id);
        $vehicle->delete();

        $this->logActivity('Deleted vehicle', ['vehicle_id' => $id]);
        return $this->successResponse(null, 'Vehicle deleted successfully');
    }
}
