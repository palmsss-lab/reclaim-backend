<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UsernameAuthenticationTest extends TestCase
{
    private const PASSWORD = 'Isolated-username-password-123';

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
        Notification::fake();
    }

    private function account(array $attributes = []): User
    {
        $person = Person::create(['full_name' => 'Username fixture', 'contact_details' => 'Synthetic private contact']);

        return User::factory()->create($attributes + ['person_id' => $person->person_id, 'password' => self::PASSWORD]);
    }

    private function headers(?User $user = null): array
    {
        auth()->forgetGuards();
        app('auth')->shouldUse('web');

        return $user ? ['Authorization' => 'Bearer '.$user->createToken('username-test', [$user->staff_id ? 'staff' : 'user'])->plainTextToken] : [];
    }

    private function registration(array $attributes = []): array
    {
        return $attributes + ['full_name' => 'New username fixture', 'contact_details' => 'Synthetic contact',
            'email' => 'new-username@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    public function test_registration_normalizes_unique_username_and_preserves_legacy_clients(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['username' => '  Palm.User_1  ']))->assertCreated();
        $user = User::where('email', 'new-username@example.test')->firstOrFail();
        $this->assertSame('palm.user_1', $user->username);
        $this->assertFalse($user->is_account_admin);
        $this->assertNull($user->staff_id);
        $this->assertNotNull($user->person_id);
        $this->assertNull($user->email_verified_at);
        $people = Person::count();
        $this->postJson('/api/auth/register', $this->registration(['username' => 'PALM.USER_1', 'email' => 'collision@example.test']))->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame($people, Person::count());
        $this->postJson('/api/auth/register', $this->registration(['email' => 'legacy@example.test']))->assertCreated();
        $this->assertNull(User::where('email', 'legacy@example.test')->firstOrFail()->username);
    }

    public function test_username_and_both_email_payloads_sign_into_the_same_account(): void
    {
        $user = $this->account(['username' => 'sample.user', 'email' => 'user@example.test']);
        foreach ([['login' => ' SAMPLE.USER '], ['login' => ' USER@EXAMPLE.TEST '], ['email' => 'USER@EXAMPLE.TEST']] as $identifier) {
            $this->postJson('/api/auth/login', $identifier + ['password' => self::PASSWORD], $this->headers())->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('token_type', 'Bearer');
        }
        $this->postJson('/api/auth/login', ['login' => 'sample.user', 'email' => 'other@example.test', 'password' => self::PASSWORD])->assertUnprocessable();
    }

    public function test_invalid_unknown_inactive_and_google_only_logins_do_not_issue_tokens(): void
    {
        $this->account(['username' => 'inactive.user', 'deactivated_at' => now()]);
        $this->account(['username' => 'google.only', 'has_local_password' => false]);
        foreach (['unknown.user', 'inactive.user', 'google.only'] as $login) {
            $this->postJson('/api/auth/login', ['login' => $login, 'password' => self::PASSWORD], $this->headers())->assertUnauthorized()->assertExactJson(['message' => 'The provided credentials are invalid.']);
        }
        $this->account(['username' => 'active.user']);
        $this->postJson('/api/auth/login', ['login' => 'active.user', 'password' => 'wrong'], $this->headers())->assertUnauthorized();
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_invalid_usernames_and_non_scalar_login_are_rejected(): void
    {
        foreach (['ab', 'name@example.test', 'first last', 'éclair', str_repeat('a', 31)] as $username) {
            $this->postJson('/api/auth/register', $this->registration(['username' => $username]))->assertUnprocessable()->assertJsonValidationErrors('username');
        }
        $this->postJson('/api/auth/login', ['login' => ['admin'], 'password' => self::PASSWORD])->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->postJson('/api/auth/login', ['password' => self::PASSWORD])->assertUnprocessable();
    }

    public function test_owner_sets_and_changes_username_without_modifying_identity_or_permissions(): void
    {
        $user = $this->account(['email' => 'unchanged@example.test']);
        $other = $this->account(['username' => 'other.user']);
        $this->patchJson('/api/me/username', ['username' => 'new.user'])->assertUnauthorized();
        $this->patchJson('/api/me/username', ['username' => 'new.user', 'current_password' => 'wrong'], $this->headers($user))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/me/username', ['username' => 'new.user', 'current_password' => self::PASSWORD, 'user_id' => $other->id], $this->headers($user))->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->patchJson('/api/me/username', ['username' => ' New.User ', 'current_password' => self::PASSWORD], $this->headers($user))->assertOk()->assertJsonPath('data.username', 'new.user');
        $this->getJson('/api/me/security', $this->headers($user))->assertOk()->assertJsonPath('data.username', 'new.user');
        $this->assertSame('unchanged@example.test', $user->fresh()->email);
        $this->assertSame($user->person_id, $user->fresh()->person_id);
        $this->assertFalse($user->fresh()->is_account_admin);
        $this->assertSame('other.user', $other->fresh()->username);
        $this->patchJson('/api/me/username', ['username' => 'changed.user', 'current_password' => self::PASSWORD], $this->headers($user))->assertOk();
        $this->postJson('/api/auth/login', ['login' => 'new.user', 'password' => self::PASSWORD], $this->headers())->assertUnauthorized();
        $this->postJson('/api/auth/login', ['login' => 'changed.user', 'password' => self::PASSWORD], $this->headers())->assertOk();
    }

    public function test_staff_and_admin_keep_their_permissions_with_username_login(): void
    {
        foreach ([false, true] as $admin) {
            $staff = Staff::create(['full_name' => 'Username staff fixture']);
            $user = User::factory()->create(['staff_id' => $staff->staff_id, 'is_account_admin' => $admin, 'password' => self::PASSWORD]);
            $username = $admin ? 'admin.handle' : 'staff.handle';
            $this->patchJson('/api/me/username', ['username' => $username, 'current_password' => self::PASSWORD], $this->headers($user))->assertOk();
            $login = $this->postJson('/api/auth/login', ['login' => $username, 'password' => self::PASSWORD], $this->headers())->assertOk()->assertJsonPath('staff.staff_id', $staff->staff_id)->json();
            $this->headers();
            $this->getJson('/api/accounts', ['Authorization' => 'Bearer '.$login['token']])->assertStatus($admin ? 200 : 403);
        }
    }

    public function test_google_only_can_choose_a_handle_without_creating_a_local_password(): void
    {
        $user = $this->account(['has_local_password' => false]);
        DB::table('google_identities')->insert(['user_id' => $user->id, 'subject' => 'username-google-fixture', 'created_at' => now(), 'updated_at' => now()]);
        $this->patchJson('/api/me/username', ['username' => 'google.handle'], $this->headers($user))->assertOk();
        $this->assertFalse($user->fresh()->has_local_password);
        $this->assertDatabaseHas('google_identities', ['user_id' => $user->id, 'subject' => 'username-google-fixture']);
        $this->postJson('/api/auth/login', ['login' => 'google.handle', 'password' => self::PASSWORD], $this->headers())->assertUnauthorized();
        $user->deactivated_at = now();
        $user->save();
        $this->patchJson('/api/me/username', ['username' => 'inactive.handle'], $this->headers($user))->assertForbidden();
    }

    public function test_database_rejects_case_variant_username_duplicates(): void
    {
        $this->account(['username' => 'unique.handle']);
        $other = $this->account();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('users')->where('id', $other->id)->update(['username' => 'UNIQUE.HANDLE']);
    }

    public function test_username_changes_reject_collisions_and_privilege_fields(): void
    {
        $owner = $this->account(['username' => 'owner.handle']);
        $this->account(['username' => 'taken.handle']);
        $this->patchJson('/api/me/username', ['username' => 'TAKEN.HANDLE', 'current_password' => self::PASSWORD], $this->headers($owner))
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->patchJson('/api/me/username', ['username' => 'owner.handle', 'current_password' => self::PASSWORD], $this->headers($owner))->assertOk();
        $this->patchJson('/api/me/username', ['username' => 'changed.handle', 'current_password' => self::PASSWORD, 'is_account_admin' => true], $this->headers($owner))
            ->assertUnprocessable()->assertJsonValidationErrors('is_account_admin');
        $this->assertSame('owner.handle', $owner->fresh()->username);
        $this->assertFalse($owner->fresh()->is_account_admin);
    }

    public function test_login_limit_is_shared_between_username_and_email_identifiers(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['login' => $i % 2 ? 'unknown.user' : 'unknown@example.test', 'password' => self::PASSWORD])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', ['email' => 'unknown@example.test', 'password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_username_change_rate_limit_is_per_account(): void
    {
        $user = $this->account();
        for ($i = 0; $i < 5; $i++) {
            $this->patchJson('/api/me/username', ['username' => 'limited.handle', 'current_password' => self::PASSWORD], $this->headers($user))->assertOk();
        }
        $this->patchJson('/api/me/username', ['username' => 'changed.handle', 'current_password' => self::PASSWORD], $this->headers($user))->assertStatus(429);
        $other = $this->account();
        $this->patchJson('/api/me/username', ['username' => 'other.limit', 'current_password' => self::PASSWORD], $this->headers($other))->assertOk();
        $this->assertSame('limited.handle', $user->fresh()->username);
    }
}
