<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppNotification extends Model
{
    protected $table = 'whatsapp_notifications';

    protected $fillable = [
        'event_key',
        'message',
        'status',
        'attempts',
        'reserved_until',
        'next_attempt_at',
        'sent_at',
        'last_error',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'reserved_until' => 'datetime',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];
}