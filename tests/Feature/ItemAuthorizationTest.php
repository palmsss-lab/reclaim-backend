<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\TransientToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ItemAuthorizationTest extends TestCase
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

    public static function operations(): array
    {
        return ['list' => ['list'], 'intake' => ['intake'], 'transfer' => ['transfer']];
    }

    public static function deniedRequests(): array
    {
        $cases = [];
        foreach (['anonymous', 'invalid-token', 'unlinked', 'limited-token', 'empty-abilities', 'detached-staff'] as $kind) {
            foreach (array_keys(self::operations()) as $operation) {
                $cases[$kind.' '.$operation] = [$kind, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('deniedRequests')]
    public function test_denied_requests_cannot_read_or_modify_records(string $kind, string $operation): void
    {
        $token = null;
        if ($kind === 'invalid-token') {
            $token = 'invalid-token';
        } elseif ($kind !== 'anonymous') {
            $user = $kind === 'unlinked' ? User::factory()->create() : $this->staffUser;
            $abilities = match ($kind) {
                'limited-token' => ['read-only'],
                'empty-abilities' => [],
                default => ['staff'],
            };
            $token = $user->createToken('authorization-test', $abilities)->plainTextToken;
            if ($kind === 'detached-staff') {
                // An issued staff token cannot replace the account's current linkage.
                $user->staff()->dissociate()->save();
            }
        }
        $expected = in_array($kind, ['anonymous', 'invalid-token'], true) ? 401 : 403;
        $before = $this->databaseSnapshot();
        foreach ([false, true] as $invalidPayload) {
            $this->resetAuthentication();
            $response = $this->requestOperation($operation, $token, $invalidPayload);
            $response->assertStatus($expected)->assertJsonPath('message', $expected === 401 ? 'Unauthenticated.' : 'This action is unauthorized.');
            $this->assertSame($before, $this->databaseSnapshot());
        }
    }

    #[DataProvider('operations')]
    public function test_real_staff_bearer_tokens_allow_valid_workflows(string $operation): void
    {
        $token = $this->staffUser->createToken('authorization-test', ['staff'])->plainTextToken;
        $this->resetAuthentication();
        $before = $this->databaseSnapshot();
        $response = $this->requestOperation($operation, $token);
        $this->assertSuccessfulWorkflow($operation, $response);
        if ($operation === 'list') {
            $this->assertSame($before, $this->databaseSnapshot());
        }
    }

    #[DataProvider('operations')]
    public function test_authenticated_web_guard_staff_uses_sanctum_transient_token(string $operation): void
    {
        $this->resetAuthentication();
        $this->actingAs($this->staffUser->fresh(), 'web');
        $before = $this->databaseSnapshot();
        $response = $this->requestOperation($operation);
        $this->assertSuccessfulWorkflow($operation, $response);
        if ($operation === 'list') {
            $this->assertSame($before, $this->databaseSnapshot());
        }
        $this->assertInstanceOf(TransientToken::class, auth('web')->user()->currentAccessToken());
        $this->assertSame(0, $this->staffUser->tokens()->count());
    }

    #[DataProvider('operations')]
    public function test_web_guard_without_staff_link_is_forbidden(string $operation): void
    {
        $user = User::factory()->create();
        $before = $this->databaseSnapshot();
        $this->resetAuthentication();
        $this->actingAs($user, 'web');
        $this->requestOperation($operation)->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
        $this->assertInstanceOf(TransientToken::class, auth('web')->user()->currentAccessToken());
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public static function actorFields(): array
    {
        $cases = [];
        foreach (['intake', 'transfer'] as $operation) {
            foreach (['staff_id', 'received_by', 'assigned_by'] as $field) {
                $cases[$operation.' '.$field] = [$operation, $field];
            }
        }

        return $cases;
    }

    #[DataProvider('actorFields')]
    public function test_client_cannot_impersonate_another_staff_member(string $operation, string $field): void
    {
        $other = Staff::create(['full_name' => 'Fictional Other Staff']);
        $token = $this->staffUser->createToken('authorization-test', ['staff'])->plainTextToken;
        $before = $this->databaseSnapshot();
        $this->resetAuthentication();
        $this->requestOperation($operation, $token, false, [$field => $other->staff_id])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->databaseSnapshot());
        // Removing the forbidden field succeeds and records the authenticated actor.
        $this->resetAuthentication();
        $this->assertSuccessfulWorkflow($operation, $this->requestOperation($operation, $token));
    }

    private function resetAuthentication(): void
    {
        auth()->forgetGuards();
        auth()->shouldUse('web');
        $this->flushHeaders();
    }

    private function requestOperation(string $operation, ?string $token = null, bool $invalid = false, array $extra = []): TestResponse
    {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

        return match ($operation) {
            'list' => $this->getJson('/api/items?category_id='.($invalid ? 'bad' : $this->category->category_id), $headers),
            'intake' => $this->postJson('/api/items', array_merge($invalid ? [] : [
                'finder_id' => $this->people[0]->person_id,
                'category_id' => $this->category->category_id,
                'initial_location_id' => $this->location->location_id,
                'item_description' => 'Fictional authorization wallet',
                'found_place' => 'Test library',
                'found_at' => '2026-09-29T08:00:00+08:00',
            ], $extra), $headers),
            'transfer' => $this->postJson('/api/items/'.$this->item->item_id.'/transfers', array_merge([
                'location_id' => $invalid ? 'bad' : $this->destination->location_id,
            ], $extra), $headers),
        };
    }

    private function assertSuccessfulWorkflow(string $operation, TestResponse $response): void
    {
        if ($operation === 'list') {
            $response->assertOk()->assertJsonPath('data.0.item_id', $this->item->item_id)->assertJsonPath('total', 1);

            return;
        }
        if ($operation === 'intake') {
            $response->assertCreated();
            $id = $response->json('item_id');
            $this->assertIsInt($id);
            $this->assertDatabaseHas('found_item', ['item_id' => $id, 'received_by' => $this->staffUser->staff_id]);
            $this->assertDatabaseHas('storage_assignment', [
                'item_id' => $id, 'assignment_no' => 1, 'assigned_by' => $this->staffUser->staff_id,
                'location_id' => $this->location->location_id, 'removed_at' => null,
            ]);

            return;
        }
        $response->assertOk()->assertJsonPath('item_id', $this->item->item_id);
        $this->assertDatabaseHas('storage_assignment', [
            'item_id' => $this->item->item_id, 'assignment_no' => 2, 'assigned_by' => $this->staffUser->staff_id,
            'location_id' => $this->destination->location_id, 'removed_at' => null,
        ]);
        $old = DB::table('storage_assignment')->where('item_id', $this->item->item_id)->where('assignment_no', 1)->first();
        $this->assertNotNull($old->removed_at);
        $this->assertSame(1, DB::table('storage_assignment')->where('item_id', $this->item->item_id)->whereNull('removed_at')->count());
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'person' => ['person_id'], 'staff' => ['staff_id'], 'category' => ['category_id'],
            'storage_location' => ['location_id'], 'found_item' => ['item_id'],
            'claim' => ['item_id', 'claimant_id'], 'storage_assignment' => ['item_id', 'assignment_no'],
            'users' => ['id'], 'personal_access_tokens' => ['id'],
        ] as $table => $keys) {
            $query = DB::table($table);
            foreach ($keys as $key) {
                $query->orderBy($key);
            }
            $snapshot[$table] = $query->get()->map(function ($row) use ($table): array {
                $attributes = (array) $row;
                if ($table === 'personal_access_tokens') {
                    // Sanctum intentionally records token use even on a denied request.
                    unset($attributes['last_used_at'], $attributes['updated_at']);
                }

                return $attributes;
            })->all();
        }

        return $snapshot;
    }
}
