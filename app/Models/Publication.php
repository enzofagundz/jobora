<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Hidden(['user_id'])]
class Publication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source',
        'external_id',
        'url',
        'canonical_url',
        'canonical_url_hash',
        'fingerprint',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(PublicationObservation::class);
    }

    public function opportunitySources(): HasMany
    {
        return $this->hasMany(OpportunitySource::class);
    }

    public function jobPostings(): BelongsToMany
    {
        return $this->belongsToMany(JobPosting::class, 'opportunity_sources')
            ->withPivot('is_legacy')
            ->withTimestamps();
    }
}
