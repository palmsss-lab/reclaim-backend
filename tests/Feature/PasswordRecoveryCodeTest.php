<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetUserPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryCodeTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        });
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $this->user = User::factory()->create();
    }

    private function issue(): string
    {
        $response = $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        $this->assertStringNotContainsString('code', json_encode($response->json('data')));
        $notification = Notification::sent($this->user, ResetUserPassword::class)->last();
        $mail = $notification->toMail($this->user);
        $this->assertSame(1, preg_match('/\b[0-9]{6}\b/', implode(' ', $mail->introLines), $matches), 'The email must contain a six-digit code.');
        $this->assertNull($mail->actionUrl);
        $this->assertStringContainsString('10 minutes', implode(' ', $mail->introLines));

        return $matches[0];
    }

    private function reset(string $code, ?string $email = null)
    {
        $verification = $this->postJson('/api/auth/password/verify-code', ['email' => $email ?? $this->user->email, 'code' => $code]);
        if ($verification->status() !== 200) {
            return $verification;
        }

        return $this->postJson('/api/auth/password/reset', [
            'email' => $email ?? $this->user->email, 'authorization' => $verification->json('data.authorization'),
            'password' => 'New-password-for-tests-123', 'password_confirmation' => 'New-password-for-tests-123',
        ]);
    }

    public function test_code_is_hashed_email_bound_single_use_and_revokes_existing_sessions(): void
    {
        $code = $this->issue();
        $row = DB::table('password_reset_tokens')->where('email', $this->user->email)->first();
        $this->assertNotSame($code, $row->token);
        $this->user->createToken('recovery-fixture', ['user']);
        DB::table('sessions')->insert(['id' => 'recovery-session', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);
        $other = User::factory()->create();
        $this->reset($code, $other->email)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->reset($code)->assertOk();
        $this->assertTrue(password_verify('New-password-for-tests-123', $this->user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->user->email]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->user->id]);
        $this->assertSame(0, $this->user->tokens()->count());
        $this->reset($code)->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_code_expires_at_ten_minutes(): void
    {
        $code = $this->issue();
        $this->travel(10)->minutes();
        $this->reset($code)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertTrue(password_verify('password', $this->user->fresh()->password));
    }

    public function test_code_is_valid_just_before_expiry(): void
    {
        $code = $this->issue();
        $this->travel(599)->seconds();
        $this->reset($code)->assertOk();
    }

    public function test_five_wrong_attempts_invalidate_the_code_across_ip_addresses(): void
    {
        $code = $this->issue();
        $wrong = $code === '000000' ? '000001' : '000000';
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i]);
            $this->reset($wrong)->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100']);
        $this->reset($code)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->user->email]);
    }

    public function test_attempt_exhaustion_does_not_bypass_the_resend_delay(): void
    {
        $code = $this->issue();
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i]);
            $this->reset($code === '000000' ? '000001' : '000000')->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100']);
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        Notification::assertSentToTimes($this->user, ResetUserPassword::class, 1);
        $this->travel(60)->seconds();
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        Notification::assertSentToTimes($this->user, ResetUserPassword::class, 2);
    }

    public function test_four_wrong_attempts_do_not_prevent_a_correct_attempt(): void
    {
        $code = $this->issue();
        for ($i = 1; $i <= 4; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i]);
            $this->reset($code === '000000' ? '000001' : '000000')->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100']);
        $this->reset($code)->assertOk();
    }

    public function test_resend_waits_a_minute_and_replaces_the_previous_hash(): void
    {
        $code = $this->issue();
        $first = DB::table('password_reset_tokens')->where('email', $this->user->email)->value('token');
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        Notification::assertSentToTimes($this->user, ResetUserPassword::class, 1);
        $this->travel(60)->seconds();
        $replacement = $this->issue();
        $this->assertNotSame($code, $replacement);
        $this->assertNotSame($first, DB::table('password_reset_tokens')->where('email', $this->user->email)->value('token'));
        $this->reset($code)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->reset($replacement)->assertOk();
        Notification::assertSentToTimes($this->user, ResetUserPassword::class, 2);
    }

    public function test_inactive_and_google_only_accounts_cannot_reset_with_an_existing_code(): void
    {
        foreach (['deactivated_at' => now(), 'has_local_password' => false] as $field => $value) {
            $this->user = User::factory()->create();
            $code = $this->issue();
            $this->user->forceFill([$field => $value])->save();
            $this->reset($code)->assertUnprocessable()->assertJsonValidationErrors('code');
            $this->assertTrue(password_verify('password', $this->user->fresh()->password));
        }
    }

    public function test_unknown_address_has_the_same_response_without_an_email(): void
    {
        $known = $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        Notification::assertCount(1);
    }

    public function test_code_format_and_password_confirmation_have_field_errors(): void
    {
        $this->postJson('/api/auth/password/verify-code', [
            'email' => $this->user->email, 'code' => '12345x',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/auth/password/reset', [
            'email' => $this->user->email, 'authorization' => str_repeat('a', 64), 'password' => 'Too-short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_attempt_limit_works_with_the_deployment_database_cache(): void
    {
        config(['cache.default' => 'database', 'cache.limiter' => 'database']);
        app('cache')->forgetDriver('database');
        app()->forgetInstance('Illuminate\Cache\RateLimiter');
        $this->test_five_wrong_attempts_invalidate_the_code_across_ip_addresses();
    }
}
