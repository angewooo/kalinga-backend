<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseApiController;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SupplierController extends BaseApiController
{   
    protected function getModel(): string
    {
        return Supplier::class;
    }

    protected function getValidationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:suppliers,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
        ];
    }

    /**
 /**
 * Handle supplier webhook updates - ULTRA SIMPLE VERSION
 */
public function handleSupplierWebhook(Request $request)
{
    // Ultra simple - no dependencies, no validation, no logging
    return response()->json(['status' => 'ok', 'message' => 'webhook works']);
}

    public function index(Request $request)
    {
        $query = Supplier::query();
        $query = $this->applyFilters($query, $request, $this->getSearchableFields());

        $params = $this->getPaginationParams($request);
        $suppliers = $query->paginate($params['per_page'], ['*'], 'page', $params['page']);

        return $this->successResponse($suppliers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->getValidationRules());
        $supplier = Supplier::create($validated);

        $this->logActivity('Created supplier', ['supplier_id' => $supplier->id]);
        return $this->successResponse($supplier, 'Supplier created successfully', 201);
    }

    public function show(int $id)
    {
        $supplier = $this->getResourceWithRelations($id);
        return $this->successResponse($supplier);
    }

    public function update(Request $request, int $id)
    {
        $supplier = $this->getResourceWithRelations($id);
        $validated = $request->validate($this->getValidationRules());
        $supplier->update($validated);

        $this->logActivity('Updated supplier', ['supplier_id' => $supplier->id]);
        return $this->successResponse($supplier, 'Supplier updated successfully');
    }

    public function destroy(int $id)
    {
        $supplier = $this->getResourceWithRelations($id);
        $supplier->delete();

        $this->logActivity('Deleted supplier', ['supplier_id' => $id]);
        return $this->successResponse(null, 'Supplier deleted successfully');
    }
}