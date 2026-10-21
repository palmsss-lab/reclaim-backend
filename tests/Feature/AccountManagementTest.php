<?php

namespace Tests\Feature;

use App\Actions\Accounts\AccountAdministration;
use App\Models\Category;
use App\Models\Person;
use App\Models\Staff;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    private User $admin;

    private User $staffUser;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        });

        $adminStaff = Staff::create(['full_name' => 'Fictional Account Admin Staff']);
        $this->admin = User::factory()->create(['password' => 'Admin-test-password-123']);
        $this->admin->staff()->associate($adminStaff);
        $this->admin->is_account_admin = true;
        $this->admin->save();

        $staff = Staff::create(['full_name' => 'Fictional Ordinary Staff']);
        $this->staffUser = User::factory()->create(['password' => 'Staff-test-password-123']);
        $this->staffUser->staff()->associate($staff)->save();
        $this->adminToken = $this->admin->createToken('account-admin-test', ['staff'])->plainTextToken;
    }

    public function test_admin_can_create_list_view_and_update_accounts_without_exposing_secrets(): void
    {
        $created = $this->postJson('/api/accounts', [
            'name' => 'Display name',
            'email' => 'new.staff@example.test',
            'staff_full_name' => 'Canonical Staff Name',
            'password' => 'New-account-test-password-123',
            'password_confirmation' => 'New-account-test-password-123',
        ], $this->adminHeaders())->assertCreated()
            ->assertJsonPath('data.name', 'Display name')
            ->assertJsonPath('data.email', 'new.staff@example.test')
            ->assertJsonPath('data.is_account_admin', false)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.staff.full_name', 'Canonical Staff Name')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.password_hash')
            ->assertJsonMissingPath('data.token');
        $id = $created->json('data.id');
        $account = User::findOrFail($id);
        $this->assertTrue(password_verify('New-account-test-password-123', $account->password));
        $this->assertNotSame('New-account-test-password-123', $account->password);
        $this->assertFalse($account->is_account_admin);
        $staffId = $account->staff_id;

        $this->getJson('/api/accounts?q=Canonical&per_page=1', $this->adminHeaders())
            ->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/accounts/'.$id, $this->adminHeaders())
            ->assertOk()->assertJsonPath('data.staff.staff_id', $staffId)
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');

        $this->patchJson('/api/accounts/'.$id, ['name' => 'Updated display'], $this->adminHeaders())
            ->assertOk()->assertJsonPath('data.name', 'Updated display')
            ->assertJsonPath('data.email', 'new.staff@example.test')
            ->assertJsonPath('data.staff.full_name', 'Canonical Staff Name');

        $this->putJson('/api/accounts/'.$id, ['name' => 'Incomplete'], $this->adminHeaders())
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson('/api/accounts/'.$id, [
            'name' => 'Full update',
            'email' => 'new.staff@example.test',
        ], $this->adminHeaders())->assertOk()->assertJsonPath('data.email', 'new.staff@example.test');
        $this->patchJson('/api/accounts/'.$id, ['email' => $this->staffUser->email], $this->adminHeaders())
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('new.staff@example.test', User::findOrFail($id)->email);

        $this->patchJson('/api/accounts/'.$id, ['is_account_admin' => true], $this->adminHeaders())
            ->assertUnprocessable()->assertJsonValidationErrors('is_account_admin');
        $this->assertDatabaseHas('staff', ['staff_id' => $staffId, 'full_name' => 'Canonical Staff Name']);
    }

    public function test_non_admin_staff_cannot_manage_accounts_and_permissions_use_current_database_state(): void
    {
        $token = $this->staffUser->createToken('ordinary-staff', ['staff'])->plainTextToken;
        foreach ([
            $this->getJson('/api/accounts', ['Authorization' => 'Bearer '.$token]),
            $this->postJson('/api/accounts', [], ['Authorization' => 'Bearer '.$token]),
            $this->getJson('/api/accounts/'.$this->admin->id, ['Authorization' => 'Bearer '.$token]),
            $this->patchJson('/api/accounts/'.$this->admin->id, ['name' => 'Forbidden'], ['Authorization' => 'Bearer '.$token]),
            $this->putJson('/api/accounts/'.$this->admin->id, [], ['Authorization' => 'Bearer '.$token]),
            $this->putJson('/api/accounts/'.$this->staffUser->id.'/administrator', ['is_account_admin' => true], ['Authorization' => 'Bearer '.$token]),
            $this->postJson('/api/accounts/'.$this->admin->id.'/deactivate', [], ['Authorization' => 'Bearer '.$token]),
        ] as $response) {
            $response->assertForbidden();
        }
        $this->assertNull($this->admin->fresh()->deactivated_at);
        $this->assertFalse($this->staffUser->fresh()->is_account_admin);
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJsonPath('permissions.manage_accounts', false);
        $this->resetAuthentication();
        $this->getJson('/api/me', $this->adminHeaders())
            ->assertOk()->assertJsonPath('permissions.manage_accounts', true);
        $this->resetAuthentication();
        $this->getJson('/api/accounts')->assertUnauthorized();

        // The token remains staff-capable, but the current users row revokes administration immediately.
        DB::table('users')->where('id', $this->admin->id)->update(['is_account_admin' => false]);
        $this->getJson('/api/accounts', $this->adminHeaders())->assertForbidden();
        $this->getJson('/api/me', $this->adminHeaders())
            ->assertOk()->assertJsonPath('permissions.manage_accounts', false);
        $this->assertSame(2, DB::table('staff')->count());
    }

    public function test_active_admin_can_use_the_configured_session_authentication_mode(): void
    {
        auth()->forgetGuards();
        $this->actingAs($this->admin->fresh(), 'web');
        $this->getJson('/api/accounts')->assertOk();
    }

    public function test_creation_validation_duplicates_and_database_failure_roll_back_staff_creation(): void
    {
        $before = DB::table('staff')->count();
        $this->postJson('/api/accounts', [
            'name' => 'Invalid account', 'email' => 'bad@example.test', 'staff_full_name' => 'Staff',
            'password' => 'short', 'password_confirmation' => 'mismatch', 'is_account_admin' => true,
        ], $this->adminHeaders())->assertUnprocessable()->assertJsonValidationErrors([
            'password', 'is_account_admin',
        ]);
        $this->assertSame($before, DB::table('staff')->count());

        $payload = [
            'name' => 'Duplicate account', 'email' => $this->staffUser->email,
            'staff_full_name' => 'Would roll back', 'password' => 'New-account-test-password-123',
            'password_confirmation' => 'New-account-test-password-123',
        ];
        $this->postJson('/api/accounts', $payload, $this->adminHeaders())->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->assertSame($before, DB::table('staff')->count());

        // Bypass request validation to exercise the unique-index race after the staff insert.
        try {
            app(AccountAdministration::class)->create($this->admin, $payload);
            $this->fail('Duplicate email should fail at the database unique constraint.');
        } catch (ValidationException) {
            $this->assertSame($before, DB::table('staff')->count());
        }
    }

    public function test_password_change_requires_current_password_revokes_tokens_and_never_returns_credentials(): void
    {
        $token = $this->staffUser->createToken('password-change', ['staff'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];
        $oldHash = $this->staffUser->password;

        $this->postJson('/api/auth/password', [
            'current_password' => 'incorrect-current-password',
            'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($oldHash, $this->staffUser->fresh()->password);
        $this->assertSame(1, $this->staffUser->tokens()->count());

        $this->postJson('/api/auth/password', [
            'current_password' => 'Staff-test-password-123',
            'password' => 'Changed-password-123', 'password_confirmation' => 'Changed-password-123',
        ], $headers)->assertNoContent();
        $this->assertSame(0, $this->staffUser->tokens()->count());
        $this->postJson('/api/auth/login', [
            'email' => $this->staffUser->email, 'password' => 'Staff-test-password-123',
        ])->assertUnauthorized();
        $this->postJson('/api/auth/login', [
            'email' => $this->staffUser->email, 'password' => 'Changed-password-123',
        ])->assertOk()->assertJsonMissingPath('password');
    }

    public function test_deactivation_revokes_tokens_blocks_login_and_session_requests_and_preserves_staff_identity(): void
    {
        $targetStaffId = $this->staffUser->staff_id;
        $finder = Person::create(['full_name' => 'Fictional Deactivation Finder', 'contact_details' => 'finder@example.test']);
        $claimant = Person::create(['full_name' => 'Fictional Deactivation Claimant', 'contact_details' => 'claimant@example.test']);
        $category = Category::create(['category_name' => 'Fictional Deactivation Category']);
        $location = StorageLocation::create(['location_code' => 'DEACTIVATE-HISTORY', 'location_description' => 'History remains']);
        $itemId = DB::table('found_item')->insertGetId([
            'finder_id' => $finder->person_id, 'received_by' => $targetStaffId, 'category_id' => $category->category_id,
            'item_description' => 'Fictional historical item', 'found_place' => 'Test desk',
            'found_at' => '2026-09-30 09:00:00.000000', 'received_at' => '2026-09-30 10:00:00.000000',
        ]);
        DB::table('storage_assignment')->insert([
            'item_id' => $itemId, 'assignment_no' => 1, 'location_id' => $location->location_id,
            'assigned_by' => $targetStaffId, 'assigned_at' => '2026-09-30 10:00:00.000000', 'removed_at' => null,
        ]);
        DB::table('claim')->insert([
            'item_id' => $itemId, 'claimant_id' => $claimant->person_id, 'submitted_at' => '2026-09-30 11:00:00.000000',
            'evidence_text' => 'Fictional retained review', 'outcome' => 'REJECTED', 'reviewed_by' => $targetStaffId,
            'reviewed_at' => '2026-09-30 11:01:00.000000',
        ]);
        $token = $this->staffUser->createToken('deactivate-target', ['staff'])->plainTextToken;
        DB::table('sessions')->insert([

            'id' => 'account-test-session', 'user_id' => $this->staffUser->id,
            'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time(),
        ]);

        $this->postJson('/api/accounts/'.$this->staffUser->id.'/deactivate', [], $this->adminHeaders())->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $this->staffUser->id, 'staff_id' => $targetStaffId]);
        $this->assertDatabaseHas('staff', ['staff_id' => $targetStaffId, 'full_name' => 'Fictional Ordinary Staff']);
        $this->assertDatabaseHas('found_item', ['item_id' => $itemId, 'received_by' => $targetStaffId]);
        $this->assertDatabaseHas('storage_assignment', ['item_id' => $itemId, 'assignment_no' => 1, 'assigned_by' => $targetStaffId]);
        $this->assertDatabaseHas('claim', ['item_id' => $itemId, 'claimant_id' => $claimant->person_id, 'reviewed_by' => $targetStaffId, 'outcome' => 'REJECTED']);
        $this->assertSame(0, $this->staffUser->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->staffUser->id]);
        auth()->forgetGuards();
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->postJson('/api/auth/login', [
            'email' => $this->staffUser->email, 'password' => 'Staff-test-password-123',
        ])->assertUnauthorized();

        auth()->forgetGuards();
        $this->actingAs($this->staffUser->fresh(), 'web');
        $this->getJson('/api/me')->assertForbidden()->assertJsonPath('message', 'This account is inactive.');
    }

    public function test_last_active_administrator_cannot_be_demoted_or_deactivated(): void
    {
        $this->putJson('/api/accounts/'.$this->admin->id.'/administrator', ['is_account_admin' => false], $this->adminHeaders())
            ->assertStatus(409);
        $this->postJson('/api/accounts/'.$this->admin->id.'/deactivate', [], $this->adminHeaders())
            ->assertStatus(409);
        $this->assertTrue((bool) $this->admin->fresh()->is_account_admin);
        $this->assertNull($this->admin->fresh()->deactivated_at);

        $this->putJson('/api/accounts/'.$this->staffUser->id.'/administrator', ['is_account_admin' => true], $this->adminHeaders())
            ->assertOk()->assertJsonPath('data.is_account_admin', true);
        $this->postJson('/api/accounts/'.$this->admin->id.'/deactivate', [], $this->adminHeaders())->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $this->staffUser->id, 'is_account_admin' => true, 'deactivated_at' => null]);
    }

    public function test_staff_and_members_cannot_deactivate_or_promote_a_member(): void
    {
        $person = Person::create(['full_name' => 'Fictional Member', 'contact_details' => 'member@example.test']);
        $member = User::factory()->create();
        $member->person()->associate($person)->save();

        foreach ([$this->staffUser->createToken('staff-denied', ['staff']), $member->createToken('member-denied', ['user'])] as $token) {
            $this->resetAuthentication();
            $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];
            $this->postJson('/api/accounts/'.$member->id.'/deactivate', [], $headers)->assertForbidden();
            $this->putJson('/api/accounts/'.$member->id.'/administrator', ['is_account_admin' => true], $headers)->assertForbidden();
            $this->patchJson('/api/accounts/'.$member->id, ['name' => 'Forbidden edit'], $headers)->assertForbidden();
        }
        $this->assertNull($member->fresh()->deactivated_at);
        $this->assertFalse($member->fresh()->is_account_admin);

        $this->resetAuthentication();
        $this->putJson('/api/accounts/'.$member->id.'/administrator', ['is_account_admin' => true], $this->adminHeaders())->assertForbidden();
        $this->assertFalse($member->fresh()->is_account_admin);

        // A legacy flag on an unlinked account must not defeat last-admin protection.
        DB::table('users')->where('id', $member->id)->update(['is_account_admin' => true]);
        $this->postJson('/api/accounts/'.$this->admin->id.'/deactivate', [], $this->adminHeaders())->assertStatus(409);
        $this->putJson('/api/accounts/'.$this->admin->id.'/administrator', ['is_account_admin' => false], $this->adminHeaders())->assertStatus(409);
        $this->postJson('/api/accounts/'.$member->id.'/deactivate', [], $this->adminHeaders())->assertForbidden();
        $this->assertNull($member->fresh()->deactivated_at);
        $this->assertNull($this->admin->fresh()->deactivated_at);
    }

    public function test_user_directory_is_read_only_for_all_staff_and_owner_self_service_remains_available(): void
    {
        $person = Person::create(['full_name' => 'Directory Owner', 'contact_details' => 'Synthetic contact']);
        $member = User::factory()->create(['name' => 'Directory Owner', 'person_id' => $person->person_id, 'password' => 'Owner-original-password-123']);
        $memberToken = $member->createToken('owner-self-service', ['user'])->plainTextToken;
        $ordinaryToken = $this->staffUser->createToken('directory-denied', ['staff'])->plainTextToken;
        $before = $member->fresh()->getAttributes();
        foreach ([$this->adminToken, $ordinaryToken] as $token) {
            $headers = ['Authorization' => 'Bearer '.$token];
            foreach (['patch', 'put'] as $method) {
                $this->resetAuthentication();
                $this->{$method.'Json'}('/api/accounts/'.$member->id, ['name' => 'Forbidden', 'email' => 'forbidden@example.test'], $headers)->assertForbidden();
            }
            foreach ([true, false] as $permission) {
                $this->resetAuthentication();
                $this->putJson('/api/accounts/'.$member->id.'/administrator', ['is_account_admin' => $permission], $headers)->assertForbidden();
            }
            $this->resetAuthentication();
            $this->postJson('/api/accounts/'.$member->id.'/deactivate', [], $headers)->assertForbidden();
        }
        $this->assertSame($before, $member->fresh()->getAttributes());
        $this->resetAuthentication();
        $this->getJson('/api/accounts?q=Directory', $this->adminHeaders())->assertOk()->assertJsonPath('data.0.can_manage', false);
        $this->getJson('/api/accounts/'.$member->id, $this->adminHeaders())->assertOk()->assertJsonPath('data.can_manage', false);
        $this->resetAuthentication();
        $this->getJson('/api/accounts', ['Authorization' => 'Bearer '.$ordinaryToken])->assertForbidden();
        $this->getJson('/api/accounts/'.$member->id, ['Authorization' => 'Bearer '.$ordinaryToken])->assertForbidden();
        // Direct callers of the transactional actions must enforce the same target restriction.
        $actions = app(AccountAdministration::class);
        foreach ([fn () => $actions->update($this->admin, $member, ['name' => 'Bypass']),
            fn () => $actions->setAdministrator($this->admin, $member, false),
            fn () => $actions->deactivate($this->admin, $member)] as $attempt) {
            try {
                $attempt();
                $this->fail('User-account mutation must be forbidden.');
            } catch (HttpException $failure) {
                $this->assertSame(403, $failure->getStatusCode());
            }
        }
        $this->assertSame($before, $member->fresh()->getAttributes());
        $this->resetAuthentication();
        $ownerHeaders = ['Authorization' => 'Bearer '.$memberToken];
        $this->patchJson('/api/me/profile', ['full_name' => 'Owner permitted update', 'contact_details' => 'Owner contact'], $ownerHeaders)->assertOk();
        $this->assertSame('Owner permitted update', $person->fresh()->full_name);
        $this->postJson('/api/auth/password', ['current_password' => 'Owner-original-password-123', 'password' => 'Owner-changed-password-123', 'password_confirmation' => 'Owner-changed-password-123'], $ownerHeaders)->assertNoContent();
        $this->assertTrue(password_verify('Owner-changed-password-123', $member->fresh()->password));
        $this->assertNull($member->fresh()->deactivated_at);
        $this->assertFalse($member->fresh()->is_account_admin);
    }

    public function test_identity_permission_respects_token_ability_and_current_active_state(): void
    {
        $restricted = $this->admin->createToken('limited-ability', ['user'])->plainTextToken;
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$restricted])->assertOk()->assertJsonPath('permissions.manage_accounts', false);
        $this->postJson('/api/accounts/'.$this->staffUser->id.'/deactivate', [], ['Authorization' => 'Bearer '.$restricted])->assertForbidden();

        DB::table('users')->where('id', $this->admin->id)->update(['deactivated_at' => now()]);
        $this->getJson('/api/me', $this->adminHeaders())->assertForbidden();
        $this->postJson('/api/accounts/'.$this->staffUser->id.'/deactivate', [], $this->adminHeaders())->assertForbidden();
        $this->assertNull($this->staffUser->fresh()->deactivated_at);
    }

    private function resetAuthentication(): void
    {
        auth()->forgetGuards();
        auth()->shouldUse('web');
        $this->flushHeaders();
    }

    private function adminHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->adminToken];
    }
}
