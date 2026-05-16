<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_events';

    protected $fillable = [
        'organization_id',
        'actor_id',
        'action',
        'target_type',
        'target_id',
        'before_data',
        'after_data',
        'request_id',
        'ip_address',
        'user_agent',
        'scrubbed_at',
        'created_at',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
        'scrubbed_at' => 'datetime',
    ];
}
