<?php

namespace App\Models;

use Database\Factories\DiscoverySourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiscoverySource extends Model
{
    /** @use HasFactory<DiscoverySourceFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'source',
        'surface',
        'url',
        'canonical_url_hash',
        'purpose',
        'criteria',
        'frequency_minutes',
        'active',
        'version',
        'last_attempt_at',
        'last_success_at',
        'next_due_at',
    ];

    protected $casts = [
        'criteria' => 'array',
        'active' => 'boolean',
        'version' => 'integer',
        'frequency_minutes' => 'integer',
        'last_attempt_at' => 'datetime',
        'last_success_at' => 'datetime',
        'next_due_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
