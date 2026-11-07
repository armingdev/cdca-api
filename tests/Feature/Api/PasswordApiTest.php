<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

const GENERIC_FORGOT_MESSAGE = 'If that email belongs to an account, a password reset link is on its way.';

describe('forgot password', function () {
    it('mails a reset link that opens the client app', function () {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.cdcaow.com']);
        $user = User::factory()->create(['email' => 'armin@example.com']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'armin@example.com'])
            ->assertOk()
            ->assertJsonPath('message', GENERIC_FORGOT_MESSAGE);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://app.cdcaow.com/reset-password?')
                && str_contains($url, 'token='.$notification->token)
                && str_contains($url, 'email=armin%40example.com');
        });
    });

    it('answers an unknown email exactly like a known one and sends nothing', function () {
        Notification::fake();

        $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', GENERIC_FORGOT_MESSAGE);

        Notification::assertNothingSent();
    });

    it('requires an email', function () {
        $this->postJson('/api/v1/forgot-password', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('email');
    });
});

describe('reset password', function () {
    it('sets the new password and revokes every api token', function () {
        $user = User::factory()->create(['email' => 'armin@example.com']);
        $user->createToken('phone');
        $user->createToken('laptop');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => 'armin@example.com',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $user->refresh();
        expect(Hash::check('brand-new-pass', $user->password))->toBeTrue()
            ->and($user->tokens()->count())->toBe(0);

        $this->postJson('/api/v1/login', ['email' => 'armin@example.com', 'password' => 'brand-new-pass'])
            ->assertOk();
    });

    it('rejects a bad token without touching the password', function () {
        $user = User::factory()->create(['email' => 'armin@example.com', 'password' => 'old-password']);

        $this->postJson('/api/v1/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'armin@example.com',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This password reset link is invalid or has expired.');

        expect(Hash::check('old-password', $user->refresh()->password))->toBeTrue();
    });

    it('gives an unknown email the same answer as a bad token', function () {
        $this->postJson('/api/v1/reset-password', [
            'token' => 'whatever',
            'email' => 'nobody@example.com',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This password reset link is invalid or has expired.');
    });
});

describe('change password', function () {
    it('changes the password, keeps this device signed in and signs out the others', function () {
        $user = User::factory()->create(['password' => 'old-password']);
        $current = $user->createToken('this-device');
        $other = $user->createToken('other-device');

        $this->withToken($current->plainTextToken)
            ->putJson('/api/v1/user/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])
            ->assertOk();

        expect(Hash::check('brand-new-pass', $user->refresh()->password))->toBeTrue()
            ->and($user->tokens()->pluck('id')->all())->toBe([$current->accessToken->id])
            ->and($user->tokens()->whereKey($other->accessToken->id)->exists())->toBeFalse();
    });

    it('rejects a wrong current password', function () {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = $user->createToken('this-device')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/user/password', [
                'current_password' => 'not-it',
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('current_password');

        expect(Hash::check('old-password', $user->refresh()->password))->toBeTrue();
    });

    it('rejects reusing the current password', function () {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = $user->createToken('this-device')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/user/password', [
                'current_password' => 'old-password',
                'password' => 'old-password',
                'password_confirmation' => 'old-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('password');
    });

    it('requires authentication', function () {
        $this->putJson('/api/v1/user/password', [])->assertUnauthorized();
    });
});
