<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'full_name', 'email', 'password', 'organization_id', 'team_id', 'role', 'is_active', 'email_reminders_opt_in', 'employment_start', 'employment_end', 'phone', 'annual_leave_days'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'full_name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'is_active' => 'boolean',
            'employment_start' => 'date',
            'employment_end' => 'date',
            'deleted_at' => 'datetime',
            'annual_leave_days' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (User $user): void {
            $email = $user->email;
            if ($email !== null && $email !== '') {
                $user->email_index_hash = hash('sha256', strtolower((string) $email));
            }
        });
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function mfaSecret(): HasOne
    {
        return $this->hasOne(MfaSecret::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function workEntriesAsEmployee(): HasMany
    {
        return $this->hasMany(WorkEntry::class, 'employee_id');
    }

    public function workEntriesRegistered(): HasMany
    {
        return $this->hasMany(WorkEntry::class, 'registered_by_id');
    }

    public function mfaRecoveryCodes(): HasMany
    {
        return $this->hasMany(MfaRecoveryCode::class);
    }

    /**
     * Verlof-types van de organisatie waartoe deze gebruiker behoort.
     * Handig voor het ophalen van beschikbare verlof-types via de user.
     */
    public function leaveTypes(): HasManyThrough
    {
        return $this->hasManyThrough(
            LeaveType::class,
            Organization::class,
            'id',              // organizations.id
            'organization_id', // leave_types.organization_id
            'organization_id', // users.organization_id
            'id'               // organizations.id
        );
    }
}
