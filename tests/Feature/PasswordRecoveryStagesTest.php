<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetUserPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryStagesTest extends TestCase
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
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        $mail = Notification::sent($this->user, ResetUserPassword::class)->last()->toMail($this->user);
        $this->assertSame(1, preg_match('/\b[0-9]{6}\b/', implode(' ', $mail->introLines), $matches));

        return $matches[0];
    }

    private function verify(string $code)
    {
        return $this->postJson('/api/auth/password/verify-code', ['email' => $this->user->email, 'code' => $code]);
    }

    private function finish(string $authorization, ?string $email = null, array $extra = [])
    {
        return $this->postJson('/api/auth/password/reset', [
            'email' => $email ?? $this->user->email, 'authorization' => $authorization,
            'password' => 'New-staged-password-12345', 'password_confirmation' => 'New-staged-password-12345',
            ...$extra,
        ]);
    }

    public function test_verification_consumes_code_and_returns_a_hashed_authorization_without_changing_password(): void
    {
        $code = $this->issue();
        $this->user->createToken('existing-session', ['user']);
        $response = $this->verify($code)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.expires_in', 300);
        $authorization = $response->json('data.authorization');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $authorization);
        $stored = DB::table('password_reset_tokens')->where('email', $this->user->email)->value('token');
        $this->assertNotSame($authorization, $stored);
        $this->assertStringNotContainsString($authorization, $stored);
        $this->assertTrue(password_verify('password', $this->user->fresh()->password));
        $this->assertSame(1, $this->user->tokens()->count());
        $this->verify($code)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->finish($authorization)->assertOk();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_code_cannot_be_used_to_skip_server_verification(): void
    {
        $code = $this->issue();
        $this->postJson('/api/auth/password/reset', [
            'email' => $this->user->email, 'code' => $code,
            'password' => 'New-staged-password-12345', 'password_confirmation' => 'New-staged-password-12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('authorization');
        $this->assertTrue(password_verify('password', $this->user->fresh()->password));
    }

    public function test_authorization_expires_at_five_minutes(): void
    {
        $authorization = $this->verify($this->issue())->assertOk()->json('data.authorization');
        $this->travel(300)->seconds();
        $this->finish($authorization)->assertUnprocessable()->assertJsonValidationErrors('authorization');
        $this->assertTrue(password_verify('password', $this->user->fresh()->password));
    }

    public function test_authorization_is_valid_just_before_expiry_and_cannot_be_replayed(): void
    {
        $authorization = $this->verify($this->issue())->assertOk()->json('data.authorization');
        $this->travel(299)->seconds();
        $this->finish($authorization)->assertOk();
        $this->finish($authorization)->assertUnprocessable()->assertJsonValidationErrors('authorization');
    }

    public function test_wrong_authorization_and_password_validation_do_not_consume_the_valid_authorization(): void
    {
        $authorization = $this->verify($this->issue())->assertOk()->json('data.authorization');
        $this->finish($authorization, extra: ['password_confirmation' => 'Does-not-match-12345'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->finish(str_repeat('a', 64))->assertUnprocessable()->assertJsonValidationErrors('authorization');
        $this->finish($authorization)->assertOk();
    }

    public function test_authorization_is_bound_to_the_email_and_active_local_password_account(): void
    {
        $authorization = $this->verify($this->issue())->assertOk()->json('data.authorization');
        $other = User::factory()->create();
        $this->finish($authorization, $other->email)->assertUnprocessable()->assertJsonValidationErrors('authorization');
        $this->user->forceFill(['has_local_password' => false])->save();
        $this->finish($authorization)->assertUnprocessable()->assertJsonValidationErrors('authorization');
        $this->assertTrue(password_verify('password', $this->user->fresh()->password));
    }

    public function test_successful_resend_invalidates_authorization_and_cooldown_is_measured_from_sending(): void
    {
        $code = $this->issue();
        $this->travel(59)->seconds();
        $authorization = $this->verify($code)->assertOk()->json('data.authorization');
        $this->travel(1)->seconds();
        $replacement = $this->issue();
        Notification::assertSentToTimes($this->user, ResetUserPassword::class, 2);
        $this->finish($authorization)->assertUnprocessable()->assertJsonValidationErrors('authorization');
        $newAuthorization = $this->verify($replacement)->assertOk()->json('data.authorization');
        $this->finish($newAuthorization)->assertOk();
    }

    public function test_code_replays_do_not_invalidate_a_verified_authorization(): void
    {
        $code = $this->issue();
        $authorization = $this->verify($code)->assertOk()->json('data.authorization');
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i]);
            $this->verify($code)->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100']);
        $this->finish($authorization)->assertOk();
    }

    public function test_authorization_is_bound_to_the_original_account_when_email_is_reassigned(): void
    {
        $authorization = $this->verify($this->issue())->assertOk()->json('data.authorization');
        $original = $this->user->email;
        $this->user->forceFill(['email' => 'changed@example.test'])->save();
        $newOwner = User::factory()->create(['email' => $original]);
        $this->finish($authorization, $original)->assertUnprocessable()->assertJsonValidationErrors('authorization');
        $this->assertTrue(password_verify('password', $newOwner->fresh()->password));
    }

    public function test_unknown_address_and_incorrect_code_receive_the_same_error(): void
    {
        $code = $this->issue();
        $wrong = $this->verify($code === '000000' ? '000001' : '000000')->assertUnprocessable()->json();
        $unknown = $this->postJson('/api/auth/password/verify-code', ['email' => 'absent@example.test', 'code' => $code])
            ->assertUnprocessable()->json();
        $this->assertSame($wrong, $unknown);
    }

    public function test_reset_code_expiry_and_five_attempt_limit_are_enforced_at_verification(): void
    {
        $code = $this->issue();
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i]);
            $this->verify($code === '000000' ? '000001' : '000000')->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100']);
        $this->verify($code)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->user->email]);
        $this->travel(60)->seconds();
        $code = $this->issue();
        $this->travel(600)->seconds();
        $this->verify($code)->assertUnprocessable()->assertJsonValidationErrors('code');
    }
}
