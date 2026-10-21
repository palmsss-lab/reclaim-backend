<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class PersonProfileAuthorizationTest extends TestCase
{
    use ReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTestDatabase();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            CarbonImmutable::setTestNow();
        });
        $this->createReviewFixtures();
    }

    public function test_staff_can_lookup_and_create_walk_in_finders_but_cannot_change_account_profiles(): void
    {
        $owner = User::factory()->create(['person_id' => $this->people[0]->person_id]);
        Sanctum::actingAs($this->staffUser, ['staff']);
        $path = '/api/people/'.$owner->person_id;
        $this->getJson('/api/people')->assertOk();
        $this->getJson($path)->assertOk();
        $this->postJson('/api/people', ['full_name' => 'Walk-in finder', 'contact_details' => 'Synthetic contact'])->assertCreated();
        foreach (['patch', 'put'] as $method) {
            $this->{$method.'Json'}($path, ['full_name' => 'Forbidden name', 'contact_details' => 'Forbidden contact'])->assertForbidden();
        }
        $this->assertSame('Fictional Claimant 1', $owner->person->fresh()->full_name);
        $this->assertSame('claimant1@example.test', $owner->person->fresh()->contact_details);
        $this->patchJson('/api/me/profile', ['full_name' => 'Not a user'])->assertForbidden();
        $this->getJson('/api/accounts')->assertForbidden();
        // A walk-in record has no account; existing reference-data correction is preserved.
        $this->patchJson('/api/people/'.$this->people[3]->person_id, ['full_name' => 'Walk-in correction'])->assertOk();
    }

    public function test_only_owner_profile_flow_changes_linked_details_even_for_administrators(): void
    {
        $owner = User::factory()->create(['person_id' => $this->people[0]->person_id]);
        $other = User::factory()->create(['person_id' => $this->people[1]->person_id]);
        $this->staffUser->forceFill(['is_account_admin' => true])->save();
        Sanctum::actingAs($this->staffUser, ['staff']);
        $this->patchJson('/api/people/'.$owner->person_id, ['full_name' => 'Admin edit'])->assertForbidden();
        Sanctum::actingAs($owner, ['user']);
        $this->patchJson('/api/people/'.$owner->person_id, ['full_name' => 'Own reference edit'])->assertForbidden();
        $this->patchJson('/api/me/profile', ['full_name' => 'Owner update', 'contact_details' => 'Owner contact'])->assertOk();
        $this->assertSame('Owner update', $owner->person->fresh()->full_name);
        $this->assertSame('Fictional Claimant 2', $other->person->fresh()->full_name);
        $this->patchJson('/api/me/profile', ['person_id' => $other->person_id, 'full_name' => 'Tampered'])->assertUnprocessable();
        $this->assertSame('Owner update', $owner->person->fresh()->full_name);
    }
}
