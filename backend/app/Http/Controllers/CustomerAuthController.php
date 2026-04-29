<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\EmailVerificationToken;
use App\Models\PasswordResetToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class CustomerAuthController extends Controller
{
    /**
     * Customer Registration (E-commerce only)
     */
    public function register(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|min:2|max:255',
                'email' => 'required|email|unique:customers,email',
                'password' => 'required|string|min:8|confirmed',
                'phone' => 'required|string|max:20|unique:customers,phone',
                'date_of_birth' => 'nullable|date|before:today',
                'gender' => 'nullable|in:male,female,other',
                'address' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:100',
                'preferences' => 'nullable|array',
                'social_profiles' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Create e-commerce customer with password
            $customer = Customer::createEcommerceCustomer([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'phone' => $request->phone,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'address' => $request->address,
                'city' => $request->city,
                'state' => $request->state,
                'postal_code' => $request->postal_code,
                'country' => $request->country ?? 'Bangladesh',
                'preferences' => $request->preferences ?? [],
                'social_profiles' => $request->social_profiles ?? [],
            ]);

            // Send email verification
            $this->sendEmailVerification($customer);

            return response()->json([
                'success' => true,
                'message' => 'Registration successful. Please check your email to verify your account.',
                'data' => [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                        'customer_code' => $customer->customer_code,
                        'status' => $customer->status,
                        'email_verified' => $customer->hasVerifiedEmail(),
                    ],
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Customer Login
     */
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string',
                'remember_me' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Find customer
            $customer = Customer::where('email', $request->email)
                ->where('customer_type', 'ecommerce')
                ->first();

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }

            // Check if customer can login
            if (!$customer->canLogin()) {
                $reason = $customer->isActive() ? 
                    'Account requires password setup' : 
                    'Account is ' . $customer->status;
                
                return response()->json([
                    'success' => false,
                    'message' => 'Login not allowed: ' . $reason,
                ], 403);
            }

            // Verify password
            if (!$customer->verifyPassword($request->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }

            // Generate JWT token for customer
            $token = JWTAuth::fromUser($customer);
            $tokenTTL = $request->remember_me ? 
                config('jwt.refresh_ttl', 20160) : // 2 weeks if remember me
                config('jwt.ttl', 60); // Default 1 hour

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                        'customer_code' => $customer->customer_code,
                        'status' => $customer->status,
                        'email_verified' => $customer->hasVerifiedEmail(),
                        'total_orders' => $customer->total_orders,
                        'total_purchases' => $customer->total_purchases,
                        'last_purchase_at' => $customer->last_purchase_at,
                    ],
                    'token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => $tokenTTL * 60, // Convert to seconds
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Customer Logout
     */
    public function logout(Request $request)
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => 'Successfully logged out',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh Token
     */
    public function refresh(Request $request)
    {
        try {
            $token = JWTAuth::refresh(JWTAuth::getToken());
            $customer = JWTAuth::setToken($token)->toUser();

            return response()->json([
                'success' => true,
                'data' => [
                    'token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl', 60) * 60,
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed: ' . $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Get Authenticated Customer Profile
     */
    public function me(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            return response()->json([
                'success' => true,
                'data' => [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                        'customer_code' => $customer->customer_code,
                        'customer_type' => $customer->customer_type,
                        'status' => $customer->status,
                        'email_verified' => $customer->hasVerifiedEmail(),
                        'date_of_birth' => $customer->date_of_birth,
                        'gender' => $customer->gender,
                        'address' => $customer->address,
                        'city' => $customer->city,
                        'state' => $customer->state,
                        'postal_code' => $customer->postal_code,
                        'country' => $customer->country,
                        'total_orders' => $customer->total_orders,
                        'total_purchases' => $customer->total_purchases,
                        'first_purchase_at' => $customer->first_purchase_at,
                        'last_purchase_at' => $customer->last_purchase_at,
                        'preferences' => $customer->preferences,
                        'social_profiles' => $customer->social_profiles,
                        'created_at' => $customer->created_at,
                        'updated_at' => $customer->updated_at,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get profile: ' . $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Send Password Reset Email
     */
    public function sendPasswordResetEmail(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customer = Customer::where('email', $request->email)
                ->where('customer_type', 'ecommerce')
                ->first();

            if (!$customer) {
                return response()->json([
                    'success' => true,
                    'message' => 'If an account exists with this email, a password reset link has been sent.',
                ]);
            }

            // Generate reset token
            $token = Str::random(64);
            
            PasswordResetToken::updateOrCreate(
                ['email' => $customer->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]
            );

            // Send email (implement mail notification)
            // Mail::to($customer->email)->send(new PasswordResetMail($customer, $token));

            return response()->json([
                'success' => true,
                'message' => 'If an account exists with this email, a password reset link has been sent.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send reset email: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reset Password
     */
    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'token' => 'required|string',
                'password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $resetRecord = PasswordResetToken::where('email', $request->email)->first();

            if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired reset token',
                ], 400);
            }

            // Check token expiry (24 hours)
            if ($resetRecord->created_at->addHours(24)->isPast()) {
                $resetRecord->delete();
                return response()->json([
                    'success' => false,
                    'message' => 'Reset token has expired',
                ], 400);
            }

            $customer = Customer::where('email', $request->email)
                ->where('customer_type', 'ecommerce')
                ->first();

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found',
                ], 404);
            }

            // Update password
            $customer->setPassword($request->password);
            
            // Delete reset token
            $resetRecord->delete();

            return response()->json([
                'success' => true,
                'message' => 'Password reset successful',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Password reset failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send Email Verification
     */
    public function sendEmailVerification(Customer $customer)
    {
        try {
            $token = Str::random(64);
            
            EmailVerificationToken::updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]
            );

            // Send verification email (implement mail notification)
            // Mail::to($customer->email)->send(new EmailVerificationMail($customer, $token));

            return true;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verify Email
     */
    public function verifyEmail(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|integer|exists:customers,id',
                'token' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $verificationRecord = EmailVerificationToken::where('customer_id', $request->customer_id)->first();

            if (!$verificationRecord || !Hash::check($request->token, $verificationRecord->token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid verification token',
                ], 400);
            }

            // Check token expiry (48 hours)
            if ($verificationRecord->created_at->addHours(48)->isPast()) {
                $verificationRecord->delete();
                return response()->json([
                    'success' => false,
                    'message' => 'Verification token has expired',
                ], 400);
            }

            $customer = Customer::find($request->customer_id);
            
            if ($customer->hasVerifiedEmail()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Email already verified',
                ]);
            }

            $customer->markEmailAsVerified();
            $verificationRecord->delete();

            return response()->json([
                'success' => true,
                'message' => 'Email verified successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Email verification failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resend Email Verification
     */
    public function resendEmailVerification(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customer = Customer::where('email', $request->email)
                ->where('customer_type', 'ecommerce')
                ->first();

            if (!$customer) {
                return response()->json([
                    'success' => true,
                    'message' => 'If an account exists with this email, a verification link has been sent.',
                ]);
            }

            if ($customer->hasVerifiedEmail()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Email is already verified',
                ]);
            }

            $this->sendEmailVerification($customer);

            return response()->json([
                'success' => true,
                'message' => 'Verification email sent successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send verification email: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Change Password (for authenticated customers)
     */
    public function changePassword(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if (!$customer->verifyPassword($request->current_password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect',
                ], 400);
            }

            $customer->setPassword($request->new_password);

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Password change failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}