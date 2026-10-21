<?php

namespace App\Actions\Accounts;

use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoogleAuthentication
{
    public function resolve(array $profile, ?User $linkingUser): User
    {
        return DB::transaction(function () use ($profile, $linkingUser) {
            $identity = DB::table('google_identities')->where('subject', $profile['subject'])->first();
            if ($linkingUser) {
                $user = User::query()->lockForUpdate()->findOrFail($linkingUser->id);
                abort_unless($user->isActive(), 403, 'Sign-in could not be completed.');
                abort_if($identity && $identity->user_id !== $user->id, 409, 'Google linking could not be completed.');
                $existing = DB::table('google_identities')->where('user_id', $user->id)->first();
                abort_if($existing && $existing->subject !== $profile['subject'], 409, 'A Google identity is already linked.');
            } elseif ($identity) {
                $user = User::query()->lockForUpdate()->findOrFail($identity->user_id);
            } else {
                // No email/name-based account or person linking, even for a verified Google email.
                abort_if(User::where('email', $profile['email'])->exists(), 409,
                    'Sign-in could not be completed. If you already have a Reclaim account, sign in with its existing method and link Google in Account Settings.');
                $person = Person::create(['full_name' => $profile['name'], 'contact_details' => $profile['email']]);
                $user = new User;
                $user->name = $profile['name'];
                $user->email = $profile['email'];
                // Required legacy column: an unrecoverable random value, disabled by has_local_password.
                $user->password = Str::random(96);
                $user->has_local_password = false;
                $user->person_id = $person->person_id;
                $user->staff_id = null;
                $user->is_account_admin = false;
                $domain = strtolower(substr(strrchr($profile['email'], '@'), 1));
                $trusted = $profile['verified'] && ($domain === 'gmail.com' ||
                    (is_string($profile['hosted_domain']) && strtolower($profile['hosted_domain']) === $domain));
                $user->email_verified_at = $trusted ? now() : null;
                $user->save();
            }
            abort_unless($user->isActive() && ($user->staff_id || $user->person_id), 403, 'Sign-in could not be completed.');
            if (! $identity) {
                DB::table('google_identities')->insert(['user_id' => $user->id, 'subject' => $profile['subject'],
                    'created_at' => now(), 'updated_at' => now()]);
            }

            return $user;
        });
    }
}
