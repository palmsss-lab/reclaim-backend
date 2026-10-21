<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationResultTest extends TestCase
{
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
        config(['services.google.frontend_origin' => 'https://reclaim.example.test']);
        $this->withHeader('Accept', 'text/html');
    }

    private function link(User $user, array $extra = [], bool $expired = false): string
    {
        return URL::temporarySignedRoute('user.email.verify', $expired ? now()->subMinute() : now()->addMinutes(30),
            array_merge(['id' => $user->id, 'hash' => sha1($user->email)], $extra));
    }

    public function test_browser_completion_is_friendly_and_links_do_not_contain_verification_secrets(): void
    {
        $user = User::factory()->unverified()->create();
        $response = $this->get($this->link($user))->assertOk()
            ->assertViewIs('auth.verification-result')->assertViewHas('verified', true)
            ->assertSee('Your email is verified')->assertSee('https://reclaim.example.test/login', false)
            ->assertSee('Browse found items')->assertDontSee('signature=')
            ->assertDontSee(sha1($user->email))->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->get($this->link($user))->assertOk()->assertViewHas('verified', true);
    }

    public function test_json_clients_keep_the_existing_success_response(): void
    {
        $user = User::factory()->unverified()->create();
        $this->getJson($this->link($user))->assertOk()
            ->assertExactJson(['message' => 'Email verified. You can now sign in to Reclaim.']);
        $this->assertNotNull($user->fresh()->email_verified_at);
        foreach (['', '*/*'] as $accept) {
            $this->withHeader('Accept', $accept)->get($this->link($user))->assertOk()
                ->assertExactJson(['message' => 'Email verified. You can now sign in to Reclaim.']);
        }
    }

    public function test_expired_and_tampered_links_do_not_verify_or_claim_success(): void
    {
        $user = User::factory()->unverified()->create();
        foreach ([$this->link($user, [], true), $this->link($user).'&returnTo=https://evil.invalid'] as $url) {
            $this->get($url)->assertForbidden()->assertViewIs('auth.verification-result')
                ->assertViewHas('verified', false)->assertSee('This verification link is no longer valid')
                ->assertDontSee('Your email is verified')->assertDontSee('evil.invalid');
            $this->assertNull($user->fresh()->email_verified_at);
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_browser_result_ignores_even_signed_continuation_parameters(): void
    {
        foreach (['https://evil.invalid', '//evil.invalid', '/items/2?token=private', '/my/claims'] as $destination) {
            $user = User::factory()->unverified()->create();
            $this->get($this->link($user, ['returnTo' => $destination]))->assertOk()
                ->assertViewHas('verified', true)->assertDontSee($destination)
                ->assertSee('href="https://reclaim.example.test/login"', false);
        }
    }

    public function test_wrong_email_hash_and_inactive_accounts_have_no_success_page(): void
    {
        $user = User::factory()->unverified()->create();
        $this->get($this->link($user, ['hash' => sha1('different@example.test')]))
            ->assertForbidden()->assertViewHas('verified', false);
        $this->assertNull($user->fresh()->email_verified_at);
        $user->forceFill(['deactivated_at' => now()])->save();
        $this->get($this->link($user))->assertNotFound()->assertViewHas('verified', false);
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
