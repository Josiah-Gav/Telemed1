<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable; // Removed 'HasApiTokens' from here

    // Tell Eloquent the table's primary key name
    protected $primaryKey = 'user_id';

    // Tell Laravel it doesn't auto-increment a column named 'id'
    public $incrementing = true;
    protected $keyType = 'int';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'account_status',
        'online_status',
        'clsu_id',
        'user_type',
        'department',
        'contact_num',
        'staff_position',
        'specialization',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (empty($user->email_verified_at) && in_array($user->role, ['admin', 'nurse', 'physician'], true)) {
                $user->email_verified_at = now();
            }
        });

        static::saving(function (self $user): void {
            if (empty($user->email_verified_at) && in_array($user->role, ['admin', 'nurse', 'physician'], true)) {
                $user->email_verified_at = now();
            }
        });
    }
}