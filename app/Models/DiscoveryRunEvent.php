<?php

namespace App\Models;

use App\Enums\DiscoveryAccessOutcome;
use Database\Factories\DiscoveryRunEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryRunEvent extends Model
{
    /** @use HasFactory<DiscoveryRunEventFactory> */
    use HasFactory;

    protected $fillable = [
        'discovery_run_id',
        'event_key',
        'outcome',
        'final_url',
        'http_status',
        'redirects',
        'details',
        'error',
        'occurred_at',
    ];

    protected $casts = [
        'outcome' => DiscoveryAccessOutcome::class,
        'redirects' => 'array',
        'details' => 'array',
        'http_status' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class, 'discovery_run_id');
    }
}
