<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    private const PASSWORD = 'Session-fixture-password-123';

    private const ORIGIN = 'http://127.0.0.1:5174';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function () {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
        });
        config(['session.driver' => 'database', 'sanctum.stateful' => ['127.0.0.1:5174']]);
        $this->withCredentials();
    }

    private function account(bool $staff = false, bool $admin = false): User
    {
        return User::factory()->create(['password' => self::PASSWORD, 'is_account_admin' => $admin,
            'person_id' => $staff ? null : Person::create(['full_name' => 'Session Owner', 'contact_details' => 'Synthetic contact'])->person_id,
            'staff_id' => $staff ? Staff::create(['full_name' => 'Session Staff'])->staff_id : null]);
    }

    private function login(User $user): string
    {
        $this->freshRequest();
        $response = $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => self::PASSWORD], ['Origin' => self::ORIGIN])
            ->assertOk()->assertJsonPath('session_authenticated', true)->assertJsonMissingPath('token');
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->withUnencryptedCookie(config('session.cookie'), $cookie->getValue());
        $this->freshRequest();

        return $cookie->getValue();
    }

    private function freshRequest(): void
    {
        auth()->forgetGuards();
        auth()->shouldUse('web');
        app()->forgetInstance('auth.driver');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
    }

    public function test_user_session_restores_identity_and_logout_revokes_the_old_cookie(): void
    {
        $user = $this->account();
        $this->login($user);
        $this->getJson('/api/me', ['Origin' => self::ORIGIN])->assertOk()->assertJsonPath('user_id', $user->id);
        $this->getJson('/api/accounts', ['Origin' => self::ORIGIN])->assertForbidden();
        $this->patchJson('/api/me/profile', ['contact_details' => 'Updated by owner'], ['Origin' => self::ORIGIN])->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/auth/logout', [], ['Origin' => self::ORIGIN])->assertNoContent();
        $this->freshRequest();
        $this->getJson('/api/me', ['Origin' => self::ORIGIN])->assertUnauthorized();
    }

    public function test_staff_session_does_not_gain_admin_or_user_profile_access(): void
    {
        $user = $this->account(true);
        $this->login($user);
        $this->getJson('/api/me', ['Origin' => self::ORIGIN])->assertOk()->assertJsonPath('permissions.manage_accounts', false);
        $this->getJson('/api/accounts', ['Origin' => self::ORIGIN])->assertForbidden();
        $this->patchJson('/api/me/profile', ['full_name' => 'Forbidden'], ['Origin' => self::ORIGIN])->assertForbidden();
        // Image routes use the same Sanctum session guard, even when the record is missing.
        $this->getJson('/api/items/999999/images', ['Origin' => self::ORIGIN])->assertNotFound();
        $this->getJson('/api/my/finder-reports/999999/images', ['Origin' => self::ORIGIN])->assertForbidden();
    }

    public function test_admin_session_retains_target_restrictions(): void
    {
        $user = $this->account(true, true);
        $member = $this->account();
        $this->login($user);
        $this->getJson('/api/accounts', ['Origin' => self::ORIGIN])->assertOk();
        $this->postJson('/api/accounts/'.$member->id.'/deactivate', [], ['Origin' => self::ORIGIN])->assertForbidden();
        $this->patchJson('/api/accounts/'.$member->id, ['name' => 'Forbidden'], ['Origin' => self::ORIGIN])->assertForbidden();
    }

    public function test_password_change_invalidates_current_and_other_database_sessions(): void
    {
        $user = $this->account();
        $this->login($user);
        $this->postJson('/api/auth/password', ['current_password' => self::PASSWORD, 'password' => 'Changed-session-password-123', 'password_confirmation' => 'Changed-session-password-123'], ['Origin' => self::ORIGIN])->assertNoContent();
        $this->freshRequest();
        $this->getJson('/api/me', ['Origin' => self::ORIGIN])->assertUnauthorized();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_deleted_expired_and_deactivated_sessions_cannot_restore_identity(): void
    {
        $user = $this->account();
        $this->login($user);
        DB::table('sessions')->where('user_id', $user->id)->update(['last_activity' => now()->subMinutes(config('session.lifetime') + 1)->timestamp]);
        $this->freshRequest();
        $this->getJson('/api/me', ['Origin' => self::ORIGIN])->assertUnauthorized();
        $this->login($user);
        $user->forceFill(['deactivated_at' => now()])->save();
        $this->getJson('/api/me', ['Origin' => self::ORIGIN])->assertForbidden();
    }
}
