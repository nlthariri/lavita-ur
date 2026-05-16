<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaSecret extends Model
{
    protected $table = 'mfa_secrets';

    protected $fillable = [
        'user_id',
        'secret_encrypted',
        'issuer',
        'label',
        'verified_at',
        'rotated_at',
        'disabled_at',
    ];

    protected $hidden = [
        'secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'rotated_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
