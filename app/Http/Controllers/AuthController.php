<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'signup', 'resetPassword']]);
    }

    /**
     * Register a new employee
     *
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function signup(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:employees',
            'password' => 'required|string|min:6|confirmed',
            'store_id' => 'required|exists:stores,id',
            'role_id' => 'nullable|exists:roles,id',
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:100',
        ]);

        $employee = Employee::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'store_id' => $request->store_id,
            'role_id' => $request->role_id,
            'phone' => $request->phone,
            'department' => $request->department,
            'employee_code' => Employee::generateEmployeeCode(),
            'hire_date' => now(),
            'is_active' => true,
            'is_in_service' => true,
        ]);

        $credentials = $request->only('email', 'password');
        $token = Auth::attempt($credentials);

        return response()->json([
            'message' => 'Employee registered successfully',
            'employee' => $employee,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::factory()->getTTL() * 60
        ], 201);
    }
    
    /**
     * Get a JWT token via given credentials.
     *
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');

        if (!$token = Auth::attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Update last login timestamp
        $employee = Auth::user();
        if ($employee && method_exists($employee, 'updateLastLogin')) {
            $employee->updateLastLogin();
        }

        return $this->respondWithToken($token);
    }

    /**
     * Get the authenticated User
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        $user = $this->guard()->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user->load(['role', 'store']);

        return response()->json($user);
    }


    /**
     * Log the user out (Invalidate the token)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        Auth::logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return $this->respondWithToken(Auth::refresh());
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::factory()->getTTL() * 60
        ]);
    }

    public function guard()
    {
        return Auth::guard('api');
    }
    /**
     * Reset password for an employee
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $employee = \App\Models\Employee::where('email', $request->email)->first();
        
        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found with this email'
            ], 404);
        }

        $employee->password = bcrypt($request->password);
        $employee->save();

        return response()->json([
            'success' => true,
            'message' => 'Password has been reset successfully'
        ]);
    }
}
