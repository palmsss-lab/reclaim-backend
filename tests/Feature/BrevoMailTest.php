<?php

namespace Tests\Feature;

use App\Exceptions\MailDeliveryFailed;
use App\Models\Person;
use App\Models\User;
use App\Notifications\SetReclaimPassword;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrevoMailTest extends TestCase
{
    private User $user;

    private string $testKey;

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
        $this->testKey = 'test-only-'.bin2hex(random_bytes(16));
        config([
            'app.debug' => true,
            'mail.default' => 'brevo',
            'mail.from.address' => 'verified-sender@example.test',
            'mail.from.name' => 'Reclaim',
            'services.brevo.key' => $this->testKey,
            'services.google.frontend_origin' => 'https://reclaim.example.test',
        ]);
        URL::forceRootUrl('https://api.reclaim.example.test');
        URL::forceScheme('https');
        Mail::purge();
        Http::preventStrayRequests();
        $person = Person::create(['full_name' => 'Mail fixture', 'contact_details' => 'Synthetic contact']);
        $this->user = User::factory()->unverified()->create(['person_id' => $person->person_id]);
    }

    private function accept(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<test-message@example.test>'], 201)]);
    }

    private function payload(): array
    {
        Http::assertSentCount(1);
        $request = Http::recorded()->first()[0];
        $this->assertSame('https://api.brevo.com/v3/smtp/email', $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->hasHeader('api-key', $this->testKey));
        $data = $request->data();
        $this->assertSame(['email' => 'verified-sender@example.test', 'name' => 'Reclaim'], $data['sender']);
        $this->assertSame($this->user->email, $data['to'][0]['email']);
        $this->assertStringNotContainsString($this->testKey, json_encode($data));

        return $data;
    }

    public function test_verification_keeps_content_signed_url_and_safe_browser_completion(): void
    {
        $this->accept();
        Sanctum::actingAs($this->user, ['user']);
        $this->postJson('/api/auth/email/verification-notification')->assertOk();
        $data = $this->payload();
        $this->assertSame('Verify your Reclaim account email', $data['subject']);
        $this->assertStringContainsString('Confirm this email address', $data['htmlContent']);
        $this->assertStringContainsString('This link expires in 60 minutes.', $data['textContent']);
        preg_match('~href="(https://api\.reclaim\.example\.test/api/auth/email/verify/[^"]+)"~', html_entity_decode($data['htmlContent']), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $this->get($matches[1], ['Accept' => 'text/html'])->assertOk()->assertViewHas('verified', true)
            ->assertSee('https://reclaim.example.test/login', false);
        $this->getJson($matches[1].'&tampered=1')->assertForbidden();
    }

    public function test_recovery_sends_a_code_and_allows_a_real_password_reset(): void
    {
        $this->accept();
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        $data = $this->payload();
        $this->assertSame('Reset your Reclaim password', $data['subject']);
        $this->assertStringContainsString('If you did not request this change, ignore this email.', $data['textContent']);
        $this->assertSame(1, preg_match('/\b[0-9]{6}\b/', $data['textContent'], $matches));
        $this->assertStringNotContainsString('/reset-password?', $data['htmlContent']);
        $authorization = $this->postJson('/api/auth/password/verify-code', [
            'email' => $this->user->email, 'code' => $matches[0],
        ])->assertOk()->json('data.authorization');
        $this->postJson('/api/auth/password/reset', [
            'email' => $this->user->email, 'authorization' => $authorization,
            'password' => 'New-test-password-12345', 'password_confirmation' => 'New-test-password-12345',
        ])->assertOk();
        $this->assertTrue(password_verify('New-test-password-12345', $this->user->fresh()->password));
    }

    public function test_failed_resend_preserves_the_previous_code(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::sequence()
            ->push(['messageId' => 'test-id'], 201)->push([], 503)]);
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        $data = $this->payload();
        preg_match('/\b[0-9]{6}\b/', $data['textContent'], $matches);
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertStatus(503);
        $authorization = $this->postJson('/api/auth/password/verify-code', [
            'email' => $this->user->email, 'code' => $matches[0],
        ])->assertOk()->json('data.authorization');
        $this->postJson('/api/auth/password/reset', [
            'email' => $this->user->email, 'authorization' => $authorization,
            'password' => 'New-test-password-12345', 'password_confirmation' => 'New-test-password-12345',
        ])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_failed_resend_preserves_an_already_verified_authorization(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::sequence()
            ->push(['messageId' => 'test-id'], 201)->push([], 503)]);
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        $data = $this->payload();
        preg_match('/\b[0-9]{6}\b/', $data['textContent'], $matches);
        $authorization = $this->postJson('/api/auth/password/verify-code', [
            'email' => $this->user->email, 'code' => $matches[0],
        ])->assertOk()->json('data.authorization');
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertStatus(503);
        $this->postJson('/api/auth/password/reset', [
            'email' => $this->user->email, 'authorization' => $authorization,
            'password' => 'New-test-password-12345', 'password_confirmation' => 'New-test-password-12345',
        ])->assertOk();
    }

    public function test_existing_password_setup_notification_uses_the_same_transport(): void
    {
        $this->accept();
        $this->user->notify(new SetReclaimPassword('synthetic-setup-proof'));
        $data = $this->payload();
        $this->assertSame('Set your separate Reclaim password', $data['subject']);
        $this->assertStringContainsString('https://reclaim.example.test/set-password?token=synthetic-setup-proof', html_entity_decode($data['htmlContent']));
    }

    public static function failedResponses(): array
    {
        return [
            'bad request' => [400, ['message' => 'PRIVATE PROVIDER DETAILS']],
            'invalid key' => [401, ['message' => 'PRIVATE PROVIDER DETAILS']],
            'sender rejected' => [403, ['message' => 'PRIVATE PROVIDER DETAILS']],
            'rate limit' => [429, ['message' => 'PRIVATE PROVIDER DETAILS']],
            'server error' => [500, ['message' => 'PRIVATE PROVIDER DETAILS']],
            'unavailable' => [503, ['message' => 'PRIVATE PROVIDER DETAILS']],
            'redirect' => [302, ['message' => 'PRIVATE PROVIDER DETAILS']],
            'wrong success status' => [200, ['messageId' => 'test-id']],
            'unconfirmed success' => [201, []],
            'invalid message id' => [201, ['messageId' => []]],
            'non JSON' => [201, 'PRIVATE PROVIDER DETAILS'],
        ];
    }

    #[DataProvider('failedResponses')]
    public function test_provider_failures_never_claim_success_or_expose_details(int $status, array|string $body): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response($body, $status)]);
        Log::spy();
        Sanctum::actingAs($this->user, ['user']);
        $response = $this->postJson('/api/auth/email/verification-notification')->assertStatus(503)
            ->assertExactJson(['message' => MailDeliveryFailed::MESSAGE]);
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertStringNotContainsString($this->testKey, $response->getContent());
        $this->assertStringNotContainsString($this->user->email, $response->getContent());
        Http::assertSentCount(1);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_connection_exception_is_replaced_without_sensitive_exception_chain(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException($this->testKey.' PRIVATE EMAIL CONTENT');
        });
        try {
            $this->user->sendEmailVerificationNotification();
            $this->fail('A connection failure must not be reported as a successful send.');
        } catch (MailDeliveryFailed $error) {
            $this->assertSame(MailDeliveryFailed::MESSAGE, $error->getMessage());
            $this->assertNull($error->getPrevious());
            $this->assertFalse(app(ExceptionHandler::class)->shouldReport($error));
            $this->assertStringNotContainsString($this->testKey, (string) $error);
        }
        $this->assertSame(1, $attempts);
    }

    public function test_missing_key_or_sender_fails_before_any_http_request(): void
    {
        $this->accept();
        Sanctum::actingAs($this->user, ['user']);
        config(['services.brevo.key' => '']);
        $this->postJson('/api/auth/email/verification-notification')->assertStatus(503);
        config(['services.brevo.key' => $this->testKey, 'mail.from.address' => '']);
        Mail::purge();
        $this->postJson('/api/auth/email/verification-notification')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_failed_reset_email_can_be_requested_again_without_false_throttle_success(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::sequence()
            ->push(['message' => 'PRIVATE DETAILS'], 500)->push(['messageId' => 'test-id'], 201)]);
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertStatus(503);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->user->email]);
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $this->user->email]);
        $again = $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        $this->assertStringNotContainsString('will be sent', $again->json('message'));
        Http::assertSentCount(2);
    }

    public function test_registration_mail_failure_is_not_a_success_and_account_can_be_recovered(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response([], 401)]);
        $this->postJson('/api/auth/register', [
            'full_name' => 'New mail fixture', 'contact_details' => 'Synthetic contact', 'email' => 'new-mail@example.test',
            'password' => 'Test-password-12345', 'password_confirmation' => 'Test-password-12345',
        ])->assertStatus(503)->assertExactJson(['message' => MailDeliveryFailed::MESSAGE]);
        $this->assertDatabaseHas('users', ['email' => 'new-mail@example.test', 'email_verified_at' => null]);
        $this->postJson('/api/auth/login', ['email' => 'new-mail@example.test', 'password' => 'Test-password-12345'])->assertOk();
    }

    public function test_failed_setup_delivery_rolls_back_the_setup_proof(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response([], 503)]);
        $this->user->forceFill(['has_local_password' => false, 'email_verified_at' => now()])->save();
        Sanctum::actingAs($this->user, ['user']);
        $this->postJson('/api/me/password/setup-email')->assertStatus(503)
            ->assertExactJson(['message' => MailDeliveryFailed::MESSAGE]);
        $this->assertDatabaseMissing('password_setup_tokens', ['user_id' => $this->user->id]);
        Http::assertSentCount(1);
    }

    public function test_unknown_inactive_and_google_only_recovery_do_not_send_email(): void
    {
        $this->accept();
        foreach ([['deactivated_at' => now()], ['has_local_password' => false]] as $attributes) {
            $user = User::factory()->create($attributes);
            $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();
        }
        $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.test'])->assertOk();
        Http::assertNothingSent();
    }
}
