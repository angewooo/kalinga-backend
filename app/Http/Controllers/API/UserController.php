<?php

namespace App\Http\Controllers\API;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * User Management Controller for Project Kalinga
 * Handles user CRUD operations, profile management, and role assignments
 */
class UserController extends BaseApiController
{
    /**
     * Get the model class for this controller
     */
    protected function getModel(): string
    {
        return User::class;
    }

    /**
     * Get validation rules for user operations
     */
    protected function getValidationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'account_status' => 'sometimes|string|in:active,inactive,suspended,pending',
        ];
    }

    /**
     * Get searchable fields
     */
    protected function getSearchableFields(): array
    {
        return ['name', 'email', 'profile.first_name', 'profile.last_name', 'profile.contact_number'];
    }

    /**
     * Get default relationships to load
     */
    protected function getDefaultRelations(): array
    {
        return ['profile', 'roles', 'permissions'];
    }

    /**
     * Display a listing of users
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate query parameters
            $request->validate($this->commonRules + [
                'status' => 'string|in:active,inactive,suspended,pending',
                'role' => 'string|exists:roles,name',
                'hospital_id' => 'integer|exists:hospitals,hospital_id'
            ]);

            $query = User::with($this->getDefaultRelations());

            // Apply filters
            $query = $this->applyFilters($query, $request, $this->getSearchableFields());

            // Role-based filtering
            if ($role = $request->get('role')) {
                $query->whereHas('roles', function($q) use ($role) {
                    $q->where('name', $role);
                });
            }

            // Hospital-based filtering (for responders/staff)
            if ($hospitalId = $request->get('hospital_id')) {
                $query->whereHas('responder', function($q) use ($hospitalId) {
                    $q->where('hospital_id', $hospitalId);
                });
            }

            // Pagination
            $params = $this->getPaginationParams($request);
            $users = $query->paginate($params['per_page']);

            $this->logActivity('Users listed', [
                'total' => $users->total(),
                'filters' => $request->only(['search', 'status', 'role'])
            ]);

            return $this->paginatedResponse($users, 'Users retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'listing users');
        }
    }

    /**
     * Store a newly created user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Check permissions
            if (!$this->userCan('create-users')) {
                return $this->forbiddenResponse('You do not have permission to create users.');
            }

            // Validate input
            $validated = $request->validate($this->getValidationRules() + [
                'roles' => 'sometimes|array',
                'roles.*' => 'string|exists:roles,name',
                
                // Profile fields
                'profile.first_name' => 'sometimes|string|max:100',
                'profile.last_name' => 'sometimes|string|max:100',
                'profile.contact_number' => 'sometimes|string|max:20',
                'profile.address' => 'sometimes|string|max:500',
                'profile.date_of_birth' => 'sometimes|date',
                'profile.gender' => 'sometimes|string|in:male,female,other',
                'profile.emergency_contact' => 'sometimes|string|max:20',
            ]);

            DB::beginTransaction();

            // Create user
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'account_status' => $validated['account_status'] ?? 'active',
                'qr_code' => $this->generateQrCode(),
            ]);

            // Create profile if profile data provided
            if (isset($validated['profile'])) {
                UserProfile::create(array_merge($validated['profile'], [
                    'user_id' => $user->id
                ]));
            }

            // Assign roles if provided
            if (isset($validated['roles'])) {
                $user->assignRole($validated['roles']);
            }

            DB::commit();

            // Load relationships for response
            $user->load($this->getDefaultRelations());

            $this->logActivity('User created', [
                'user_id' => $user->id,
                'email' => $user->email,
                'roles' => $validated['roles'] ?? []
            ]);

            return $this->createdResponse($user, 'User created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'creating user');
        }
    }

    /**
     * Display the specified user
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = $this->getResourceWithRelations($id, $this->getDefaultRelations());

            // Check if user can view this profile (own profile or admin permission)
            if (!$this->canViewUser($user)) {
                return $this->forbiddenResponse('You do not have permission to view this user.');
            }

            $this->logActivity('User viewed', ['viewed_user_id' => $user->id]);

            return $this->successResponse($user, 'User retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving user');
        }
    }

    /**
     * Update the specified user
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            // Check permissions
            if (!$this->canUpdateUser($user)) {
                return $this->forbiddenResponse('You do not have permission to update this user.');
            }

            // Validate input (make email unique except for current user)
            $rules = $this->getValidationRules();
            $rules['email'] = ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)];
            $rules['password'] = 'sometimes|string|min:8|confirmed';

            $validated = $request->validate($rules + [
                'roles' => 'sometimes|array',
                'roles.*' => 'string|exists:roles,name',
                
                // Profile fields
                'profile.first_name' => 'sometimes|string|max:100',
                'profile.last_name' => 'sometimes|string|max:100',
                'profile.contact_number' => 'sometimes|string|max:20',
                'profile.address' => 'sometimes|string|max:500',
                'profile.date_of_birth' => 'sometimes|date',
                'profile.gender' => 'sometimes|string|in:male,female,other',
                'profile.emergency_contact' => 'sometimes|string|max:20',
            ]);

            DB::beginTransaction();

            // Update user data
            $updateData = collect($validated)->except(['password', 'roles', 'profile'])->toArray();
            
            if (isset($validated['password'])) {
                $updateData['password'] = Hash::make($validated['password']);
            }

            $user->update($updateData);

            // Update profile if provided
            if (isset($validated['profile'])) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $validated['profile']
                );
            }

            // Update roles if provided and user has permission
            if (isset($validated['roles']) && $this->userCan('assign-roles')) {
                $user->syncRoles($validated['roles']);
            }

            DB::commit();

            // Load relationships for response
            $user->load($this->getDefaultRelations());

            $this->logActivity('User updated', [
                'user_id' => $user->id,
                'updated_fields' => array_keys($updateData),
                'roles_updated' => isset($validated['roles'])
            ]);

            return $this->updatedResponse($user, 'User updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'updating user');
        }
    }

    /**
     * Remove the specified user
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            // Check permissions
            if (!$this->userCan('delete-users')) {
                return $this->forbiddenResponse('You do not have permission to delete users.');
            }

            // Prevent self-deletion
            if ($user->id === auth('sanctum')->id()) {
                return $this->errorResponse('You cannot delete your own account.', 400);
            }

            // Check if user has critical assignments
            if ($this->userHasCriticalAssignments($user)) {
                return $this->errorResponse('User has active critical assignments and cannot be deleted.', 409);
            }

            $user->delete();

            $this->logActivity('User deleted', ['deleted_user_id' => $user->id]);

            return $this->deletedResponse('User deleted successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting user');
        }
    }

    /**
     * Get user profile information
     *
     * @param int $id
     * @return JsonResponse
     */
    public function profile(int $id): JsonResponse
    {
        try {
            $user = User::with(['profile', 'responderDetails', 'adminDetails'])->findOrFail($id);

            if (!$this->canViewUser($user)) {
                return $this->forbiddenResponse('You do not have permission to view this profile.');
            }

            $profileData = [
                'user' => $user->only(['id', 'name', 'email', 'account_status', 'last_active']),
                'profile' => $user->profile,
                'responder_details' => $user->responderDetails,
                'admin_details' => $user->adminDetails,
                'roles' => $user->roles->pluck('name'),
                'permissions' => $user->getAllPermissions()->pluck('name')
            ];

            return $this->successResponse($profileData, 'Profile retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving profile');
        }
    }

    /**
     * Update user profile
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateProfile(Request $request, int $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            if (!$this->canUpdateUser($user)) {
                return $this->forbiddenResponse('You do not have permission to update this profile.');
            }

            $validated = $request->validate([
                'first_name' => 'sometimes|string|max:100',
                'last_name' => 'sometimes|string|max:100',
                'contact_number' => 'sometimes|string|max:20',
                'address' => 'sometimes|string|max:500',
                'date_of_birth' => 'sometimes|date',
                'gender' => 'sometimes|string|in:male,female,other',
                'emergency_contact' => 'sometimes|string|max:20',
            ]);

            $profile = $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                $validated
            );

            $this->logActivity('Profile updated', ['user_id' => $user->id]);

            return $this->updatedResponse($profile, 'Profile updated successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'updating profile');
        }
    }

    /**
     * Get user roles
     *
     * @param int $id
     * @return JsonResponse
     */
    public function roles(int $id): JsonResponse
    {
        try {
            $user = User::with(['roles.permissions'])->findOrFail($id);

            if (!$this->canViewUser($user)) {
                return $this->forbiddenResponse('You do not have permission to view this user\'s roles.');
            }

            return $this->successResponse([
                'roles' => $user->roles,
                'permissions' => $user->getAllPermissions()
            ], 'User roles retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving user roles');
        }
    }

    /**
     * Update user roles
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateRoles(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('assign-roles')) {
                return $this->forbiddenResponse('You do not have permission to assign roles.');
            }

            $user = User::findOrFail($id);

            $validated = $request->validate([
                'roles' => 'required|array',
                'roles.*' => 'string|exists:roles,name'
            ]);

            $user->syncRoles($validated['roles']);

            $this->logActivity('Roles updated', [
                'user_id' => $user->id,
                'roles' => $validated['roles']
            ]);

            return $this->successResponse([
                'roles' => $user->fresh()->roles,
                'permissions' => $user->getAllPermissions()
            ], 'User roles updated successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'updating user roles');
        }
    }

    /**
     * Get user permissions
     *
     * @param int $id
     * @return JsonResponse
     */
    public function permissions(int $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            if (!$this->canViewUser($user)) {
                return $this->forbiddenResponse('You do not have permission to view this user\'s permissions.');
            }

            return $this->successResponse([
                'direct_permissions' => $user->permissions,
                'role_permissions' => $user->getPermissionsViaRoles(),
                'all_permissions' => $user->getAllPermissions()
            ], 'User permissions retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving user permissions');
        }
    }

    /**
     * Helper Methods
     */

    /**
     * Check if current user can view the specified user
     */
    private function canViewUser(User $user): bool
    {
        $currentUser = auth('sanctum')->user();
        
        // Users can view their own profile
        if ($currentUser && $currentUser->id === $user->id) {
            return true;
        }

        // Check admin permissions
        return $this->userCan('view-users');
    }

    /**
     * Check if current user can update the specified user
     */
    private function canUpdateUser(User $user): bool
    {
        $currentUser = auth('sanctum')->user();
        
        // Users can update their own profile (limited fields)
        if ($currentUser && $currentUser->id === $user->id) {
            return true;
        }

        // Check admin permissions
        return $this->userCan('update-users');
    }

    /**
     * Check if user has critical assignments that prevent deletion
     */
    private function userHasCriticalAssignments(User $user): bool
    {
        if ($user->responder) {
            return $user->responder->assignments()
                ->whereIn('status', ['assigned', 'in_progress'])
                ->exists();
        }

        return false;
    }

    /**
     * Generate QR code for user
     */
    private function generateQrCode(): string
    {
        return 'QR_' . uniqid() . '_' . time();
    }
}