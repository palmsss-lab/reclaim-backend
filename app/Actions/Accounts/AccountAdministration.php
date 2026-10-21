<?php

namespace App\Actions\Accounts;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountAdministration
{
    public function create(User $actor, array $data): User
    {
        try {
            return DB::transaction(function () use ($actor, $data): User {
                $this->lockActiveAdmins();
                $this->assertAdministrator($actor->getKey());

                $staff = Staff::create(['full_name' => $data['staff_full_name']]);
                $account = new User;
                $account->name = $data['name'];
                $account->email = $data['email'];
                $account->password = $data['password'];
                $account->is_account_admin = false;
                $account->staff()->associate($staff);
                $account->save();

                return $account->load('staff');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
    }

    public function update(User $actor, User $target, array $data): User
    {
        try {
            return DB::transaction(function () use ($actor, $target, $data): User {
                $this->lockActiveAdmins();
                $this->assertAdministrator($actor->getKey());
                $account = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
                $this->assertStaffTarget($account);
                $account->fill(array_intersect_key($data, array_flip(['name', 'email'])))->save();

                return $account->load('staff');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
    }

    public function setAdministrator(User $actor, User $target, bool $makeAdministrator): User
    {
        return DB::transaction(function () use ($actor, $target, $makeAdministrator): User {
            $admins = $this->lockActiveAdmins();
            $this->assertAdministrator($actor->getKey());
            $account = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $this->assertStaffTarget($account);

            if ($makeAdministrator && $account->deactivated_at !== null) {
                throw new HttpException(409, 'A deactivated account cannot become an administrator.');
            }

            if ($makeAdministrator && ! $account->staff()->exists()) {
                throw new HttpException(409, 'Only an account linked to staff can become an administrator.');
            }

            if (! $makeAdministrator && $admins->contains('id', $account->getKey()) && $admins->count() <= 1) {
                throw new HttpException(409, 'The last active administrator cannot be demoted.');
            }

            $account->is_account_admin = $makeAdministrator;
            $account->save();

            return $account->load('staff');
        });
    }

    public function deactivate(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $admins = $this->lockActiveAdmins();
            $this->assertAdministrator($actor->getKey());
            $account = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $this->assertStaffTarget($account);

            if ($account->deactivated_at !== null) {
                throw new HttpException(409, 'The account is already deactivated.');
            }

            if ($admins->contains('id', $account->getKey()) && $admins->count() <= 1) {
                throw new HttpException(409, 'The last active administrator cannot be deactivated.');
            }

            $account->deactivated_at = now(config('app.timezone'));
            $account->remember_token = Str::random(60);
            $account->save();

            $account->tokens()->delete();
            DB::table(config('session.table', 'sessions'))->where('user_id', $account->getKey())->delete();
        });
    }

    public function changePassword(User $account, string $currentPassword, string $newPassword): void
    {
        DB::transaction(function () use ($account, $currentPassword, $newPassword): void {
            $current = User::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            if (! $current->has_local_password || ! Hash::check($currentPassword, $current->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }

            $current->password = $newPassword;
            $current->remember_token = Str::random(60);
            $current->save();
            $current->tokens()->delete();
            DB::table(config('session.table', 'sessions'))->where('user_id', $current->getKey())->delete();
        });
    }

    public function bootstrapAdministrator(array $data): User
    {
        $lockName = 'reclaim_account_admin_bootstrap';
        $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, 15) AS acquired', [$lockName])->acquired ?? 0);
        if ($acquired !== 1) {
            throw new HttpException(409, 'Could not acquire the administrator bootstrap lock.');
        }

        try {
            try {
                return DB::transaction(function () use ($data): User {
                    if (DB::table('users')->where('is_account_admin', true)->exists()) {
                        throw new HttpException(409, 'An administrator has already been bootstrapped.');
                    }

                    $staff = Staff::create(['full_name' => $data['staff_full_name']]);
                    $account = new User;
                    $account->name = $data['name'];
                    $account->email = $data['email'];
                    $account->password = $data['password'];
                    $account->is_account_admin = true;
                    $account->staff()->associate($staff);
                    $account->save();

                    return $account;
                });
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
            }
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    private function lockActiveAdmins()
    {
        return DB::table('users')
            ->where('is_account_admin', true)
            ->whereNull('deactivated_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('staff')->whereColumn('staff.staff_id', 'users.staff_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function assertAdministrator(int $userId): void
    {
        $allowed = DB::table('users')
            ->join('staff', 'staff.staff_id', '=', 'users.staff_id')
            ->where('users.id', $userId)
            ->where('users.is_account_admin', true)
            ->whereNull('users.deactivated_at')
            ->exists();

        abort_unless($allowed, 403, 'This action is unauthorized.');
    }

    private function assertStaffTarget(User $account): void
    {
        abort_unless($account->person_id === null && $account->staff()->exists(), 403,
            'User accounts are read-only in account administration.');
    }
}
