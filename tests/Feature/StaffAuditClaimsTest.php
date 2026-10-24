<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class StaffAuditClaimsTest extends TestCase
{
    use ReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTestDatabase();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) DB::rollBack();
            CarbonImmutable::setTestNow();
        });
        $this->createReviewFixtures();
        Sanctum::actingAs($this->staffUser, ['staff']);
    }

    public function test_people_expose_only_account_eligibility_and_preserve_owner_edit_denial(): void
    {
        $owner = User::factory()->create(['person_id' => $this->people[0]->person_id]);
        $this->getJson('/api/people/'.$owner->person_id)->assertOk()
            ->assertJsonPath('data.is_account_holder', true)->assertJsonMissingPath('data.user_id');
        $this->getJson('/api/people/'.$this->people[1]->person_id)->assertOk()
            ->assertJsonPath('data.is_account_holder', false);
        $this->getJson('/api/people')->assertOk()->assertJsonStructure(['data' => [['person_id', 'is_account_holder']]]);
        $this->patchJson('/api/people/'.$owner->person_id, ['full_name' => 'Forbidden'])->assertForbidden();
        $this->assertSame('Fictional Claimant 1', $owner->person->fresh()->full_name);
        $count = DB::table('person')->count();
        $this->patchJson('/api/people/'.$this->people[1]->person_id, ['full_name' => 'Corrected walk-in'])
            ->assertOk()->assertJsonPath('data.person_id', $this->people[1]->person_id)
            ->assertJsonPath('data.is_account_holder', false);
        $this->assertSame($count, DB::table('person')->count());
    }

    public function test_staff_cannot_create_a_claim_as_an_account_holder_but_walk_in_claims_remain_pending_and_unique(): void
    {
        User::factory()->create(['person_id' => $this->people[0]->person_id]);
        $path = '/api/items/'.$this->item->item_id.'/claims';
        DB::table('claim')->where('item_id', $this->item->item_id)->delete();
        $snapshot = $this->snapshot();
        $this->postJson($path, ['claimant_id' => $this->people[0]->person_id, 'evidence_text' => 'Private marks'])->assertForbidden();
        $this->assertSame($snapshot, $this->snapshot());
        $body = ['claimant_id' => $this->people[1]->person_id, 'evidence_text' => '  Private marks  '];
        $this->postJson($path, $body)->assertCreated()->assertJsonPath('data.outcome', 'PENDING')
            ->assertJsonPath('data.reviewed_at', null)->assertJsonPath('data.reviewed_by', null);
        $this->postJson($path, $body)->assertConflict();
        $this->postJson($path, [...$body, 'outcome' => 'RETURNED', 'reviewed_by' => $this->staffUser->staff_id])->assertUnprocessable();
        $this->postJson($path, [...$body, 'claimant_id' => $this->people[2]->person_id, 'evidence_text' => '   '])->assertUnprocessable();
        $this->assertSame(1, DB::table('claim')->where('item_id', $this->item->item_id)->count());
        $this->assertSame($snapshot['storage'], $this->snapshot()['storage']);
    }
}
