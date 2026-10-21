<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return $this->tokenResponse(auth('api')->login($user), $user, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $token = auth('api')->attempt($request->validated());
        if (! $token) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        return $this->tokenResponse($token, auth('api')->user());
    }


    private function tokenResponse(string $token, User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => (new UserResource($user))->resolve(),
        ], $status);
    }

}
