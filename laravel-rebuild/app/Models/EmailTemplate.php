<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = [
        'organization_id',
        'type',
        'subject_template',
        'body_text_template',
        'body_html_template',
        'is_active',
        'updated_by_actor_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
