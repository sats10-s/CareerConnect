<?php

namespace App\Models;

use App\Enums\CompanyMemberRole;
use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'company_id',
        'company_member_role',
        'is_active',
        'last_login_at',
        'must_change_password',
        'credentials_issued_at',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
            'credentials_issued_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'failed_logins' => 'integer',
            'locked_until' => 'datetime',
            'company_member_role' => CompanyMemberRole::class,
        ];
    }

    public function role(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function faculty(): HasOne
    {
        return $this->hasOne(Faculty::class);
    }

    /**
     * The password broker's key for this account. Imported students have no
     * email (they sign in by JNTU No), so they get a stable stand-in; the
     * reset link itself goes to their personal email (PasswordService).
     */
    public function getEmailForPasswordReset(): string
    {
        return $this->email ?? "user-{$this->id}@no-email.careerconnect";
    }

    /** Set only for COMPANY-role logins. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function hasRole(RoleName $role): bool
    {
        return $this->role?->name === $role->value;
    }

    public function roleName(): ?RoleName
    {
        return $this->role ? RoleName::tryFrom($this->role->name) : null;
    }
}
