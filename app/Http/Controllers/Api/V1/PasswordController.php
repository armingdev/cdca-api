<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * cdca account passwords: the forgot → mail → reset flow, and a signed-in
 * change. Neither public endpoint says whether an email is registered.
 */
class PasswordController extends Controller
{
    /**
     * Mail a reset link. The answer is the same whether or not the email
     * belongs to an account (and whether the broker throttled a repeat), so
     * the endpoint cannot be used to discover who has signed up.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => $request->validated('email')]);

        return response()->json([
            'message' => 'If that email belongs to an account, a password reset link is on its way.',
        ]);
    }

    /**
     * Set a new password from a mailed token. Every API token is revoked:
     * a reset is what someone does when they think the account is exposed.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
                $user->save();
                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            // One message for a bad token and an unknown email alike.
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return response()->json(['message' => 'Your password has been reset. Sign in with the new password.']);
    }

    /**
     * Change the signed-in user's password. The calling device stays signed
     * in; every other token is revoked.
     */
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        // The API authenticates by bearer token only (no stateful SPA guard),
        // so the current token is always a stored PersonalAccessToken.
        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();

        return response()->json(['message' => 'Password changed. Other devices have been signed out.']);
    }
}
