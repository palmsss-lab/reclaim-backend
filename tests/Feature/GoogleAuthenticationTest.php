<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\SetReclaimPassword;
use App\Services\GoogleGateway;
use App\Services\PkceGoogleProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    private array $profile;

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
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-only-secret',
            'services.google.redirect' => 'http://127.0.0.1:8001/api/auth/google/callback',
            'services.google.frontend_origin' => 'http://127.0.0.1:5174']);
        $this->profile = ['subject' => 'google-stable-123', 'email' => 'google-fixture@gmail.com',
            'name' => 'Fictional Google User', 'verified' => true, 'hosted_domain' => null];
        $gateway = Mockery::mock(GoogleGateway::class)->makePartial();
        $gateway->shouldReceive('profile')->andReturnUsing(fn () => $this->profile);
        app()->instance(GoogleGateway::class, $gateway);
    }

    private function headers(?User $user = null): array
    {
        auth()->forgetGuards();
        app('auth')->shouldUse('web');

        return $user ? ['Authorization' => 'Bearer '.$user->createToken('test-google', [$user->staff_id ? 'staff' : 'user'])->plainTextToken] : [];
    }

    private function attempt(array $headers = [], string $prefix = 'auth/google', string $password = ''): array
    {
        $verifier = str_repeat('a', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $start = $this->postJson('/api/'.$prefix.'/start', ['challenge' => $challenge, 'current_password' => $password], $headers)->assertOk()->json();
        parse_str(parse_url($start['url'], PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(43, strlen($query['code_challenge']));

        return ['state' => $query['state'], 'verifier' => $verifier];
    }

    private function googleCallback(array $attempt, array $query = []): array
    {
        $response = $this->get('/api/auth/google/callback?'.http_build_query($query + ['state' => $attempt['state'], 'code' => 'test-provider-code']))->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        preg_match('/postMessage\((\{.*?\}),/', $response->getContent(), $matches);

        return json_decode($matches[1], true);
    }

    private function signIn(): array
    {
        $this->headers();
        $attempt = $this->attempt();
        $message = $this->googleCallback($attempt);
        $this->assertArrayHasKey('code', $message);

        return $this->postJson('/api/auth/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']])->assertOk()->json();
    }

    private function local(bool $staff = false): User
    {
        $user = User::factory()->create(['email' => 'local-'.bin2hex(random_bytes(4)).'@gmail.com', 'password' => 'Separate-reclaim-password-123']);
        if ($staff) {
            $user->staff_id = Staff::create(['full_name' => 'Fictional Staff'])->staff_id;
        } else {
            $user->person_id = Person::create(['full_name' => 'Fictional Person', 'contact_details' => $user->email])->person_id;
        }
        $user->save();

        return $user->fresh();
    }

    public function test_first_party_google_exchange_creates_a_session_instead_of_a_bearer_token(): void
    {
        $origin = ['Origin' => 'http://127.0.0.1:5174'];
        config(['sanctum.stateful' => ['127.0.0.1:5174']]);
        $this->headers();
        $attempt = $this->attempt($origin);
        $message = $this->googleCallback($attempt);
        $response = $this->postJson('/api/auth/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']], $origin)
            ->assertOk()->assertJsonPath('session_authenticated', true)->assertJsonMissingPath('token');
        $user = User::where('email', $this->profile['email'])->firstOrFail();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue($response->getCookie(config('session.cookie'), false)->isHttpOnly());
    }

    public function test_cookie_google_linking_is_bound_to_the_current_authenticated_session(): void
    {
        $origin = ['Origin' => 'http://127.0.0.1:5174'];
        config(['sanctum.stateful' => ['127.0.0.1:5174']]);
        $this->withCredentials();
        $user = $this->local(true);
        $login = function () use ($user, $origin): void {
            $response = $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'Separate-reclaim-password-123'], $origin)->assertOk();
            $this->withUnencryptedCookie(config('session.cookie'), $response->getCookie(config('session.cookie'), false)->getValue());
            auth()->forgetGuards();
            auth()->shouldUse('web');
        };
        $login();
        $attempt = $this->attempt($origin, 'me/google', 'Separate-reclaim-password-123');
        $message = $this->googleCallback($attempt);
        $this->postJson('/api/me/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']], $origin)->assertOk();
        $this->assertDatabaseHas('google_identities', ['user_id' => $user->id, 'subject' => $this->profile['subject']]);
        $attempt = $this->attempt($origin, 'me/google', 'Separate-reclaim-password-123');
        $message = $this->googleCallback($attempt);
        $login(); // A new login rotates the browser session, even for the same account.
        $this->postJson('/api/me/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']], $origin)->assertForbidden();
    }

    public function test_google_registration_is_regular_and_linked_login_uses_subject_even_after_email_changes(): void
    {
        $before = DB::table('person')->count();
        $result = $this->signIn();
        $user = User::where('email', $this->profile['email'])->firstOrFail();
        $this->assertFalse($user->has_local_password);
        $this->assertFalse($user->is_account_admin);
        $this->assertNull($user->staff_id);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame($before + 1, DB::table('person')->count());
        $headers = ['Authorization' => 'Bearer '.$result['token']];
        $this->getJson('/api/me', $headers)->assertOk()->assertJsonPath('user_id', $user->id);
        $this->getJson('/api/accounts', $headers)->assertForbidden();
        $this->headers();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Separate-reclaim-password-123'])->assertUnauthorized();
        $this->profile['email'] = 'provider-changed@gmail.com';
        $this->signIn();
        $this->assertSame($before + 1, DB::table('person')->count());
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_email_collision_requires_authenticated_explicit_link_and_preserves_admin(): void
    {
        $user = $this->local(true);
        $user->is_account_admin = true;
        $user->save();
        $this->profile['email'] = $user->email;
        $attempt = $this->attempt();
        $message = $this->googleCallback($attempt);
        $this->postJson('/api/auth/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']])->assertStatus(409);
        $this->assertDatabaseMissing('google_identities', ['user_id' => $user->id]);
        $this->postJson('/api/me/google/start', ['challenge' => str_repeat('a', 43)])->assertUnauthorized();
        $headers = $this->headers($user);
        $this->postJson('/api/me/google/start', ['challenge' => str_repeat('a', 43), 'current_password' => 'wrong'], $headers)->assertUnprocessable();
        $attempt = $this->attempt($headers, 'me/google', 'Separate-reclaim-password-123');
        $message = $this->googleCallback($attempt);
        $this->postJson('/api/auth/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']])->assertForbidden();
        $this->postJson('/api/me/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']], $headers)->assertOk();
        $this->assertTrue($user->fresh()->is_account_admin);
        $result = $this->signIn();
        $headers = ['Authorization' => 'Bearer '.$result['token']];
        $this->getJson('/api/accounts', $headers)->assertOk();
        $this->headers();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Separate-reclaim-password-123'])->assertOk();
    }

    public function test_linking_requires_same_token_and_identity_cannot_be_shared(): void
    {
        $this->signIn();
        $local = $this->local();
        $headers = $this->headers($local);
        $attempt = $this->attempt($headers, 'me/google', 'Separate-reclaim-password-123');
        $message = $this->googleCallback($attempt);
        $otherHeaders = $this->headers($local);
        $this->postJson('/api/me/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']], $otherHeaders)->assertForbidden();
        auth()->forgetGuards();
        $this->postJson('/api/me/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']], $headers)->assertStatus(409);
        $this->assertDatabaseMissing('google_identities', ['user_id' => $local->id]);
    }

    public function test_state_cancel_invalid_profile_and_provider_failures_never_create_accounts(): void
    {
        $before = User::count();
        $this->assertArrayHasKey('error', $this->googleCallback(['state' => str_repeat('x', 43)]));
        $attempt = $this->attempt();
        $this->assertStringContainsString('cancelled', $this->googleCallback($attempt, ['error' => 'access_denied'])['error']);
        $this->assertArrayHasKey('error', $this->googleCallback($attempt));
        $this->profile['subject'] = '';
        $this->assertArrayHasKey('error', $this->googleCallback($this->attempt()));
        $gateway = Mockery::mock(GoogleGateway::class)->makePartial();
        $gateway->shouldReceive('profile')->andThrow(new \RuntimeException('sensitive provider detail'));
        app()->instance(GoogleGateway::class, $gateway);
        $message = $this->googleCallback($this->attempt());
        $this->assertStringNotContainsString('sensitive', $message['error']);
        $this->assertSame($before, User::count());
    }

    public function test_exchange_and_callback_are_single_use_expiring_and_bound_to_verifier(): void
    {
        $attempt = $this->attempt();
        $message = $this->googleCallback($attempt);
        $this->assertArrayHasKey('error', $this->googleCallback($attempt));
        $body = ['code' => $message['code'], 'verifier' => $attempt['verifier']];
        $this->postJson('/api/auth/google/exchange', ['code' => $message['code'], 'verifier' => str_repeat('b', 43)])->assertUnprocessable();
        $this->postJson('/api/auth/google/exchange', $body)->assertOk();
        $this->postJson('/api/auth/google/exchange', $body)->assertUnprocessable();
        $attempt = $this->attempt();
        $message = $this->googleCallback($attempt);
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']])->assertUnprocessable();
        $attempt = $this->attempt();
        $this->travel(6)->minutes();
        $this->assertArrayHasKey('error', $this->googleCallback($attempt));
    }

    public function test_deactivation_is_checked_at_exchange_and_existing_login_still_works(): void
    {
        $this->signIn();
        $user = User::where('email', $this->profile['email'])->firstOrFail();
        $attempt = $this->attempt();
        $message = $this->googleCallback($attempt);
        $user->deactivated_at = now();
        $user->save();
        $this->postJson('/api/auth/google/exchange', ['code' => $message['code'], 'verifier' => $attempt['verifier']])->assertForbidden();
        $local = $this->local();
        $this->headers();
        $this->postJson('/api/auth/login', ['email' => $local->email, 'password' => 'Separate-reclaim-password-123'])->assertOk();
    }

    public function test_only_trusted_google_verified_assertions_verify_new_email(): void
    {
        $this->profile['verified'] = false;
        $this->signIn();
        $user = User::where('email', $this->profile['email'])->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->profile['subject'] = 'external';
        $this->profile['email'] = 'fixture@example.test';
        $this->profile['verified'] = true;
        $this->signIn();
        $this->assertFalse(User::where('email', $this->profile['email'])->firstOrFail()->hasVerifiedEmail());
    }

    public function test_password_setup_is_email_verified_single_use_and_preserves_google_login(): void
    {
        $this->signIn();
        $user = User::where('email', $this->profile['email'])->firstOrFail();
        $headers = $this->headers($user);
        $this->getJson('/api/me/security', $headers)->assertOk()->assertJsonPath('data.has_local_password', false)->assertJsonPath('data.google_linked', true);
        $this->postJson('/api/me/password/setup-email', [], $headers)->assertOk();
        $proof = Notification::sent($user, SetReclaimPassword::class)->last()->proof;
        $payload = ['proof' => $proof, 'password' => 'New-separate-password-123', 'password_confirmation' => 'New-separate-password-123'];
        $this->postJson('/api/me/password/setup', $payload + ['has_verified' => true], $headers)->assertNoContent();
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertTrue($user->fresh()->has_local_password);
        auth()->forgetGuards();
        $this->getJson('/api/me', $headers)->assertUnauthorized();
        $headers = $this->headers($user);
        $this->postJson('/api/me/password/setup', $payload, $headers)->assertUnprocessable();
        $this->headers();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $payload['password']])->assertOk();
        $this->signIn();
        $this->postJson('/api/auth/password', ['current_password' => 'wrong', 'password' => $payload['password'], 'password_confirmation' => $payload['password']], $this->headers($user))->assertUnprocessable();
    }

    public function test_password_setup_rejects_forgery_expiry_other_account_changed_email_and_unverified_account(): void
    {
        $this->signIn();
        $user = User::where('email', $this->profile['email'])->firstOrFail();
        $headers = $this->headers($user);
        $payload = ['proof' => str_repeat('x', 64), 'password' => 'New-separate-password-123', 'password_confirmation' => 'New-separate-password-123'];
        $this->postJson('/api/me/password/setup', $payload, $headers)->assertUnprocessable();
        $this->postJson('/api/me/password/setup-email', [], $headers)->assertOk();
        $payload['proof'] = Notification::sent($user, SetReclaimPassword::class)->last()->proof;
        $this->postJson('/api/me/password/setup', $payload, $this->headers($this->local()))->assertUnprocessable();
        $headers = $this->headers($user);
        $this->travel(16)->minutes();
        $this->postJson('/api/me/password/setup', $payload, $headers)->assertUnprocessable();
        $this->postJson('/api/me/password/setup-email', [], $headers)->assertOk();
        $payload['proof'] = Notification::sent($user, SetReclaimPassword::class)->last()->proof;
        $user->email = 'changed@gmail.com';
        $user->save();
        $this->postJson('/api/me/password/setup', $payload, $headers)->assertUnprocessable();
        $user->email_verified_at = null;
        $user->save();
        auth()->forgetGuards();
        $this->postJson('/api/me/password/setup-email', [], $headers)->assertStatus(409);
        $this->assertFalse($user->fresh()->has_local_password);
    }

    public function test_recovery_does_not_set_google_only_password_and_public_message_is_generic(): void
    {
        $this->signIn();
        $user = User::where('email', $this->profile['email'])->firstOrFail();
        $this->headers();
        $a = $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk()->json();
        $b = $this->postJson('/api/auth/password/forgot', ['email' => 'absent@example.test'])->assertOk()->json();
        $this->assertSame($a, $b);
        Notification::assertNothingSent();
        $this->postJson('/api/auth/password/verify-code', ['email' => $user->email, 'code' => '123456',
            'password' => 'New-separate-password-123', 'password_confirmation' => 'New-separate-password-123'])->assertUnprocessable();
        $this->assertFalse($user->fresh()->has_local_password);
    }

    public function test_configuration_and_rate_limits_fail_closed(): void
    {
        config(['services.google.frontend_origin' => 'https://example.test/untrusted-path']);
        $this->postJson('/api/auth/google/start', ['challenge' => str_repeat('a', 43)])->assertStatus(503);
        config(['services.google.frontend_origin' => 'http://127.0.0.1:5174', 'services.google.client_secret' => '']);
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/auth/google/start', ['challenge' => str_repeat('a', 43)])->assertStatus(503);
        }
        $this->postJson('/api/auth/google/start', ['challenge' => str_repeat('a', 43)])->assertStatus(429);
        $this->assertSame(0, DB::table('google_auth_attempts')->count());
    }

    public function test_setup_resend_invalidates_old_link_and_deactivation_blocks_setup(): void
    {
        $this->signIn();
        $user = User::where('email', $this->profile['email'])->firstOrFail();
        $headers = $this->headers($user);
        $this->postJson('/api/me/password/setup-email', [], $headers)->assertOk();
        $oldProof = Notification::sent($user, SetReclaimPassword::class)->last()->proof;
        $this->postJson('/api/me/password/setup-email', [], $headers)->assertOk();
        $newProof = Notification::sent($user, SetReclaimPassword::class)->last()->proof;
        $this->assertNotSame($oldProof, $newProof);
        $payload = ['proof' => $oldProof, 'password' => 'New-separate-password-123', 'password_confirmation' => 'New-separate-password-123'];
        $this->postJson('/api/me/password/setup', $payload, $headers)->assertUnprocessable();
        $user->deactivated_at = now();
        $user->save();
        auth()->forgetGuards();
        $payload['proof'] = $newProof;
        $this->postJson('/api/me/password/setup', $payload, $headers)->assertForbidden();
        $this->assertFalse($user->fresh()->has_local_password);
    }

    public function test_socialite_transport_sends_pkce_and_uses_authenticated_userinfo_without_persisting_provider_token(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['access_token' => 'fake-google-token', 'token_type' => 'Bearer'])),
            new Response(200, [], json_encode(['sub' => 'stable-transport', 'name' => 'Fictional User', 'email' => 'fixture@gmail.com', 'email_verified' => true]))]));
        $handler->push(Middleware::history($history));
        $driver = new PkceGoogleProvider(Request::create('/callback?code=fake-code'), 'test-client', 'test-secret', 'http://127.0.0.1:8001/api/auth/google/callback');
        $driver->setHttpClient(new Client(['handler' => $handler]));
        $user = $driver->stateless()->withVerifier(str_repeat('a', 43))->user();
        $this->assertSame('stable-transport', $user->getId());
        parse_str((string) $history[0]['request']->getBody(), $fields);
        $this->assertSame(str_repeat('a', 43), $fields['code_verifier']);
        $this->assertSame('test-secret', $fields['client_secret']);
        $this->assertSame('Bearer fake-google-token', $history[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame('www.googleapis.com', $history[1]['request']->getUri()->getHost());
    }
}
