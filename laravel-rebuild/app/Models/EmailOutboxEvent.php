<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailOutboxEvent extends Model
{
    protected $table = 'email_outbox_events';

    protected $fillable = [
        'outbox_id',
        'event_type',
        'actor_id',
        'request_id',
        'source_ip',
        'user_agent',
        'correlation_id',
        'payload',
        'previous_event_hash',
        'event_hash',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('EmailOutboxEvent is append-only en mag niet geupdate worden.');
        });

        static::deleting(function (): void {
            throw new \LogicException('EmailOutboxEvent is append-only en mag niet verwijderd worden.');
        });
    }

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(EmailOutbox::class, 'outbox_id');
    }
}
