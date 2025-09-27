<?php

namespace App\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Authentication Controller for Project Kalinga
 * Handles user authentication, token management, and profile operations
 */
class AuthController extends BaseApiController
{
    /**
     * Get the model class for this controller
     */
    protected function getModel(): string
    {
        return User::class;
    }

    /**
     * Get validation rules (not used for auth but required by base class)
     */
    protected function getValidationRules(): array
    {
        return [];
    }

    /**
     * User login
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
                'device_name' => 'sometimes|string|max:255',
                'remember_me' => 'sometimes|boolean'
            ]);

            // Find user by email
            $user = User::where('email', $validated['email'])->first();

            // Check if user exists and password is correct
            if (!$user || !Hash::check($validated['password'], $user->password)) {
                $this->logFailedLogin($validated['email'], 'Invalid credentials');
                return $this->errorResponse('Invalid credentials.', 401, null, 'INVALID_CREDENTIALS');
            }

            // Check if account is active
            if ($user->account_status !== 'active') {
                $this->logFailedLogin($validated['email'], 'Account inactive');
                return $this->errorResponse(
                    'Account is not active. Please contact administrator.', 
                    403, 
                    null, 
                    'ACCOUNT_INACTIVE'
                );
            }

            // Check if account is locked
            if ($user->locked_until && $user->locked_until > now()) {
                $this->logFailedLogin($validated['email'], 'Account locked');
                return $this->errorResponse(
                    'Account is temporarily locked. Please try again later.', 
                    423, 
                    ['locked_until' => $user->locked_until->toISOString()], 
                    'ACCOUNT_LOCKED'
                );
            }

            // Reset failed login attempts
            $user->update([
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'last_active' => now()
            ]);

            // Create token
            $deviceName = $validated['device_name'] ?? $request->userAgent();
            $abilities = $this->getUserAbilities($user);
            
            // Set token expiration based on remember_me
            $expiresAt = $validated['remember_me'] ?? false 
                ? now()->addDays(30) 
                : now()->addDay();

            $token = $user->createToken($deviceName, $abilities, $expiresAt);

            // Load user relationships
            $user->load(['profile', 'roles', 'responderDetails', 'adminDetails']);

            // Prepare response data
            $responseData = [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'account_status' => $user->account_status,
                    'last_active' => $user->last_active,
                    'profile' => $user->profile,
                    'roles' => $user->roles->pluck('name'),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                    'responder_details' => $user->responderDetails,
                    'admin_details' => $user->adminDetails
                ],
                'token' => [
                    'access_token' => $token->plainTextToken,
                    'token_type' => 'Bearer',
                    'expires_at' => $expiresAt->toISOString()
                ],
                'system_info' => [
                    'server_time' => now()->toISOString(),
                    'system_status' => $this->getSystemLoad(),
                    'api_version' => 'v1'
                ]
            ];

            $this->logActivity('User login', [
                'user_id' => $user->id,
                'device_name' => $deviceName,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return $this->successResponse($responseData, 'Login successful.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'user login');
        }
    }

    /**
     * User registration
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
                'device_name' => 'sometimes|string|max:255',
                
                // Profile information
                'first_name' => 'sometimes|string|max:100',
                'last_name' => 'sometimes|string|max:100',
                'contact_number' => 'sometimes|string|max:20',
                'address' => 'sometimes|string|max:500',
                'date_of_birth' => 'sometimes|date',
                'gender' => 'sometimes|string|in:male,female,other',
                'emergency_contact' => 'sometimes|string|max:20',
                
                // Role assignment (admin only)
                'role' => 'sometimes|string|exists:roles,name'
            ]);

            DB::beginTransaction();

            // Create user
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'account_status' => 'active', // or 'pending' if email verification required
                'qr_code' => $this->generateQrCode(),
                'last_active' => now()
            ]);

            // Create profile if profile data provided
            $profileData = collect($validated)->only([
                'first_name', 'last_name', 'contact_number', 'address', 
                'date_of_birth', 'gender', 'emergency_contact'
            ])->filter()->toArray();

            if (!empty($profileData)) {
                $user->profile()->create($profileData);
            }

            // Assign default role or specified role
            $role = 'user'; // default role
            if (isset($validated['role']) && $this->userCan('assign-roles')) {
                $role = $validated['role'];
            }
            $user->assignRole($role);

            // Create token
            $deviceName = $validated['device_name'] ?? $request->userAgent();
            $abilities = $this->getUserAbilities($user);
            $token = $user->createToken($deviceName, $abilities, now()->addDay());

            DB::commit();

            // Load relationships for response
            $user->load(['profile', 'roles']);

            $responseData = [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'account_status' => $user->account_status,
                    'profile' => $user->profile,
                    'roles' => $user->roles->pluck('name')
                ],
                'token' => [
                    'access_token' => $token->plainTextToken,
                    'token_type' => 'Bearer',
                    'expires_at' => now()->addDay()->toISOString()
                ]
            ];

            $this->logActivity('User registered', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $role
            ]);

            return $this->createdResponse($responseData, 'Registration successful.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'user registration');
        }
    }

    /**
     * User logout
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            // Option to logout from all devices
            if ($request->get('all_devices', false)) {
                $user->tokens()->delete();
                $message = 'Logged out from all devices.';
            } else {
                $request->user()->currentAccessToken()->delete();
                $message = 'Logged out successfully.';
            }

            $this->logActivity('User logout', [
                'user_id' => $user->id,
                'all_devices' => $request->get('all_devices', false)
            ]);

            return $this->successResponse(null, $message);

        } catch (\Exception $e) {
            return $this->handleException($e, 'user logout');
        }
    }

    /**
     * Get current authenticated user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $user->load(['profile', 'roles', 'permissions', 'responderDetails', 'adminDetails']);

            // Update last active timestamp
            $user->update(['last_active' => now()]);

            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_status' => $user->account_status,
                'last_active' => $user->last_active,
                'qr_code' => $user->qr_code,
                'profile' => $user->profile,
                'roles' => $user->roles,
                'permissions' => $user->getAllPermissions(),
                'responder_details' => $user->responderDetails,
                'admin_details' => $user->adminDetails,
                'token_info' => [
                    'expires_at' => $request->user()->currentAccessToken()->expires_at,
                    'last_used_at' => $request->user()->currentAccessToken()->last_used_at
                ]
            ];

            return $this->successResponse($userData, 'User profile retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving user profile');
        }
    }

    /**
     * Update user profile
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'first_name' => 'sometimes|string|max:100',
                'last_name' => 'sometimes|string|max:100',
                'contact_number' => 'sometimes|string|max:20',
                'address' => 'sometimes|string|max:500',
                'date_of_birth' => 'sometimes|date',
                'gender' => 'sometimes|string|in:male,female,other',
                'emergency_contact' => 'sometimes|string|max:20'
            ]);

            DB::beginTransaction();

            // Update user table fields
            if (isset($validated['name'])) {
                $user->update(['name' => $validated['name']]);
            }

            // Update profile fields
            $profileData = collect($validated)->except(['name'])->filter()->toArray();
            if (!empty($profileData)) {
                $user->profile()->updateOrCreate(['user_id' => $user->id], $profileData);
            }

            DB::commit();

            $user->load(['profile']);

            $this->logActivity('Profile updated', ['user_id' => $user->id]);

            return $this->updatedResponse([
                'user' => $user->only(['id', 'name', 'email']),
                'profile' => $user->profile
            ], 'Profile updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'updating profile');
        }
    }

    /**
     * Update user password
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updatePassword(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'current_password' => 'required|string',
                'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
                'logout_other_devices' => 'sometimes|boolean'
            ]);

            // Verify current password
            if (!Hash::check($validated['current_password'], $user->password)) {
                return $this->errorResponse('Current password is incorrect.', 400, null, 'INVALID_PASSWORD');
            }

            // Update password
            $user->update([
                'password' => Hash::make($validated['password'])
            ]);

            // Optionally logout from other devices
            if ($validated['logout_other_devices'] ?? false) {
                // Delete all tokens except current one
                $currentToken = $request->user()->currentAccessToken();
                $user->tokens()->where('id', '!=', $currentToken->id)->delete();
            }

            $this->logActivity('Password updated', [
                'user_id' => $user->id,
                'logout_other_devices' => $validated['logout_other_devices'] ?? false
            ]);

            return $this->successResponse(null, 'Password updated successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'updating password');
        }
    }

    /**
     * Forgot password - send reset link
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|string|email|exists:users,email'
            ]);

            $user = User::where('email', $validated['email'])->first();

            if (!$user || $user->account_status !== 'active') {
                // Don't reveal if account exists or is inactive for security
                return $this->successResponse(null, 'If the email exists, a reset link has been sent.');
            }

            // Generate password reset token (in a real app, you'd use Laravel's password reset functionality)
            $resetToken = Str::random(64);
            
            // Store reset token (you'd typically have a password_resets table)
            DB::table('password_resets')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($resetToken),
                    'created_at' => now()
                ]
            );

            // Here you would send the reset email
            // Mail::to($user->email)->send(new PasswordResetMail($resetToken));

            $this->logActivity('Password reset requested', ['email' => $user->email]);

            return $this->successResponse([
                'reset_token' => $resetToken // Only for testing - remove in production
            ], 'If the email exists, a reset link has been sent.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'password reset request');
        }
    }

    /**
     * Reset password with token
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|string|email',
                'token' => 'required|string',
                'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()]
            ]);

            // Find the reset token
            $resetRecord = DB::table('password_resets')
                ->where('email', $validated['email'])
                ->first();

            if (!$resetRecord || !Hash::check($validated['token'], $resetRecord->token)) {
                return $this->errorResponse('Invalid or expired reset token.', 400, null, 'INVALID_TOKEN');
            }

            // Check if token is not expired (24 hours)
            if (now()->diffInHours($resetRecord->created_at) > 24) {
                DB::table('password_resets')->where('email', $validated['email'])->delete();
                return $this->errorResponse('Reset token has expired.', 400, null, 'TOKEN_EXPIRED');
            }

            // Find user and update password
            $user = User::where('email', $validated['email'])->first();
            
            if (!$user) {
                return $this->errorResponse('User not found.', 404, null, 'USER_NOT_FOUND');
            }

            // Update password and clear failed attempts
            $user->update([
                'password' => Hash::make($validated['password']),
                'failed_login_attempts' => 0,
                'locked_until' => null
            ]);

            // Delete the reset token
            DB::table('password_resets')->where('email', $validated['email'])->delete();

            // Revoke all existing tokens for security
            $user->tokens()->delete();

            $this->logActivity('Password reset completed', ['user_id' => $user->id]);

            return $this->successResponse(null, 'Password reset successfully. Please login with your new password.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'password reset');
        }
    }

    /**
     * Helper Methods
     */

    /**
     * Get user abilities for token creation
     */
    private function getUserAbilities(User $user): array
    {
        $abilities = ['*']; // Default: all abilities
        
        // You could customize abilities based on roles
        // $abilities = $user->getAllPermissions()->pluck('name')->toArray();
        
        return $abilities;
    }

    /**
     * Generate QR code for user
     */
    private function generateQrCode(): string
    {
        return 'QR_' . Str::random(10) . '_' . time();
    }

    /**
     * Log failed login attempt
     */
    private function logFailedLogin(string $email, string $reason): void
    {
        $user = User::where('email', $email)->first();
        
        if ($user) {
            $attempts = $user->failed_login_attempts + 1;
            $updateData = ['failed_login_attempts' => $attempts];

            // Lock account after 5 failed attempts
            if ($attempts >= 5) {
                $updateData['locked_until'] = now()->addHours(1);
            }

            $user->update($updateData);
        }

        $this->logActivity('Failed login attempt', [
            'email' => $email,
            'reason' => $reason,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ], 'warning');
    }
}