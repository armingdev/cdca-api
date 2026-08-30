<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Jobs\SyncRgaCharactersJob;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->except('device_name'));
        $token = $user->createToken($request->validated('device_name') ?? 'api')->plainTextToken;

        return response()->json(['user' => UserResource::make($user), 'token' => $token], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        $token = $user->createToken($request->validated('device_name') ?? 'api')->plainTextToken;

        // Signing in should find the fleet already current. The job does all
        // the guarding (it skips a sessionless account rather than logging it
        // in, and debounces repeat logins), so this stays a plain dispatch.
        foreach ($user->rgas as $rga) {
            SyncRgaCharactersJob::dispatch($rga);
        }

        return response()->json(['user' => UserResource::make($user), 'token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json(['user' => UserResource::make($request->user())]);
    }
}
