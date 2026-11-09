<?php

namespace App\Models;

use App\Enums\DiscoveryRunStatus;
use Database\Factories\DiscoveryRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscoveryRun extends Model
{
    /** @use HasFactory<DiscoveryRunFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'discovery_source_id',
        'agent_integration_id',
        'execution_id',
        'idempotency_key',
        'status',
        'active_key',
        'canonical_url_hash',
        'initial_url',
        'surface',
        'reserved_at',
        'expires_at',
        'last_access_at',
        'completed_at',
        'page_capture_id',
    ];

    protected $casts = [
        'status' => DiscoveryRunStatus::class,
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_access_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DiscoverySource::class, 'discovery_source_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(AgentIntegration::class, 'agent_integration_id');
    }

    public function pageCapture(): BelongsTo
    {
        return $this->belongsTo(PageCapture::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DiscoveryRunEvent::class);
    }
}
