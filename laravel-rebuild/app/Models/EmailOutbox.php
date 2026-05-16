<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailOutbox extends Model
{
    use HasFactory;

    protected $table = 'email_outbox';

    protected $fillable = [
        'idempotency_key',
        'organization_id',
        'user_id',
        'initiator_actor_id',
        'initiator_role_snapshot',
        'initiator_org_id_snapshot',
        'monthly_report_run_id',
        'request_id',
        'source_ip',
        'user_agent',
        'correlation_id',
        'recipient',
        'subject',
        'subject_sha256',
        'body_text',
        'body_text_sha256',
        'body_html',
        'body_html_sha256',
        'type',
        'attachments',
        'status',
        'retry_count',
        'next_attempt_at',
        'sent_at',
        'scrubbed_at',
        'error_message',
    ];

    protected $casts = [
        'attachments' => 'array',
        'retry_count' => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'scrubbed_at' => 'datetime',
    ];
}
