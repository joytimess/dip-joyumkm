<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AuthResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return (new AuthResource($this->authService->register($request->validated())))
            ->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): AuthResource
    {
        return new AuthResource($this->authService->login($request->validated()));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($this->authService->me($request->user()));
    }

    public function logout(Request $request): MessageResource
    {
        return new MessageResource($this->authService->logout($request->user()));
    }
}
