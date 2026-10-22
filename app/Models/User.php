<?php

namespace App\Models;

use App\Actions\Accounts\VerifyAccountEmail;
use App\Notifications\ResetUserPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id', 'person_id');
    }

    public function sendEmailVerificationNotification(): void
    {
        app(VerifyAccountEmail::class)->send($this);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetUserPassword($token));
    }

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'has_local_password' => 'boolean',
            'is_account_admin' => 'boolean',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    public function canManageAccounts(): bool
    {
        return DB::table('users')
            ->join('staff', 'staff.staff_id', '=', 'users.staff_id')
            ->where('users.id', $this->getKey())
            ->where('users.is_account_admin', true)
            ->whereNull('users.deactivated_at')
            ->exists();
    }

    public function isActive(): bool
    {
        return DB::table('users')->where('id', $this->getKey())->whereNull('deactivated_at')->exists();
    }
}
