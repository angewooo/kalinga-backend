<?php

namespace App\Http\Controllers\API;

use App\Models\RequestEntry;
use App\Models\Assignment;
use App\Models\Hospital;
use App\Models\Responder;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Emergency Request Management Controller for Project Kalinga
 * Handles emergency request workflow, assignments, and tracking
 */
class RequestController extends BaseApiController
{
    /**
     * Get the model class for this controller
     */
    protected function getModel(): string
    {
        return RequestEntry::class;
    }

    /**
     * Get validation rules for request operations
     */
    protected function getValidationRules(): array
    {
        return [
            'citizen_name' => 'required|string|max:100',
            'citizen_contact' => 'required|string|max:20',
            'incident_type' => 'required|string|max:50|in:medical_emergency,fire,accident,natural_disaster,security,other',
            'severity_level' => 'required|string|in:low,medium,high,critical',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'address' => 'required|string',
            'description' => 'required|string|min:10',
        ];
    }

    /**
     * Get searchable fields
     */
    protected function getSearchableFields(): array
    {
        return ['citizen_name', 'citizen_contact', 'incident_type', 'address', 'description'];
    }

    /**
     * Get default relationships to load
     */
    protected function getDefaultRelations(): array
    {
        return ['assignments.responder.user', 'assignments.hospital', 'assignments.vehicle'];
    }

    /**
     * Display a listing of emergency requests
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate query parameters
            $request->validate($this->commonRules + [
                'status' => 'string|in:pending,assigned,in_progress,completed,cancelled',
                'severity_level' => 'string|in:low,medium,high,critical',
                'incident_type' => 'string|in:medical_emergency,fire,accident,natural_disaster,security,other',
                'date_from' => 'date',
                'date_to' => 'date|after_or_equal:date_from',
                'assigned_hospital' => 'integer|exists:hospitals,hospital_id',
                'assigned_responder' => 'integer|exists:responders,responder_id'
            ]);

            $query = RequestEntry::with($this->getDefaultRelations());

            // Apply standard filters
            $query = $this->applyFilters($query, $request, $this->getSearchableFields());

            // Severity level filter
            if ($severityLevel = $request->get('severity_level')) {
                $query->where('severity_level', $severityLevel);
            }

            // Incident type filter
            if ($incidentType = $request->get('incident_type')) {
                $query->where('incident_type', $incidentType);
            }

            // Hospital assignment filter
            if ($hospitalId = $request->get('assigned_hospital')) {
                $query->whereHas('assignments', function($q) use ($hospitalId) {
                    $q->where('hospital_id', $hospitalId);
                });
            }

            // Responder assignment filter
            if ($responderId = $request->get('assigned_responder')) {
                $query->whereHas('assignments', function($q) use ($responderId) {
                    $q->where('responder_id', $responderId);
                });
            }

            // Date range filters
            if ($dateFrom = $request->get('date_from')) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo = $request->get('date_to')) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            // Pagination with severity-based sorting
            $params = $this->getPaginationParams($request);
            
            // Default sort: Critical first, then by creation date
            if (!$request->has('sort')) {
                $query->orderByRaw("
                    CASE severity_level 
                        WHEN 'critical' THEN 1 
                        WHEN 'high' THEN 2 
                        WHEN 'medium' THEN 3 
                        WHEN 'low' THEN 4 
                    END
                ")->orderBy('created_at', 'desc');
            }

            $requests = $query->paginate($params['per_page']);

            // Add calculated fields
            $requests->getCollection()->transform(function ($request) {
                $request->response_time = $this->calculateResponseTime($request);
                $request->estimated_arrival = $this->calculateEstimatedArrival($request);
                $request->priority_score = $this->calculatePriorityScore($request);
                return $request;
            });

            $this->logActivity('Emergency requests listed', [
                'total' => $requests->total(),
                'filters' => $request->only(['status', 'severity_level', 'incident_type'])
            ]);

            return $this->paginatedResponse($requests, 'Emergency requests retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'listing emergency requests');
        }
    }

    /**
     * Store a newly created emergency request
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate($this->getValidationRules());

            // Validate coordinates
            if (!$this->validateCoordinates($validated['latitude'], $validated['longitude'])) {
                return $this->errorResponse('Invalid coordinates provided.', 400);
            }

            DB::beginTransaction();

            // Create emergency request
            $emergencyRequest = RequestEntry::create([
                ...$validated,
                'status' => 'pending',
                'created_at' => now()
            ]);

            // Trigger critical alert if necessary
            if ($this->isCriticalRequest($request)) {
                $this->sendCriticalAlert([
                    'request_id' => $emergencyRequest->request_id,
                    'severity' => $validated['severity_level'],
                    'incident_type' => $validated['incident_type'],
                    'location' => $validated['address'],
                    'coordinates' => [$validated['latitude'], $validated['longitude']]
                ]);
            }

            // Auto-assign if high priority and responders available
            if (in_array($validated['severity_level'], ['high', 'critical'])) {
                $this->attemptAutoAssignment($emergencyRequest);
            }

            DB::commit();

            // Load relationships for response
            $emergencyRequest->load($this->getDefaultRelations());

            $this->logActivity('Emergency request created', [
                'request_id' => $emergencyRequest->request_id,
                'severity' => $emergencyRequest->severity_level,
                'incident_type' => $emergencyRequest->incident_type,
                'auto_assigned' => isset($emergencyRequest->assignments) && $emergencyRequest->assignments->count() > 0
            ]);

            return $this->emergencyRequestResponse(
                $emergencyRequest, 
                $emergencyRequest->severity_level
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'creating emergency request');
        }
    }

    /**
     * Display the specified emergency request
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $emergencyRequest = RequestEntry::with([
                'assignments.responder.user.profile',
                'assignments.hospital',
                'assignments.vehicle'
            ])->findOrFail($id);

            // Add calculated metrics
            $emergencyRequest->response_time = $this->calculateResponseTime($emergencyRequest);
            $emergencyRequest->estimated_arrival = $this->calculateEstimatedArrival($emergencyRequest);
            $emergencyRequest->priority_score = $this->calculatePriorityScore($emergencyRequest);
            $emergencyRequest->timeline = $this->generateTimeline($emergencyRequest);
            $emergencyRequest->nearby_resources = $this->findNearbyResources($emergencyRequest);

            $this->logActivity('Emergency request viewed', [
                'request_id' => $emergencyRequest->request_id
            ]);

            return $this->successResponse($emergencyRequest, 'Emergency request retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving emergency request');
        }
    }

    /**
     * Update the specified emergency request
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('update-requests')) {
                return $this->forbiddenResponse('You do not have permission to update requests.');
            }

            $emergencyRequest = RequestEntry::findOrFail($id);

            // Don't allow updates to completed/cancelled requests
            if (in_array($emergencyRequest->status, ['completed', 'cancelled'])) {
                return $this->errorResponse('Cannot update completed or cancelled requests.', 400);
            }

            // Make fields optional for updates
            $rules = $this->getValidationRules();
            foreach ($rules as $key => $rule) {
                $rules[$key] = str_replace('required|', 'sometimes|', $rule);
            }

            $validated = $request->validate($rules + [
                'status' => 'sometimes|string|in:pending,assigned,in_progress,completed,cancelled'
            ]);

            // Validate coordinates if provided
            if (isset($validated['latitude'], $validated['longitude'])) {
                if (!$this->validateCoordinates($validated['latitude'], $validated['longitude'])) {
                    return $this->errorResponse('Invalid coordinates provided.', 400);
                }
            }

            $emergencyRequest->update($validated);
            $emergencyRequest->load($this->getDefaultRelations());

            $this->logActivity('Emergency request updated', [
                'request_id' => $emergencyRequest->request_id,
                'updated_fields' => array_keys($validated)
            ]);

            return $this->updatedResponse($emergencyRequest, 'Emergency request updated successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'updating emergency request');
        }
    }

    /**
     * Assign responders/resources to emergency request
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('assign-resources')) {
                return $this->forbiddenResponse('You do not have permission to assign resources.');
            }

            $emergencyRequest = RequestEntry::findOrFail($id);

            if ($emergencyRequest->status !== 'pending') {
                return $this->errorResponse('Can only assign resources to pending requests.', 400);
            }

            $validated = $request->validate([
                'responder_id' => 'required|integer|exists:responders,responder_id',
                'hospital_id' => 'required|integer|exists:hospitals,hospital_id',
                'vehicle_id' => 'nullable|integer|exists:vehicles,vehicle_id',
                'estimated_arrival' => 'nullable|date|after:now',
                'notes' => 'nullable|string|max:500'
            ]);

            // Check responder availability
            $responder = Responder::findOrFail($validated['responder_id']);
            if ($responder->status !== 'available') {
                return $this->errorResponse('Selected responder is not available.', 400);
            }

            // Check vehicle availability if specified
            if (isset($validated['vehicle_id'])) {
                $vehicle = Vehicle::findOrFail($validated['vehicle_id']);
                if ($vehicle->status !== 'available') {
                    return $this->errorResponse('Selected vehicle is not available.', 400);
                }
            }

            DB::beginTransaction();

            // Create assignment
            $assignment = Assignment::create([
                'request_id' => $emergencyRequest->request_id,
                'responder_id' => $validated['responder_id'],
                'hospital_id' => $validated['hospital_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'status' => 'assigned',
                'assigned_at' => now(),
                'notes' => $validated['notes'] ?? null
            ]);

            // Update request status
            $emergencyRequest->update(['status' => 'assigned']);

            // Update responder status
            $responder->update(['status' => 'on_duty']);

            // Update vehicle status if assigned
            if (isset($validated['vehicle_id'])) {
                Vehicle::findOrFail($validated['vehicle_id'])->update(['status' => 'in_use']);
            }

            DB::commit();

            // Load updated data
            $emergencyRequest->load($this->getDefaultRelations());

            $this->logActivity('Resources assigned to emergency request', [
                'request_id' => $emergencyRequest->request_id,
                'assignment_id' => $assignment->assignment_id,
                'responder_id' => $validated['responder_id'],
                'hospital_id' => $validated['hospital_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null
            ]);

            return $this->successResponse([
                'request' => $emergencyRequest,
                'assignment' => $assignment->load(['responder.user', 'hospital', 'vehicle'])
            ], 'Resources assigned successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'assigning resources to request');
        }
    }

    /**
     * Update emergency request status
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('update-request-status')) {
                return $this->forbiddenResponse('You do not have permission to update request status.');
            }

            $emergencyRequest = RequestEntry::findOrFail($id);

            $validated = $request->validate([
                'status' => 'required|string|in:pending,assigned,in_progress,completed,cancelled',
                'notes' => 'nullable|string|max:500',
                'resolution_details' => 'nullable|string|max:1000'
            ]);

            // Validate status transition
            if (!$this->isValidStatusTransition($emergencyRequest->status, $validated['status'])) {
                return $this->errorResponse(
                    "Invalid status transition from {$emergencyRequest->status} to {$validated['status']}.", 
                    400
                );
            }

            DB::beginTransaction();

            $updateData = ['status' => $validated['status']];

            // Set resolved_at timestamp for completed requests
            if ($validated['status'] === 'completed') {
                $updateData['resolved_at'] = now();
            }

            $emergencyRequest->update($updateData);

            // Update assignment statuses
            if (in_array($validated['status'], ['completed', 'cancelled'])) {
                $this->releaseAssignedResources($emergencyRequest);
            }

            DB::commit();

            $this->logActivity('Emergency request status updated', [
                'request_id' => $emergencyRequest->request_id,
                'old_status' => $emergencyRequest->getOriginal('status'),
                'new_status' => $validated['status']
            ]);

            return $this->successResponse(
                $emergencyRequest->load($this->getDefaultRelations()), 
                'Request status updated successfully.'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'updating request status');
        }
    }

    /**
     * Complete emergency request
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        try {
            $emergencyRequest = RequestEntry::findOrFail($id);

            if (!in_array($emergencyRequest->status, ['in_progress', 'assigned'])) {
                return $this->errorResponse('Can only complete requests that are in progress or assigned.', 400);
            }

            $validated = $request->validate([
                'resolution_details' => 'required|string|min:10|max:1000',
                'satisfaction_rating' => 'nullable|integer|min:1|max:5',
                'response_time_minutes' => 'nullable|integer|min:0',
                'notes' => 'nullable|string|max:500'
            ]);

            DB::beginTransaction();

            // Update request
            $emergencyRequest->update([
                'status' => 'completed',
                'resolved_at' => now()
            ]);

            // Complete assignments
            $emergencyRequest->assignments()->update([
                'status' => 'completed',
                'completed_at' => now(),
                'notes' => $validated['notes'] ?? null
            ]);

            // Release resources
            $this->releaseAssignedResources($emergencyRequest);

            DB::commit();

            $this->logActivity('Emergency request completed', [
                'request_id' => $emergencyRequest->request_id,
                'resolution_details' => $validated['resolution_details'],
                'satisfaction_rating' => $validated['satisfaction_rating'] ?? null
            ]);

            return $this->successResponse(
                $emergencyRequest->load($this->getDefaultRelations()),
                'Emergency request completed successfully.'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'completing emergency request');
        }
    }

    /**
     * Cancel emergency request
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('cancel-requests')) {
                return $this->forbiddenResponse('You do not have permission to cancel requests.');
            }

            $emergencyRequest = RequestEntry::findOrFail($id);

            if (in_array($emergencyRequest->status, ['completed', 'cancelled'])) {
                return $this->errorResponse('Cannot cancel completed or already cancelled requests.', 400);
            }

            $validated = $request->validate([
                'cancellation_reason' => 'required|string|in:duplicate,false_alarm,resolved_elsewhere,no_longer_needed,other',
                'notes' => 'nullable|string|max:500'
            ]);

            DB::beginTransaction();

            // Update request
            $emergencyRequest->update([
                'status' => 'cancelled',
                'resolved_at' => now()
            ]);

            // Cancel assignments
            $emergencyRequest->assignments()->update([
                'status' => 'cancelled',
                'completed_at' => now(),
                'notes' => "Cancelled: {$validated['cancellation_reason']}. " . ($validated['notes'] ?? '')
            ]);

            // Release resources
            $this->releaseAssignedResources($emergencyRequest);

            DB::commit();

            $this->logActivity('Emergency request cancelled', [
                'request_id' => $emergencyRequest->request_id,
                'cancellation_reason' => $validated['cancellation_reason']
            ]);

            return $this->successResponse(
                $emergencyRequest->load($this->getDefaultRelations()),
                'Emergency request cancelled successfully.'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'cancelling emergency request');
        }
    }

    /**
 * Approve emergency request
 *
 * @param Request $request
 * @param int $id
 * @return JsonResponse
 */
public function approve(Request $request, int $id): JsonResponse
{
    try {
        if (!$this->userCan('approve-requests')) {
            return $this->forbiddenResponse('You do not have permission to approve requests.');
        }

        $emergencyRequest = RequestEntry::findOrFail($id);

        if ($emergencyRequest->status !== 'pending') {
            return $this->errorResponse('Can only approve pending requests.', 400);
        }

        $validated = $request->validate([
            'approved_by' => 'required|integer|exists:users,id',
            'approval_notes' => 'nullable|string|max:500'
        ]);

        DB::beginTransaction();

        // Update request status to approved (which might transition to assigned)
        $emergencyRequest->update([
            'status' => 'assigned', // or 'approved' if you have that status
            'approved_by' => $validated['approved_by'],
            'approved_at' => now()
        ]);

        // Log approval activity
        $this->logActivity('Emergency request approved', [
            'request_id' => $emergencyRequest->request_id,
            'approved_by' => $validated['approved_by'],
            'notes' => $validated['approval_notes'] ?? null
        ]);

        DB::commit();

        return $this->successResponse(
            $emergencyRequest->load($this->getDefaultRelations()),
            'Emergency request approved successfully.'
        );

    } catch (\Exception $e) {
        DB::rollBack();
        return $this->handleException($e, 'approving emergency request');
    }
}

    /**
     * Get active emergency requests
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function active(Request $request): JsonResponse
    {
        try {
            $query = RequestEntry::with($this->getDefaultRelations())
                ->whereIn('status', ['pending', 'assigned', 'in_progress'])
                ->orderByRaw("
                    CASE severity_level 
                        WHEN 'critical' THEN 1 
                        WHEN 'high' THEN 2 
                        WHEN 'medium' THEN 3 
                        WHEN 'low' THEN 4 
                    END
                ")
                ->orderBy('created_at', 'desc');

            $params = $this->getPaginationParams($request);
            $activeRequests = $query->paginate($params['per_page']);

            // Add real-time metrics
            $activeRequests->getCollection()->transform(function ($request) {
                $request->elapsed_time = now()->diffInMinutes($request->created_at);
                $request->priority_score = $this->calculatePriorityScore($request);
                return $request;
            });

            return $this->paginatedResponse($activeRequests, 'Active emergency requests retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving active requests');
        }
    }

    /**
     * Get completed emergency requests
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function completed(Request $request): JsonResponse
    {
        try {
            $query = RequestEntry::with($this->getDefaultRelations())
                ->where('status', 'completed')
                ->orderBy('resolved_at', 'desc');

            $params = $this->getPaginationParams($request);
            $completedRequests = $query->paginate($params['per_page']);

            return $this->paginatedResponse($completedRequests, 'Completed emergency requests retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving completed requests');
        }
    }

    /**
     * Get critical emergency requests
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function critical(Request $request): JsonResponse
    {
        try {
            $criticalRequests = RequestEntry::with($this->getDefaultRelations())
                ->where('severity_level', 'critical')
                ->whereIn('status', ['pending', 'assigned', 'in_progress'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return $this->paginatedResponse($criticalRequests, 'Critical emergency requests retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving critical requests');
        }
    }

    /**
     * Get request statistics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $stats = [
                'total_requests' => RequestEntry::count(),
                'today' => [
                    'total' => RequestEntry::whereDate('created_at', today())->count(),
                    'pending' => RequestEntry::whereDate('created_at', today())->where('status', 'pending')->count(),
                    'in_progress' => RequestEntry::whereDate('created_at', today())->where('status', 'in_progress')->count(),
                    'completed' => RequestEntry::whereDate('created_at', today())->where('status', 'completed')->count()
                ],
                'by_severity' => [
                    'critical' => RequestEntry::where('severity_level', 'critical')->count(),
                    'high' => RequestEntry::where('severity_level', 'high')->count(),
                    'medium' => RequestEntry::where('severity_level', 'medium')->count(),
                    'low' => RequestEntry::where('severity_level', 'low')->count()
                ],
                'by_status' => [
                    'pending' => RequestEntry::where('status', 'pending')->count(),
                    'assigned' => RequestEntry::where('status', 'assigned')->count(),
                    'in_progress' => RequestEntry::where('status', 'in_progress')->count(),
                    'completed' => RequestEntry::where('status', 'completed')->count(),
                    'cancelled' => RequestEntry::where('status', 'cancelled')->count()
                ],
                'by_incident_type' => RequestEntry::selectRaw('incident_type, COUNT(*) as count')
                    ->groupBy('incident_type')
                    ->pluck('count', 'incident_type'),
                'response_times' => [
                    'average_minutes' => $this->getAverageResponseTime(),
                    'median_minutes' => $this->getMedianResponseTime()
                ]
            ];

            return $this->successResponse($stats, 'Request statistics retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving request statistics');
        }
    }

    /**
     * Helper Methods
     */

    /**
     * Attempt automatic assignment for high-priority requests
     */
    private function attemptAutoAssignment(RequestEntry $request): void
    {
        // Find nearest available responder
        $nearestResponder = Responder::where('status', 'available')
            ->with(['hospital'])
            ->get()
            ->sortBy(function ($responder) use ($request) {
                if (!$responder->hospital) return 999999;
                return $this->calculateDistance(
                    $request->latitude, 
                    $request->longitude,
                    $responder->hospital->latitude, 
                    $responder->hospital->longitude
                );
            })
            ->first();

        if ($nearestResponder) {
            // Auto-assign
            Assignment::create([
                'request_id' => $request->request_id,
                'responder_id' => $nearestResponder->responder_id,
                'hospital_id' => $nearestResponder->hospital_id,
                'status' => 'assigned',
                'assigned_at' => now(),
                'notes' => 'Auto-assigned based on proximity and availability'
            ]);

            $request->update(['status' => 'assigned']);
            $nearestResponder->update(['status' => 'on_duty']);
        }
    }

    /**
     * Release assigned resources when request is completed/cancelled
     */
    private function releaseAssignedResources(RequestEntry $request): void
    {
        foreach ($request->assignments as $assignment) {
            // Release responder
            if ($assignment->responder) {
                $assignment->responder->update(['status' => 'available']);
            }

            // Release vehicle
            if ($assignment->vehicle) {
                $assignment->vehicle->update(['status' => 'available']);
            }
        }
    }

    /**
     * Validate status transitions
     */
    private function isValidStatusTransition(string $currentStatus, string $newStatus): bool
    {
        $validTransitions = [
            'pending' => ['assigned', 'cancelled'],
            'assigned' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => []
        ];

        return in_array($newStatus, $validTransitions[$currentStatus] ?? []);
    }

    /**
     * Calculate response time for a request
     */
    private function calculateResponseTime(RequestEntry $request): ?int
    {
        if ($request->resolved_at) {
            return $request->created_at->diffInMinutes($request->resolved_at);
        }

        return null;
    }

    /**
     * Calculate priority score based on multiple factors
     */
    private function calculatePriorityScore(RequestEntry $request): int
    {
        $severityScores = ['low' => 1, 'medium' => 3, 'high' => 7, 'critical' => 10];
        $timeMultiplier = max(1, now()->diffInHours($request->created_at));
        
        return ($severityScores[$request->severity_level] ?? 1) * $timeMultiplier;
    }

    /**
     * Calculate estimated arrival time
     */
    private function calculateEstimatedArrival(RequestEntry $request): ?string
    {
        if ($request->status === 'assigned' && $request->assignments->isNotEmpty()) {
            // Base ETA calculation on distance and traffic conditions
            $baseMinutes = 15; // Base response time
            return now()->addMinutes($baseMinutes)->toISOString();
        }

        return null;
    }

    /**
     * Generate timeline for request
     */
    private function generateTimeline(RequestEntry $request): array
    {
        $timeline = [
            [
                'event' => 'Request Created',
                'timestamp' => $request->created_at->toISOString(),
                'status' => 'completed'
            ]
        ];

        foreach ($request->assignments as $assignment) {
            $timeline[] = [
                'event' => 'Resources Assigned',
                'timestamp' => $assignment->assigned_at->toISOString(),
                'details' => "Assigned to {$assignment->responder->user->name} from {$assignment->hospital->name}",
                'status' => 'completed'
            ];

            if ($assignment->completed_at) {
                $timeline[] = [
                    'event' => 'Assignment Completed',
                    'timestamp' => $assignment->completed_at->toISOString(),
                    'status' => 'completed'
                ];
            }
        }

        if ($request->resolved_at) {
            $timeline[] = [
                'event' => 'Request Resolved',
                'timestamp' => $request->resolved_at->toISOString(),
                'status' => 'completed'
            ];
        }

        return $timeline;
    }

    /**
     * Find nearby resources for a request
     */
    private function findNearbyResources(RequestEntry $request): array
    {
        $nearbyHospitals = Hospital::selectRaw("
            *, 
            (6371 * acos(
                cos(radians(?)) * cos(radians(latitude)) * 
                cos(radians(longitude) - radians(?)) + 
                sin(radians(?)) * sin(radians(latitude))
            )) AS distance
        ", [$request->latitude, $request->longitude, $request->latitude])
        ->having('distance', '<=', 25)
        ->where('status', 'active')
        ->with(['responders' => function($q) {
            $q->where('status', 'available');
        }, 'vehicles' => function($q) {
            $q->where('status', 'available');
        }])
        ->orderBy('distance')
        ->limit(5)
        ->get();

        return $nearbyHospitals->toArray();
    }

    /**
     * Get average response time
     */
    private function getAverageResponseTime(): float
    {
        return RequestEntry::whereNotNull('resolved_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (resolved_at - created_at))/60) as avg_minutes')
            ->value('avg_minutes') ?? 0;
    }

    /**
     * Get median response time
     */
    private function getMedianResponseTime(): float
    {
        // This is a simplified calculation - in production you'd use proper median SQL
        $times = RequestEntry::whereNotNull('resolved_at')
            ->selectRaw('EXTRACT(EPOCH FROM (resolved_at - created_at))/60 as minutes')
            ->pluck('minutes')
            ->sort();
        
        $count = $times->count();
        if ($count === 0) return 0;
        
        if ($count % 2 === 0) {
            return ($times[$count / 2 - 1] + $times[$count / 2]) / 2;
        }
        
        return $times[floor($count / 2)];
    }
}