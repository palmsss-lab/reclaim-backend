<?php

use App\Actions\Accounts\AccountAdministration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

Artisan::command('accounts:bootstrap-admin', function (AccountAdministration $accounts): int {
    if (! $this->confirm('Create the first account administrator?', false)) {
        return self::SUCCESS;
    }

    do {
        $name = trim((string) $this->ask('Account display name'));
        $validName = $name !== '' && mb_strlen($name) <= 255;
        if (! $validName) {
            $this->error('The display name is required and must be at most 255 characters.');
        }
    } while (! $validName);

    do {
        $email = mb_strtolower(trim((string) $this->ask('Email address')));
        $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && mb_strlen($email) <= 255
            && ! \Illuminate\Support\Facades\DB::table('users')->where('email', $email)->exists();
        if (! $validEmail) {
            $this->error('Enter a valid, unused email address.');
        }
    } while (! $validEmail);

    do {
        $staffName = trim((string) $this->ask('Staff full name'));
        $validStaffName = $staffName !== '' && mb_strlen($staffName) <= 120;
        if (! $validStaffName) {
            $this->error('The staff name is required and must be at most 120 characters.');
        }
    } while (! $validStaffName);

    do {
        $password = (string) $this->secret('Initial password (at least 12 characters)');
        $confirmation = (string) $this->secret('Confirm initial password');
        $validPassword = mb_strlen($password) >= 12 && mb_strlen($password) <= 255 && hash_equals($password, $confirmation);
        if (! $validPassword) {
            $this->error('Passwords must match and contain at least 12 characters.');
        }
    } while (! $validPassword);

    try {
        $account = $accounts->bootstrapAdministrator([
            'name' => $name,
            'email' => $email,
            'staff_full_name' => $staffName,
            'password' => $password,
        ]);
    } catch (HttpException $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    } catch (ValidationException $exception) {
        $this->error('The account could not be created because its data conflicted with an existing record.');

        return self::FAILURE;
    } finally {
        unset($password, $confirmation);
    }

    $this->info('First account administrator created: '.$account->email);

    return self::SUCCESS;
})->purpose('Interactively create the first Reclaim account administrator; never accepts a password argument');
