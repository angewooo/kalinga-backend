<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VehicleService
{
    /**
     * Fetch all vehicles with optional filters.
     */
    public function getAllVehicles(array $filters = [])
    {
        $query = Vehicle::query();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    /**
     * Find a vehicle by ID.
     */
    public function getVehicleById(int $id): ?Vehicle
    {
        return Vehicle::find($id);
    }

    /**
     * Create a new vehicle.
     */
    public function createVehicle(array $data): Vehicle
    {
        $validator = Validator::make($data, [
            'plate_number' => 'required|string|max:50|unique:vehicles,plate_number',
            'type' => 'required|string|max:50',
            'status' => 'required|string|max:50',
            'capacity' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return Vehicle::create($data);
    }

    /**
     * Update an existing vehicle.
     */
    public function updateVehicle(int $id, array $data): ?Vehicle
    {
        $vehicle = Vehicle::findOrFail($id);

        $validator = Validator::make($data, [
            'plate_number' => 'sometimes|string|max:50|unique:vehicles,plate_number,' . $id,
            'type' => 'sometimes|string|max:50',
            'status' => 'sometimes|string|max:50',
            'capacity' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $vehicle->update($data);
        return $vehicle;
    }

    /**
     * Delete a vehicle by ID.
     */
    public function deleteVehicle(int $id): bool
    {
        $vehicle = Vehicle::findOrFail($id);
        return $vehicle->delete();
    }
}
