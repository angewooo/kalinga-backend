<?php

namespace App\Http\Controllers\API;

use App\Models\Responder;
use App\Models\ResponderDetail;
use App\Models\Assignment;
use App\Models\User;
use App\Models\Hospital;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Emergency Responder Management Controller for Project Kalinga
 * Handles responder management, assignments, scheduling, and performance tracking
 */
class ResponderController extends BaseApiController
{
    /**
     * Get the model class for this controller
     */
    protected function getModel(): string
    {
        return Responder::class;
    }

    /**
     * Get validation rules for responder operations
     */
    protected function getValidationRules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
            'hospital_id' => 'required|integer|exists:hospitals,hospital_id',
            'specialization' => 'required|string|max:100|in:medical,fire_rescue,paramedic,security,logistics,general',
            'status' => 'sometimes|string|in:available,on_duty,off_duty,unavailable',
            'shift_start' => 'nullable|date_format:H:i',
            'shift_end' => 'nullable|date_format:H:i|after:shift_start',
        ];
    }

    /**
     * Get searchable fields
     */
    protected function getSearchableFields(): array
    {
        return ['user.name', 'specialization', 'hospital.name', 'status'];
    }

    /**
     * Get default relationships to load
     */
    protected function getDefaultRelations(): array
    {
        return ['user.profile', 'hospital', 'responderDetails'];
    }

    /**
     * Display a listing of responders
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate query parameters
            $request->validate($this->commonRules + [
                'status' => 'string|in:available,on_duty,off_duty,unavailable',
                'specialization' => 'string|in:medical,fire_rescue,paramedic,security,logistics,general',
                'hospital_id' => 'integer|exists:hospitals,hospital_id',
                'available_only' => 'boolean',
                'on_shift' => 'boolean',
                'certification_level' => 'string|in:basic,intermediate,advanced,expert'
            ]);

            $query = Responder::with($this->getDefaultRelations());

            // Apply standard filters
            $query = $this->applyFilters($query, $request, $this->getSearchableFields());

            // Status filter
            if ($status = $request->get('status')) {
                $query->where('status', $status);
            }

            // Specialization filter
            if ($specialization = $request->get('specialization')) {
                $query->where('specialization', $specialization);
            }

            // Hospital filter
            if ($hospitalId = $request->get('hospital_id')) {
                $query->where('hospital_id', $hospitalId);
            }

            // Available only filter
            if ($request->get('available_only')) {
                $query->where('status', 'available')
                      ->where(function($q) {
                          $currentTime = now()->format('H:i');
                          $q->where(function($subQ) use ($currentTime) {
                              $subQ->where('shift_start', '<=', $currentTime)
                                   ->where('shift_end', '>=', $currentTime);
                          })
                          ->orWhereNull('shift_start');
                      });
            }

            // On shift filter
            if ($request->get('on_shift')) {
                $currentTime = now()->format('H:i');
                $query->where('shift_start', '<=', $currentTime)
                      ->where('shift_end', '>=', $currentTime)
                      ->whereIn('status', ['available', 'on_duty']);
            }

            // Certification level filter (from responder details)
            if ($certificationLevel = $request->get('certification_level')) {
                $query->whereHas('responderDetails', function($q) use ($certificationLevel) {
                    $q->where('certification_level', $certificationLevel);
                });
            }

            // Pagination
            $params = $this->getPaginationParams($request);
            $responders = $query->paginate($params['per_page']);

            // Add calculated fields
            $responders->getCollection()->transform(function ($responder) {
                $responder->active_assignments = $this->getActiveAssignmentsCount($responder);
                $responder->on_shift = $this->isOnShift($responder);
                $responder->performance_score = $this->calculatePerformanceScore($responder);
                $responder->availability_status = $this->getAvailabilityStatus($responder);
                return $responder;
            });

            $this->logActivity('Responders listed', [
                'total' => $responders->total(),
                'filters' => $request->only(['status', 'specialization', 'hospital_id'])
            ]);

            return $this->paginatedResponse($responders, 'Responders retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'listing responders');
        }
    }

    /**
     * Store a newly created responder
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('create-responders')) {
                return $this->forbiddenResponse('You do not have permission to create responders.');
            }

            $validated = $request->validate($this->getValidationRules() + [
                // Responder detail fields
                'certification_level' => 'sometimes|string|in:basic,intermediate,advanced,expert',
                'certifications' => 'sometimes|array',
                'certifications.*' => 'string|max:100',
                'experience_years' => 'sometimes|integer|min:0|max:50',
                'emergency_contact' => 'sometimes|string|max:20',
                'medical_conditions' => 'sometimes|string|max:500',
                'equipment_assigned' => 'sometimes|array',
                'equipment_assigned.*' => 'string|max:100'
            ]);

            // Check if user is already a responder
            if (Responder::where('user_id', $validated['user_id'])->exists()) {
                return $this->errorResponse('User is already registered as a responder.', 400);
            }

            // Verify user has appropriate role
            $user = User::findOrFail($validated['user_id']);
            if (!$user->hasAnyRole(['responder', 'admin'])) {
                return $this->errorResponse('User must have responder or admin role.', 400);
            }

            DB::beginTransaction();

            // Create responder
            $responder = Responder::create([
                'user_id' => $validated['user_id'],
                'hospital_id' => $validated['hospital_id'],
                'specialization' => $validated['specialization'],
                'status' => $validated['status'] ?? 'available',
                'shift_start' => $validated['shift_start'] ?? null,
                'shift_end' => $validated['shift_end'] ?? null,
            ]);

            // Create responder details if provided
            if ($this->hasResponderDetailData($validated)) {
                ResponderDetail::create([
                    'user_id' => $validated['user_id'],
                    'certification_level' => $validated['certification_level'] ?? 'basic',
                    'certifications' => $validated['certifications'] ?? [],
                    'experience_years' => $validated['experience_years'] ?? 0,
                    'emergency_contact' => $validated['emergency_contact'] ?? null,
                    'medical_conditions' => $validated['medical_conditions'] ?? null,
                    'equipment_assigned' => $validated['equipment_assigned'] ?? []
                ]);
            }

            DB::commit();

            $responder->load($this->getDefaultRelations());

            $this->logActivity('Responder created', [
                'responder_id' => $responder->responder_id,
                'user_id' => $responder->user_id,
                'hospital_id' => $responder->hospital_id,
                'specialization' => $responder->specialization
            ]);

            return $this->createdResponse($responder, 'Responder created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'creating responder');
        }
    }

    /**
     * Display the specified responder
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $responder = Responder::with([
                'user.profile',
                'hospital',
                'responderDetails',
                'assignments' => function($q) {
                    $q->with(['requestEntry', 'hospital', 'vehicle'])
                      ->orderBy('assigned_at', 'desc')
                      ->limit(10);
                }
            ])->findOrFail($id);

            // Add calculated metrics
            $responder->active_assignments = $this->getActiveAssignmentsCount($responder);
            $responder->on_shift = $this->isOnShift($responder);
            $responder->performance_metrics = $this->getDetailedPerformanceMetrics($responder);
            $responder->availability_forecast = $this->getAvailabilityForecast($responder);
            $responder->recent_activity = $this->getRecentActivity($responder);

            $this->logActivity('Responder viewed', ['responder_id' => $responder->responder_id]);

            return $this->successResponse($responder, 'Responder retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving responder');
        }
    }

    /**
     * Update the specified responder
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('update-responders')) {
                return $this->forbiddenResponse('You do not have permission to update responders.');
            }

            $responder = Responder::findOrFail($id);

            // Make fields optional for updates
            $rules = $this->getValidationRules();
            foreach ($rules as $key => $rule) {
                $rules[$key] = str_replace('required|', 'sometimes|', $rule);
            }

            $validated = $request->validate($rules + [
                // Responder detail fields for update
                'certification_level' => 'sometimes|string|in:basic,intermediate,advanced,expert',
                'certifications' => 'sometimes|array',
                'certifications.*' => 'string|max:100',
                'experience_years' => 'sometimes|integer|min:0|max:50',
                'emergency_contact' => 'sometimes|string|max:20',
                'medical_conditions' => 'sometimes|string|max:500',
                'equipment_assigned' => 'sometimes|array',
                'equipment_assigned.*' => 'string|max:100'
            ]);

            DB::beginTransaction();

            // Update responder data
            $responderData = collect($validated)->only([
                'hospital_id', 'specialization', 'status', 'shift_start', 'shift_end'
            ])->filter()->toArray();

            if (!empty($responderData)) {
                $responder->update($responderData);
            }

            // Update responder details
            $detailData = collect($validated)->only([
                'certification_level', 'certifications', 'experience_years', 
                'emergency_contact', 'medical_conditions', 'equipment_assigned'
            ])->filter()->toArray();

            if (!empty($detailData)) {
                $responder->responderDetails()->updateOrCreate(
                    ['user_id' => $responder->user_id],
                    $detailData
                );
            }

            DB::commit();

            $responder->load($this->getDefaultRelations());

            $this->logActivity('Responder updated', [
                'responder_id' => $responder->responder_id,
                'updated_fields' => array_keys($validated)
            ]);

            return $this->updatedResponse($responder, 'Responder updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'updating responder');
        }
    }

    /**
     * Remove the specified responder
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            if (!$this->userCan('delete-responders')) {
                return $this->forbiddenResponse('You do not have permission to delete responders.');
            }

            $responder = Responder::findOrFail($id);

            // Check for active assignments
            if ($this->hasActiveAssignments($responder)) {
                return $this->errorResponse(
                    'Responder has active assignments and cannot be deleted.',
                    409
                );
            }

            $responder->delete();

            $this->logActivity('Responder deleted', ['responder_id' => $responder->responder_id]);

            return $this->deletedResponse('Responder deleted successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting responder');
        }
    }

    /**
     * Get responder assignments
     *
     * @param int $id
     * @return JsonResponse
     */
    public function assignments(int $id): JsonResponse
    {
        try {
            $responder = Responder::findOrFail($id);

            $assignments = $responder->assignments()
                ->with(['requestEntry', 'hospital', 'vehicle'])
                ->orderBy('assigned_at', 'desc')
                ->paginate(20);

            // Add assignment metrics
            $assignments->getCollection()->transform(function ($assignment) {
                $assignment->duration_minutes = $this->calculateAssignmentDuration($assignment);
                $assignment->response_time_minutes = $this->calculateResponseTime($assignment);
                $assignment->completion_status = $this->getCompletionStatus($assignment);
                return $assignment;
            });

            return $this->paginatedResponse($assignments, 'Responder assignments retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving responder assignments');
        }
    }

    /**
     * Get responder performance metrics
     *
     * @param int $id
     * @return JsonResponse
     */
    public function performance(int $id): JsonResponse
    {
        try {
            $responder = Responder::findOrFail($id);
            $performance = $this->getDetailedPerformanceMetrics($responder);

            return $this->successResponse($performance, 'Responder performance metrics retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving responder performance');
        }
    }

    /**
     * Update responder status
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('update-responder-status')) {
                return $this->forbiddenResponse('You do not have permission to update responder status.');
            }

            $responder = Responder::findOrFail($id);

            $validated = $request->validate([
                'status' => 'required|string|in:available,on_duty,off_duty,unavailable',
                'reason' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:500'
            ]);

            // Validate status transition
            if (!$this->isValidStatusTransition($responder->status, $validated['status'])) {
                return $this->errorResponse(
                    "Invalid status transition from {$responder->status} to {$validated['status']}.",
                    400
                );
            }

            $oldStatus = $responder->status;
            $responder->update([
                'status' => $validated['status'],
                'last_updated' => now()
            ]);

            $this->logActivity('Responder status updated', [
                'responder_id' => $responder->responder_id,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
                'reason' => $validated['reason'] ?? null
            ]);

            return $this->successResponse(
                $responder->load($this->getDefaultRelations()),
                'Responder status updated successfully.'
            );

        } catch (\Exception $e) {
            return $this->handleException($e, 'updating responder status');
        }
    }

    /**
     * Get responder schedule
     *
     * @param int $id
     * @return JsonResponse
     */
    public function schedule(int $id): JsonResponse
    {
        try {
            $responder = Responder::findOrFail($id);

            $schedule = [
                'responder_id' => $responder->responder_id,
                'current_shift' => [
                    'start' => $responder->shift_start,
                    'end' => $responder->shift_end,
                    'is_on_shift' => $this->isOnShift($responder)
                ],
                'upcoming_assignments' => $this->getUpcomingAssignments($responder),
                'weekly_schedule' => $this->getWeeklySchedule($responder),
                'availability_next_7_days' => $this->getAvailabilityForecast($responder)
            ];

            return $this->successResponse($schedule, 'Responder schedule retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving responder schedule');
        }
    }

    /**
     * Get available responders
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function available(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'specialization' => 'sometimes|string|in:medical,fire_rescue,paramedic,security,logistics,general',
                'hospital_id' => 'sometimes|integer|exists:hospitals,hospital_id',
                'within_radius_km' => 'sometimes|numeric|min:1|max:200',
                'latitude' => 'sometimes|numeric|between:-90,90',
                'longitude' => 'sometimes|numeric|between:-180,180',
                'certification_level' => 'sometimes|string|in:basic,intermediate,advanced,expert'
            ]);

            $query = Responder::with($this->getDefaultRelations())
                ->where('status', 'available');

            // Check if currently on shift
            $currentTime = now()->format('H:i');
            $query->where(function($q) use ($currentTime) {
                $q->where(function($subQ) use ($currentTime) {
                    $subQ->where('shift_start', '<=', $currentTime)
                         ->where('shift_end', '>=', $currentTime);
                })
                ->orWhereNull('shift_start');
            });

            // Specialization filter
            if (isset($validated['specialization'])) {
                $query->where('specialization', $validated['specialization']);
            }

            // Hospital filter
            if (isset($validated['hospital_id'])) {
                $query->where('hospital_id', $validated['hospital_id']);
            }

            // Certification level filter
            if (isset($validated['certification_level'])) {
                $query->whereHas('responderDetails', function($q) use ($validated) {
                    $q->where('certification_level', $validated['certification_level']);
                });
            }

            // Location-based filtering
            if (isset($validated['latitude'], $validated['longitude'], $validated['within_radius_km'])) {
                $query->whereHas('hospital', function($q) use ($validated) {
                    $q->selectRaw("
                        *, 
                        (6371 * acos(
                            cos(radians(?)) * cos(radians(latitude)) * 
                            cos(radians(longitude) - radians(?)) + 
                            sin(radians(?)) * sin(radians(latitude))
                        )) AS distance
                    ", [$validated['latitude'], $validated['longitude'], $validated['latitude']])
                    ->having('distance', '<=', $validated['within_radius_km']);
                });
            }

            $responders = $query->orderBy('last_updated')->paginate(20);

            // Add availability metrics
            $responders->getCollection()->transform(function ($responder) {
                $responder->response_readiness = $this->calculateResponseReadiness($responder);
                $responder->estimated_availability_duration = $this->getEstimatedAvailabilityDuration($responder);
                return $responder;
            });

            return $this->paginatedResponse($responders, 'Available responders retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving available responders');
        }
    }

    /**
     * Get responders by hospital
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function byHospital(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'hospital_id' => 'required|integer|exists:hospitals,hospital_id',
                'include_inactive' => 'sometimes|boolean'
            ]);

            $query = Responder::with($this->getDefaultRelations())
                ->where('hospital_id', $validated['hospital_id']);

            if (!($validated['include_inactive'] ?? false)) {
                $query->whereIn('status', ['available', 'on_duty', 'off_duty']);
            }

            $responders = $query->get()->groupBy('specialization');

            $summary = [
                'hospital_id' => $validated['hospital_id'],
                'total_responders' => $query->count(),
                'by_specialization' => $responders->map(function($group) {
                    return [
                        'count' => $group->count(),
                        'available' => $group->where('status', 'available')->count(),
                        'on_duty' => $group->where('status', 'on_duty')->count()
                    ];
                }),
                'responders' => $responders
            ];

            return $this->successResponse($summary, 'Hospital responders retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving hospital responders');
        }
    }

    /**
     * Get responders by specialization
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bySpecialization(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'specialization' => 'required|string|in:medical,fire_rescue,paramedic,security,logistics,general',
                'status' => 'sometimes|string|in:available,on_duty,off_duty,unavailable'
            ]);

            $query = Responder::with($this->getDefaultRelations())
                ->where('specialization', $validated['specialization']);

            if (isset($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            $responders = $query->paginate(20);

            return $this->paginatedResponse($responders, 'Specialized responders retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving specialized responders');
        }
    }

    /**
     * Bulk assign responders to requests
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkAssign(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('bulk-assign-responders')) {
                return $this->forbiddenResponse('You do not have permission to bulk assign responders.');
            }

            $validated = $request->validate([
                'assignments' => 'required|array|min:1|max:50',
                'assignments.*.responder_id' => 'required|integer|exists:responders,responder_id',
                'assignments.*.request_id' => 'required|integer|exists:requests,request_id',
                'assignments.*.hospital_id' => 'required|integer|exists:hospitals,hospital_id',
                'assignments.*.vehicle_id' => 'nullable|integer|exists:vehicles,vehicle_id',
                'assignments.*.notes' => 'nullable|string|max:500'
            ]);

            DB::beginTransaction();

            $results = [];
            foreach ($validated['assignments'] as $assignmentData) {
                try {
                    // Check responder availability
                    $responder = Responder::findOrFail($assignmentData['responder_id']);
                    if ($responder->status !== 'available') {
                        $results[] = [
                            'responder_id' => $assignmentData['responder_id'],
                            'request_id' => $assignmentData['request_id'],
                            'status' => 'failed',
                            'error' => 'Responder not available'
                        ];
                        continue;
                    }

                    // Create assignment
                    $assignment = Assignment::create([
                        'request_id' => $assignmentData['request_id'],
                        'responder_id' => $assignmentData['responder_id'],
                        'hospital_id' => $assignmentData['hospital_id'],
                        'vehicle_id' => $assignmentData['vehicle_id'] ?? null,
                        'status' => 'assigned',
                        'assigned_at' => now(),
                        'notes' => $assignmentData['notes'] ?? null
                    ]);

                    // Update responder status
                    $responder->update(['status' => 'on_duty']);

                    $results[] = [
                        'responder_id' => $assignmentData['responder_id'],
                        'request_id' => $assignmentData['request_id'],
                        'assignment_id' => $assignment->assignment_id,
                        'status' => 'assigned'
                    ];

                } catch (\Exception $e) {
                    $results[] = [
                        'responder_id' => $assignmentData['responder_id'],
                        'request_id' => $assignmentData['request_id'],
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            $successful = collect($results)->where('status', 'assigned')->count();
            $failed = collect($results)->where('status', 'failed')->count();

            $this->logActivity('Bulk responder assignment', [
                'total_assignments' => count($validated['assignments']),
                'successful' => $successful,
                'failed' => $failed
            ]);

            return $this->successResponse([
                'results' => $results,
                'summary' => [
                    'total' => count($validated['assignments']),
                    'successful' => $successful,
                    'failed' => $failed
                ]
            ], "Bulk assignment completed. {$successful} successful, {$failed} failed.");

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'bulk assigning responders');
        }
    }

    /**
     * Helper Methods
     */

    /**
     * Check if responder has detail data
     */
    private function hasResponderDetailData(array $data): bool
    {
        $detailFields = [
            'certification_level', 'certifications', 'experience_years',
            'emergency_contact', 'medical_conditions', 'equipment_assigned'
        ];

        return collect($detailFields)->some(fn($field) => isset($data[$field]));
    }

    /**
     * Get active assignments count
     */
    private function getActiveAssignmentsCount(Responder $responder): int
    {
        return $responder->assignments()
            ->whereIn('status', ['assigned', 'in_progress'])
            ->count();
    }

    /**
     * Check if responder is currently on shift
     */
    private function isOnShift(Responder $responder): bool
    {
        if (!$responder->shift_start || !$responder->shift_end) {
            return true; // No shift restrictions
        }

        $currentTime = now()->format('H:i');
        return $currentTime >= $responder->shift_start && $currentTime <= $responder->shift_end;
    }

    /**
     * Calculate performance score
     */
    private function calculatePerformanceScore(Responder $responder): float
    {
        $assignments = $responder->assignments()->get();
        
        if ($assignments->count() === 0) {
            return 0;
        }

        $completedCount = $assignments->where('status', 'completed')->count();
        $completionRate = ($completedCount / $assignments->count()) * 100;

        // Factor in response times, completion rates, etc.
        $avgResponseTime = $assignments->where('status', 'completed')
            ->avg(function($assignment) {
                return $assignment->assigned_at->diffInMinutes($assignment->completed_at ?? now());
            }) ?? 60;

        // Simple scoring algorithm
        $responseScore = max(0, 100 - ($avgResponseTime / 2));
        
        return round(($completionRate + $responseScore) / 2, 1);
    }

    /**
     * Get availability status
     */
    private function getAvailabilityStatus(Responder $responder): string
    {
        if ($responder->status === 'unavailable') {
            return 'unavailable';
        }

        if (!$this->isOnShift($responder)) {
            return 'off_shift';
        }

        if ($this->getActiveAssignmentsCount($responder) > 0) {
            return 'assigned';
        }

        return $responder->status === 'available' ? 'available' : $responder->status;
    }

    /**
     * Get detailed performance metrics
     */
    private function getDetailedPerformanceMetrics(Responder $responder): array
    {
        $assignments = $responder->assignments;
        $completed = $assignments->where('status', 'completed');

        return [
            'total_assignments' => $assignments->count(),
            'completed_assignments' => $completed->count(),
            'completion_rate' => $assignments->count() > 0 
                ? round(($completed->count() / $assignments->count()) * 100, 2)
                : 0,
            'average_response_time_minutes' => $completed->avg(function($assignment) {
                return $assignment->assigned_at->diffInMinutes($assignment->completed_at ?? now());
            }) ?? 0,
            'specialization_efficiency' => $this->calculateSpecializationEfficiency($responder),
            'performance_trend' => $this->getPerformanceTrend($responder),
            'last_30_days' => [
                'assignments' => $assignments->where('assigned_at', '>=', now()->subDays(30))->count(),
                'completion_rate' => $this->getRecentCompletionRate($responder, 30)
            ]
        ];
    }

    /**
     * Validate status transitions
     */
    private function isValidStatusTransition(string $currentStatus, string $newStatus): bool
    {
        $validTransitions = [
            'available' => ['on_duty', 'off_duty', 'unavailable'],
            'on_duty' => ['available', 'off_duty'],
            'off_duty' => ['available', 'unavailable'],
            'unavailable' => ['available', 'off_duty']
        ];

        return in_array($newStatus, $validTransitions[$currentStatus] ?? []);
    }

    /**
     * Check if responder has active assignments
     */
    private function hasActiveAssignments(Responder $responder): bool
    {
        return $responder->assignments()
            ->whereIn('status', ['assigned', 'in_progress'])
            ->exists();
    }

    /**
     * Calculate assignment duration
     */
    private function calculateAssignmentDuration(Assignment $assignment): ?int
    {
        if ($assignment->completed_at) {
            return $assignment->assigned_at->diffInMinutes($assignment->completed_at);
        }
        return null;
    }

    /**
     * Calculate response time
     */
    private function calculateResponseTime(Assignment $assignment): ?int
    {
        // This would calculate time from request creation to assignment
        if ($assignment->requestEntry) {
            return $assignment->requestEntry->created_at->diffInMinutes($assignment->assigned_at);
        }
        return null;
    }

    /**
     * Get completion status
     */
    private function getCompletionStatus(Assignment $assignment): string
    {
        return $assignment->status;
    }

    /**
     * Additional helper methods for comprehensive functionality
     */
    private function getAvailabilityForecast(Responder $responder): array
    {
        // Mock forecast for next 7 days
        return array_map(function($day) {
            return [
                'date' => now()->addDays($day)->format('Y-m-d'),
                'availability' => rand(6, 12), // hours available
                'scheduled_assignments' => rand(0, 3)
            ];
        }, range(0, 6));
    }

    private function getUpcomingAssignments(Responder $responder): array
    {
        // This would return actual upcoming assignments
        return [];
    }

    private function getWeeklySchedule(Responder $responder): array
    {
        return [
            'monday' => ['start' => $responder->shift_start, 'end' => $responder->shift_end],
            'tuesday' => ['start' => $responder->shift_start, 'end' => $responder->shift_end],
            'wednesday' => ['start' => $responder->shift_start, 'end' => $responder->shift_end],
            'thursday' => ['start' => $responder->shift_start, 'end' => $responder->shift_end],
            'friday' => ['start' => $responder->shift_start, 'end' => $responder->shift_end],
            'saturday' => ['start' => null, 'end' => null],
            'sunday' => ['start' => null, 'end' => null]
        ];
    }

    private function getRecentActivity(Responder $responder): array
    {
        return $responder->assignments()
            ->with('requestEntry')
            ->orderBy('assigned_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function($assignment) {
                return [
                    'date' => $assignment->assigned_at,
                    'activity' => 'Assignment to request #' . $assignment->request_id,
                    'status' => $assignment->status
                ];
            })
            ->toArray();
    }

    private function calculateResponseReadiness(Responder $responder): float
    {
        $baseScore = 80;
        
        // On shift bonus
        if ($this->isOnShift($responder)) {
            $baseScore += 15;
        }
        
        // No active assignments bonus
        if ($this->getActiveAssignmentsCount($responder) === 0) {
            $baseScore += 10;
        }
        
        // Recent performance factor
        $performanceScore = $this->calculatePerformanceScore($responder);
        $baseScore += ($performanceScore - 50) * 0.1;
        
        return min(100, max(0, $baseScore));
    }

    private function getEstimatedAvailabilityDuration(Responder $responder): string
    {
        if (!$this->isOnShift($responder)) {
            return 'Off shift';
        }
        
        if (!$responder->shift_end) {
            return 'Unlimited';
        }
        
        $remainingMinutes = now()->diffInMinutes($responder->shift_end, false);
        return $remainingMinutes > 0 ? "{$remainingMinutes} minutes" : 'Shift ended';
    }

    private function calculateSpecializationEfficiency(Responder $responder): float
    {
        // Mock calculation based on specialization
        return 85.5;
    }

    private function getPerformanceTrend(Responder $responder): string
    {
        // Mock trend calculation
        return 'improving';
    }

    private function getRecentCompletionRate(Responder $responder, int $days): float
    {
        $recent = $responder->assignments()
            ->where('assigned_at', '>=', now()->subDays($days))
            ->get();
            
        if ($recent->count() === 0) return 0;
        
        return ($recent->where('status', 'completed')->count() / $recent->count()) * 100;
    }
}