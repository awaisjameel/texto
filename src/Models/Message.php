<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Table: texto_messages
 *
 * @property int $id
 * @property string $direction
 * @property string $driver
 * @property string $from_number
 * @property string $to_number
 * @property string|null $body
 * @property array|null $media_urls
 * @property string $status
 * @property string $provider_message_id
 * @property string $error_code
 * @property int $segments_count
 * @property float $cost_estimate
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property \Illuminate\Support\Carbon|null $status_updated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Message extends Model
{
    protected $table = 'texto_messages';

    protected $guarded = [];

    protected $casts = [
        'media_urls' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'status_updated_at' => 'datetime',
    ];
}
