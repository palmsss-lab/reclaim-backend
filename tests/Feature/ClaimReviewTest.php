<?php

namespace Tests\Feature;

use App\Actions\Claims\RejectClaim;
use App\Actions\Items\ReturnFoundItem;
use App\Actions\Items\TransferFoundItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ClaimReviewTest extends TestCase
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
        Sanctum::actingAs($this->staffUser, ['staff']);
    }

    public function test_rejection_records_authenticated_reviewer_and_preserves_evidence_and_storage(): void
    {
        $before = $this->snapshot();
        $this->postJson($this->reviewUrl('reject'), [])->assertOk()
            ->assertJsonPath('data.outcome', 'REJECTED')
            ->assertJsonPath('data.reviewed_by', $this->staffUser->staff_id)
            ->assertJsonPath('data.reviewed_at', '2026-09-30T12:00:00.654321+08:00')
            ->assertJsonPath('data.evidence_text', 'Original evidence 0');
        $after = $this->snapshot();
        $this->assertSame($before['storage'], $after['storage']);
        foreach ($before['claims'] as $index => $claim) {
            $this->assertSame($claim['evidence_text'], $after['claims'][$index]['evidence_text']);
            if ($claim['claimant_id'] !== $this->people[0]->person_id) {
                $this->assertSame($claim, $after['claims'][$index]);
            }
        }
    }

    public function test_return_reviews_pending_claims_closes_only_current_storage_and_keeps_history(): void
    {
        $this->setClock('11:30:00.500000');
        app(TransferFoundItem::class)->execute($this->item->item_id, $this->destination->location_id, $this->staffUser->staff_id);
        $this->setClock('12:00:00.654321');
        $before = $this->snapshot();
        $this->postJson($this->reviewUrl('return'), [])->assertOk()
            ->assertJsonPath('data.outcome', 'RETURNED')
            ->assertJsonPath('data.reviewed_by', $this->staffUser->staff_id)
            ->assertJsonPath('data.reviewed_at', '2026-09-30T12:00:00.654321+08:00');
        $after = $this->snapshot();
        $this->assertSame($before['storage'][0], $after['storage'][0]);
        $this->assertSame('2026-09-30 12:00:00.654321', $after['storage'][1]['removed_at']);
        $this->assertSame($before['claims'][2], $after['claims'][2]);
        foreach ([0 => 'RETURNED', 1 => 'REJECTED'] as $index => $outcome) {
            $this->assertDatabaseHas('claim', [
                'item_id' => $this->item->item_id, 'claimant_id' => $this->people[$index]->person_id,
                'outcome' => $outcome, 'reviewed_by' => $this->staffUser->staff_id,
                'reviewed_at' => '2026-09-30 12:00:00.654321', 'evidence_text' => 'Original evidence '.$index,
            ]);
        }
        $this->assertSame(0, DB::table('storage_assignment')->where('item_id', $this->item->item_id)->whereNull('removed_at')->count());
        $this->getJson('/api/items')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_rejection_accepts_submission_boundary_and_normalizes_timezone(): void
    {
        $this->postJson($this->reviewUrl('reject'), ['reviewed_at' => '2026-09-30T03:00:00.100000Z'])
            ->assertOk()->assertJsonPath('data.reviewed_at', '2026-09-30T11:00:00.100000+08:00');
    }

    public function test_return_accepts_latest_pending_submission_boundary(): void
    {
        $this->postJson($this->reviewUrl('return'), ['returned_at' => '2026-09-30T03:10:00.200000Z'])
            ->assertOk()->assertJsonPath('data.reviewed_at', '2026-09-30T11:10:00.200000+08:00');
    }

    public static function invalidDates(): array
    {
        return [
            'malformed review' => ['reject', 'reviewed_at', 'not-a-date'],
            'impossible calendar date' => ['reject', 'reviewed_at', '2026-02-30T10:00:00+08:00'],
            'null review' => ['reject', 'reviewed_at', null],
            'future review' => ['reject', 'reviewed_at', '2026-10-01T10:00:00+08:00'],
            'review before receipt' => ['reject', 'reviewed_at', '2026-09-30T09:00:00+08:00'],
            'review before submission' => ['reject', 'reviewed_at', '2026-09-30T10:59:59.999999+08:00'],
            'malformed return' => ['return', 'returned_at', 'not-a-date'],
            'null return' => ['return', 'returned_at', null],
            'future return' => ['return', 'returned_at', '2026-10-01T10:00:00+08:00'],
            'return before receipt' => ['return', 'returned_at', '2026-09-30T09:00:00+08:00'],
            'return before selected submission' => ['return', 'returned_at', '2026-09-30T10:59:59.999999+08:00'],
            'return before another pending submission' => ['return', 'returned_at', '2026-09-30T11:05:00+08:00'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_leave_all_state_unchanged(string $operation, string $field, mixed $value): void
    {
        $before = $this->snapshot();
        $this->postJson($this->reviewUrl($operation), [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_return_must_be_strictly_after_current_placement(): void
    {
        $this->setClock('11:30:00.500000');
        app(TransferFoundItem::class)->execute($this->item->item_id, $this->destination->location_id, $this->staffUser->staff_id);
        $this->setClock('12:00:00.654321');
        $before = $this->snapshot();
        foreach (['11:29:00.000000', '11:30:00.500000'] as $time) {
            $this->postJson($this->reviewUrl('return'), ['returned_at' => '2026-09-30T'.$time.'+08:00'])
                ->assertUnprocessable()->assertJsonValidationErrors('returned_at');
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_default_clock_before_submission_is_invalid_for_both_operations(): void
    {
        $this->setClock('10:30:00.000000');
        $before = $this->snapshot();
        foreach (['reject' => 'reviewed_at', 'return' => 'returned_at'] as $operation => $field) {
            $this->postJson($this->reviewUrl($operation), [])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unlinked_account_and_insufficient_token_ability_are_forbidden(): void
    {
        $before = $this->snapshot();
        foreach (['reject', 'return'] as $operation) {
            Sanctum::actingAs(User::factory()->create(), ['staff']);
            $this->postJson($this->reviewUrl($operation), [])->assertForbidden();
            Sanctum::actingAs($this->staffUser, ['read-only']);
            $this->postJson($this->reviewUrl($operation), [])->assertForbidden();
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_anonymous_requests_are_unauthorized(): void
    {
        auth()->forgetGuards();
        foreach (['reject', 'return'] as $operation) {
            $this->postJson($this->reviewUrl($operation), [])->assertUnauthorized();
        }
    }

    public function test_client_cannot_override_reviewer_outcome_evidence_or_composite_identifiers(): void
    {
        $before = $this->snapshot();
        foreach (['reject', 'return'] as $operation) {
            $this->postJson($this->reviewUrl($operation), [
                'reviewed_by' => 999, 'outcome' => 'APPROVED', 'evidence_text' => 'Replacement',
                'item_id' => 999, 'claimant_id' => $this->people[1]->person_id,
                'submitted_at' => '2026-09-30T08:00:00+08:00',
            ])->assertUnprocessable()->assertJsonValidationErrors(['reviewed_by', 'outcome', 'evidence_text', 'item_id', 'claimant_id', 'submitted_at']);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_composite_scope_and_missing_item_are_enforced(): void
    {
        $before = $this->snapshot();
        foreach (['reject', 'return'] as $operation) {
            $this->postJson($this->reviewUrl($operation, 3), [])->assertNotFound();
            $this->postJson($this->reviewUrl($operation, 0, 999999999), [])->assertNotFound();
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_rejection_and_return_are_final_and_repeated_requests_conflict(): void
    {
        $this->postJson($this->reviewUrl('reject'), [])->assertOk();
        $afterRejection = $this->snapshot();
        $this->postJson($this->reviewUrl('reject'), [])->assertConflict();
        $this->postJson($this->reviewUrl('return'), [])->assertConflict();
        $this->assertSame($afterRejection, $this->snapshot());

        $this->postJson($this->reviewUrl('return', 1), [])->assertOk();
        $afterReturn = $this->snapshot();
        $this->postJson($this->reviewUrl('return', 1), [])->assertConflict();
        $this->postJson($this->reviewUrl('return'), [])->assertConflict();
        $this->postJson($this->reviewUrl('reject', 1), [])->assertConflict();
        $this->assertSame($afterReturn, $this->snapshot());
    }

    public function test_returned_item_cannot_be_transferred_or_receive_new_claims(): void
    {
        $this->postJson($this->reviewUrl('return'), [])->assertOk();
        $before = $this->snapshot();
        $this->postJson('/api/items/'.$this->item->item_id.'/transfers', ['location_id' => $this->destination->location_id])->assertConflict();
        $this->postJson('/api/items/'.$this->item->item_id.'/claims', ['claimant_id' => $this->people[3]->person_id, 'evidence_text' => 'New evidence'])->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_return_rejects_missing_open_assignment_without_partial_changes(): void
    {
        DB::table('storage_assignment')->where('item_id', $this->item->item_id)->where('assignment_no', 1)
            ->update(['removed_at' => '2026-09-30 11:30:00']);
        $before = $this->snapshot();
        $this->postJson($this->reviewUrl('return'), [])->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_invalid_reviewer_foreign_key_rolls_back_rejection(): void
    {
        $before = $this->snapshot();
        try {
            app(RejectClaim::class)->execute($this->item->item_id, $this->people[0]->person_id, 999999999);
            $this->fail('The reviewer foreign key must be enforced.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function failureStages(): array
    {
        return [
            'after selected claim update' => ['selected'],
            'after other pending claims update' => ['other'],
            'after storage closure' => ['storage'],
        ];
    }

    #[DataProvider('failureStages')]
    public function test_return_rolls_back_every_write_if_a_later_step_fails(string $stage): void
    {
        $before = $this->snapshot();
        $fired = false;
        DB::listen(function (QueryExecuted $query) use ($stage, &$fired): void {
            $isClaim = str_starts_with($query->sql, 'update `claim`');
            $matches = match ($stage) {
                'selected' => $isClaim && in_array('RETURNED', $query->bindings, true),
                'other' => $isClaim && str_contains($query->sql, '!='),
                'storage' => str_starts_with($query->sql, 'update `storage_assignment`'),
            };
            if (! $fired && $matches) {
                $fired = true;
                throw new RuntimeException('Injected failure after '.$stage.' write.');
            }
        });
        try {
            app(ReturnFoundItem::class)->execute($this->item->item_id, $this->people[0]->person_id, $this->staffUser->staff_id);
            $this->fail('The injected failure must abort the return.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after '.$stage.' write.', $exception->getMessage());
        }
        $this->assertTrue($fired, 'The requested write stage must actually be exercised.');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_item_workflows_reject_times_before_receipt_even_with_older_placements(): void
    {
        // Simulate inconsistent imported chronology without weakening any schema constraints.
        DB::table('found_item')->where('item_id', $this->item->item_id)
            ->update(['received_at' => '2026-09-30 13:00:00']);
        $before = $this->snapshot();
        $this->postJson($this->reviewUrl('reject'), [])->assertUnprocessable()->assertJsonValidationErrors('reviewed_at');
        $this->postJson($this->reviewUrl('return'), [])->assertUnprocessable()->assertJsonValidationErrors('returned_at');
        $this->postJson('/api/items/'.$this->item->item_id.'/transfers', ['location_id' => $this->destination->location_id])
            ->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_rejection_rolls_back_when_a_failure_occurs_after_review_write(): void
    {
        $before = $this->snapshot();
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired): void {
            if (! $fired && str_starts_with($query->sql, 'update `claim`')) {
                $fired = true;
                throw new RuntimeException('Injected failure after rejection.');
            }
        });
        try {
            app(RejectClaim::class)->execute($this->item->item_id, $this->people[0]->person_id, $this->staffUser->staff_id);
            $this->fail('The injected failure must abort rejection.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after rejection.', $exception->getMessage());
        }
        $this->assertTrue($fired);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_mysql_unique_index_prevents_two_returned_claims(): void
    {
        $this->postJson($this->reviewUrl('return'), [])->assertOk();
        $this->expectException(QueryException::class);
        DB::table('claim')->where('item_id', $this->item->item_id)->where('claimant_id', $this->people[1]->person_id)
            ->update(['outcome' => 'RETURNED']);
    }
}
