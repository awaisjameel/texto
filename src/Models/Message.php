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
 * @property string|null $from_number
 * @property string|null $to_number
 * @property string|null $body
 * @property array|null $media_urls
 * @property string|null $status
 * @property string|null $provider_message_id
 * @property string|null $error_code
 * @property int|null $segments_count
 * @property float|null $cost_estimate
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

    /**
     * Explicit allow-list instead of an open $guarded so only known business columns are
     * mass-assignable (id/timestamps stay protected). Keep in sync with the migration columns.
     *
     * @var list<string>
     */
    protected $fillable = [
        'direction',
        'driver',
        'from_number',
        'to_number',
        'body',
        'media_urls',
        'status',
        'provider_message_id',
        'error_code',
        'segments_count',
        'cost_estimate',
        'metadata',
        'sent_at',
        'received_at',
        'status_updated_at',
    ];

    protected $casts = [
        'media_urls' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'status_updated_at' => 'datetime',
    ];
}
