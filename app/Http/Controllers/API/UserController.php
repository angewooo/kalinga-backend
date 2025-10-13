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
    protected function getModel(): string
    {
        return User::class;
    }

    protected function getValidationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'account_status' => 'sometimes|string|in:active,inactive,suspended,pending',
        ];
    }

    protected function getSearchableFields(): array
    {
        return ['name', 'email', 'profile.first_name', 'profile.last_name', 'profile.contact_number'];
    }

    protected function getDefaultRelations(): array
    {
        return ['profile', 'roles', 'permissions'];
    }

    /**
     * Display list of users
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'status' => 'sometimes|string|in:active,inactive,suspended,pending',
                'role' => 'sometimes|string|exists:roles,name',
                'hospital_id' => 'sometimes|integer|exists:hospitals,hospital_id',
            ]);

            $query = User::with($this->getDefaultRelations());
            $query = $this->applyFilters($query, $request, $this->getSearchableFields());

            if ($role = $request->get('role')) {
                $query->whereHas('roles', fn($q) => $q->where('name', $role));
            }

            if ($hospitalId = $request->get('hospital_id')) {
                $query->whereHas('responder', fn($q) => $q->where('hospital_id', $hospitalId));
            }

            $params = $this->getPaginationParams($request);
            $users = $query->paginate($params['per_page']);

            $this->logActivity('Users listed', [
                'total' => $users->total(),
                'filters' => $request->only(['search', 'status', 'role']),
            ]);

            return $this->paginatedResponse($users, 'Users retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'listing users');
        }
    }

    /**
     * Store a newly created user
     */
  public function store(Request $request): JsonResponse
{
    try {
        // 🔒 Permission check
        $authUser = auth()->user();
        if (!$authUser || !$authUser->can('create users')) {
            return $this->forbiddenResponse('You do not have permission to create users.');
        }

        // ✅ Validation (strict) - Include profile fields at top level for better handling
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'account_status' => 'sometimes|string|in:active,inactive,suspended,pending',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|exists:roles,name',

            // Profile fields - accept both nested and flat
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'contact_number' => 'sometimes|string|max:20',
            'gender' => 'sometimes|string|max:10',
            'address' => 'sometimes|string',
            'home_address' => 'sometimes|string',
            'emergency_contact_name' => 'sometimes|string|max:100',
            'emergency_contact_number' => 'sometimes|string|max:20',
            'blood_type' => 'sometimes|string|max:5',
            'allergies' => 'sometimes|string',
            'medical_conditions' => 'sometimes|string',
            
            // Also accept nested profile
            'profile.first_name' => 'sometimes|string|max:100',
            'profile.last_name' => 'sometimes|string|max:100',
            'profile.contact_number' => 'sometimes|string|max:20',
            'profile.gender' => 'sometimes|string|max:10',
            'profile.address' => 'sometimes|string',
            'profile.home_address' => 'sometimes|string',
            'profile.emergency_contact_name' => 'sometimes|string|max:100',
            'profile.emergency_contact_number' => 'sometimes|string|max:20',
            'profile.blood_type' => 'sometimes|string|max:5',
            'profile.allergies' => 'sometimes|string',
            'profile.medical_conditions' => 'sometimes|string',
        ]);

        DB::beginTransaction();

        // 👤 Create user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'account_status' => $validated['account_status'] ?? 'active',
            'qr_code' => $this->generateQrCode(),
        ]);

        // 🧱 Build profile data - FIXED: Properly handle both nested and flat data
        $profileData = [];
        
        // List of all profile fields
        $profileFields = [
            'first_name', 'last_name', 'contact_number', 'gender', 'address',
            'home_address', 'emergency_contact_name', 'emergency_contact_number',
            'blood_type', 'allergies', 'medical_conditions'
        ];
        
        foreach ($profileFields as $field) {
            // Check nested profile data first
            if (isset($validated['profile'][$field])) {
                $profileData[$field] = $validated['profile'][$field];
            }
            // Then check flat data
            elseif (isset($validated[$field])) {
                $profileData[$field] = $validated[$field];
            }
        }
        
        // Ensure first_name and last_name have values
        if (empty($profileData['first_name'])) {
            $profileData['first_name'] = explode(' ', $validated['name'])[0] ?? 'Unknown';
        }
        if (empty($profileData['last_name'])) {
            $profileData['last_name'] = explode(' ', $validated['name'])[1] ?? 'User';
        }
        
        $profileData['user_id'] = $user->id;
        
        // Create the profile
        UserProfile::create($profileData);

        // 🧩 Assign roles (case-insensitive handling)
        if (!empty($validated['roles'])) {
            $availableRoles = \Spatie\Permission\Models\Role::all()->pluck('name');
            $normalizedRoles = [];
            
            foreach ($validated['roles'] as $role) {
                $matchedRole = $availableRoles->first(function ($availableRole) use ($role) {
                    return strtolower($availableRole) === strtolower($role);
                });
                
                if ($matchedRole) {
                    $normalizedRoles[] = $matchedRole;
                }
            }
            
            if (!empty($normalizedRoles)) {
                $user->assignRole($normalizedRoles);
            }
        }

        DB::commit();

        $user->load($this->getDefaultRelations());

        $this->logActivity('User created', [
            'user_id' => $user->id,
            'email' => $user->email,
            'roles' => $normalizedRoles ?? [],
        ]);

        return $this->createdResponse($user, 'User created successfully.');
    } catch (\Throwable $e) {
        DB::rollBack();
        return $this->handleException($e, 'creating user');
    }
}

    private function validateAndNormalizeRoles(array $roles): array
{
    $availableRoles = \Spatie\Permission\Models\Role::all()->pluck('name');
    $normalizedRoles = [];
    
    foreach ($roles as $role) {
        // Case-insensitive match
        $matchedRole = $availableRoles->first(function ($availableRole) use ($role) {
            return strtolower($availableRole) === strtolower($role);
        });
        
        if ($matchedRole) {
            $normalizedRoles[] = $matchedRole;
        } else {
            throw new \Illuminate\Validation\ValidationException([
                'roles' => ["The role '{$role}' is invalid. Available roles: " . $availableRoles->join(', ')]
            ]);
        }
    }
    
    return $normalizedRoles;
}

    /**
     * Show user by ID
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = User::with($this->getDefaultRelations())->findOrFail($id);

            if (!$this->canViewUser($user)) {
                return $this->forbiddenResponse('You do not have permission to view this user.');
            }

            $this->logActivity('User viewed', ['viewed_user_id' => $user->id]);
            return $this->successResponse($user, 'User retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'retrieving user');
        }
    }

    /**
 * Update user
 */
public function update(Request $request, int $user): JsonResponse
{
    try {
        $userModel = User::findOrFail($user);

        if (!$this->canUpdateUser($userModel)) {
            return $this->forbiddenResponse('You do not have permission to update this user.');
        }

        $rules = [
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userModel->id)], // ← FIXED: use $userModel->id
            'password' => 'sometimes|string|min:8|confirmed',
            'account_status' => 'sometimes|string|in:active,inactive,suspended,pending',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|exists:roles,name',
            'profile.first_name' => 'sometimes|string|max:100',
            'profile.last_name' => 'sometimes|string|max:100',
            'profile.contact_number' => 'sometimes|string|max:20',
            'profile.gender' => 'sometimes|string|max:10',
            'profile.address' => 'sometimes|string',
            'profile.home_address' => 'sometimes|string',
            'profile.emergency_contact_name' => 'sometimes|string|max:100',
            'profile.emergency_contact_number' => 'sometimes|string|max:20',
            'profile.blood_type' => 'sometimes|string|max:5',
            'profile.allergies' => 'sometimes|string',
            'profile.medical_conditions' => 'sometimes|string',
        ];

        $validated = $request->validate($rules);

        DB::beginTransaction();

        $updateData = collect($validated)->except(['password', 'roles', 'profile'])->toArray();

        if (isset($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $userModel->update($updateData); // ← FIXED: use $userModel

        if (isset($validated['profile'])) {
            $userModel->profile()->updateOrCreate(['user_id' => $userModel->id], $validated['profile']); // ← FIXED: use $userModel
        }

        if (!empty($validated['roles']) && $this->userCan('assign-roles')) {
            $userModel->syncRoles($validated['roles']); // ← FIXED: use $userModel
        }

        DB::commit();
        $userModel->load($this->getDefaultRelations()); // ← FIXED: use $userModel

        $this->logActivity('User updated', [
            'user_id' => $userModel->id, // ← FIXED: use $userModel->id
            'updated_fields' => array_keys($updateData),
            'roles_updated' => isset($validated['roles']),
        ]);

        return $this->updatedResponse($userModel, 'User updated successfully.'); // ← FIXED: use $userModel
    } catch (\Throwable $e) {
        DB::rollBack();
        return $this->handleException($e, 'updating user');
    }
}

   /**
 * Remove user
 */
public function destroy(int $user): JsonResponse
{
    try {
        $userModel = User::findOrFail($user);

        // 🔧 FIXED: Check multiple permission names and allow Admin role
        $currentUser = request()->user();
        $hasPermission = $currentUser->can('delete-users') || 
                        $currentUser->can('delete users') ||
                        $currentUser->can('manage users') ||
                        $currentUser->hasRole('Admin');
        
        if (!$hasPermission) {
            return $this->forbiddenResponse('You do not have permission to delete users.');
        }

        // Prevent users from deleting their own account
        if ($userModel->id === $currentUser->id) {
            return $this->errorResponse('You cannot delete your own account.', 400);
        }

        // Check if user has critical assignments
        if ($this->userHasCriticalAssignments($userModel)) {
            return $this->errorResponse('User has active assignments and cannot be deleted.', 409);
        }

        $userModel->delete();

        $this->logActivity('User deleted', ['deleted_user_id' => $userModel->id]);
        return $this->deletedResponse('User deleted successfully.');
    } catch (\Throwable $e) {
        return $this->handleException($e, 'deleting user');
    }
}

    /**
     * Get user profile
     */
    public function getProfile(int $userId): JsonResponse
    {
        try {
            $user = User::with('profile')->findOrFail($userId);

            if (!$this->canViewUser($user)) {
                return $this->forbiddenResponse('You do not have permission to view this profile.');
            }

            return $this->successResponse([
                'user' => $user,
                'profile' => $user->profile
            ], 'Profile retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'retrieving profile');
        }
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request, int $userId): JsonResponse
    {
        try {
            $user = User::findOrFail($userId);

            if (!$this->canUpdateUser($user)) {
                return $this->forbiddenResponse('You do not have permission to update this profile.');
            }

            $validated = $request->validate([
                'first_name' => 'sometimes|string|max:100',
                'last_name' => 'sometimes|string|max:100',
                'contact_number' => 'sometimes|string|max:20',
                'gender' => 'sometimes|string|max:10',
                'address' => 'sometimes|string',
                'home_address' => 'sometimes|string',
                'emergency_contact_name' => 'sometimes|string|max:100',
                'emergency_contact_number' => 'sometimes|string|max:20',
                'blood_type' => 'sometimes|string|max:5',
                'allergies' => 'sometimes|string',
                'medical_conditions' => 'sometimes|string',
            ]);

            $user->profile()->updateOrCreate(['user_id' => $user->id], $validated);
            $user->load('profile');

            $this->logActivity('Profile updated', ['user_id' => $user->id]);
            return $this->updatedResponse($user, 'Profile updated successfully.');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'updating profile');
        }
    }

    /**
 * Get user roles
 */
public function getRoles(int $user): JsonResponse
{
    try {
        $userModel = User::findOrFail($user);
        
        if (!$this->canViewUser($userModel)) {
            return $this->forbiddenResponse('You do not have permission to view roles.');
        }

        return $this->successResponse([
            'user_id' => $userModel->id,
            'roles' => $userModel->getRoleNames(),
            'all_roles' => \Spatie\Permission\Models\Role::all()->pluck('name')
        ], 'User roles retrieved successfully.');
    } catch (\Throwable $e) {
        return $this->handleException($e, 'retrieving roles');
    }
}

/**
 * Update user roles
 */
public function updateRoles(Request $request, int $user): JsonResponse
{
    try {
        $userModel = User::findOrFail($user);

        // 🔧 FIXED: Check multiple permission names and allow Admin role
        $currentUser = request()->user();
        $hasPermission = $currentUser->can('assign-roles') || 
                        $currentUser->can('manage users') ||
                        $currentUser->can('edit users') ||
                        $currentUser->hasRole('Admin');
        
        if (!$hasPermission) {
            return $this->forbiddenResponse('You do not have permission to assign roles.');
        }

        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name'
        ]);

        $userModel->syncRoles($validated['roles']);
        $userModel->load('roles');

        $this->logActivity('Roles updated', [
            'user_id' => $userModel->id,
            'roles' => $validated['roles']
        ]);

        return $this->successResponse([
            'user_id' => $userModel->id,
            'roles' => $userModel->getRoleNames()
        ], 'User roles updated successfully.');
    } catch (\Throwable $e) {
        return $this->handleException($e, 'updating roles');
    }
}

/**
 * Remove role from user
 */
public function removeRole(int $user, string $role): JsonResponse
{
    try {
        $userModel = User::findOrFail($user);

        // 🔧 FIXED: Check multiple permission names and allow Admin role
        $currentUser = request()->user();
        $hasPermission = $currentUser->can('assign-roles') || 
                        $currentUser->can('manage users') ||
                        $currentUser->can('edit users') ||
                        $currentUser->hasRole('Admin');
        
        if (!$hasPermission) {
            return $this->forbiddenResponse('You do not have permission to remove roles.');
        }

        $userModel->removeRole($role);
        $userModel->load('roles');

        $this->logActivity('Role removed', [
            'user_id' => $userModel->id,
            'role' => $role
        ]);

        return $this->successResponse([
            'user_id' => $userModel->id,
            'roles' => $userModel->getRoleNames()
        ], 'Role removed successfully.');
    } catch (\Throwable $e) {
        return $this->handleException($e, 'removing role');
    }
}

/**
 * Get user permissions
 */
public function getPermissions(int $user): JsonResponse
{
    try {
        $userModel = User::findOrFail($user);
        
        if (!$this->canViewUser($userModel)) {
            return $this->forbiddenResponse('You do not have permission to view permissions.');
        }

        return $this->successResponse([
            'user_id' => $userModel->id,
            'permissions' => $userModel->getAllPermissions()->pluck('name'),
            'permissions_via_roles' => $userModel->getPermissionsViaRoles()->pluck('name')
        ], 'User permissions retrieved successfully.');
    } catch (\Throwable $e) {
        return $this->handleException($e, 'retrieving permissions');
    }
}

    /**
 * Activate user
 */
public function activate(int $user): JsonResponse
{
    try {
        $userModel = User::findOrFail($user);

        // Check permission - allow Admin and users with manage permissions
        $currentUser = request()->user();
        $hasPermission = $currentUser->can('manage users') || 
                        $currentUser->can('edit users') ||
                        $currentUser->hasRole('Admin');
        
        if (!$hasPermission) {
            return $this->forbiddenResponse('You do not have permission to activate users.');
        }

        $userModel->update(['account_status' => 'active']);
        $userModel->load($this->getDefaultRelations());

        $this->logActivity('User activated', ['user_id' => $userModel->id]);
        return $this->successResponse($userModel, 'User activated successfully.');
    } catch (\Throwable $e) {
        return $this->handleException($e, 'activating user');
    }
}

/**
 * Deactivate user
 */
public function deactivate(int $user): JsonResponse
{
    try {
        $userModel = User::findOrFail($user);

        // Check permission - allow Admin and users with manage permissions
        $currentUser = request()->user();
        $hasPermission = $currentUser->can('manage users') || 
                        $currentUser->can('edit users') ||
                        $currentUser->hasRole('Admin');
        
        if (!$hasPermission) {
            return $this->forbiddenResponse('You do not have permission to deactivate users.');
        }

        $userModel->update(['account_status' => 'inactive']);
        $userModel->load($this->getDefaultRelations());

        $this->logActivity('User deactivated', ['user_id' => $userModel->id]);
        return $this->successResponse($userModel, 'User deactivated successfully.');
    } catch (\Throwable $e) {
        return $this->handleException($e, 'deactivating user');
    }
}

    // 🧩 Helper Methods
    private function canViewUser(User $user): bool
{
    $currentUser = request()->user();
    
    if (!$currentUser) {
        return false;
    }
    
    // Allow users to view their own profile
    if ($currentUser->id === $user->id) {
        return true;
    }
    
    // Check if user has permission to view other users
    // Try multiple permission names since your seeder might use different ones
    $hasPermission = $currentUser->can('view-users') || 
                    $currentUser->can('view users') ||
                    $currentUser->can('manage users');
    
    return $hasPermission;
}

  private function canUpdateUser(User $user): bool
{
    $currentUser = request()->user();
    
    if (!$currentUser) {
        return false;
    }
    
    // Allow users to update their own profile
    if ($currentUser->id === $user->id) {
        return true;
    }
    
    // Check if user has permission to update other users
    // Try multiple permission names since your seeder might use different ones
    $hasPermission = $currentUser->can('update-users') || 
                    $currentUser->can('edit users') ||
                    $currentUser->can('manage users');
    
    return $hasPermission;
}

    private function userHasCriticalAssignments(User $user): bool
    {
        return $user->responder
            ? $user->responder->assignments()->whereIn('status', ['assigned', 'in_progress'])->exists()
            : false;
    }

    private function generateQrCode(): string
    {
        return 'QR_' . uniqid() . '_' . time();
    }
}