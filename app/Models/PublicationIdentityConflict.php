<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['user_id'])]
class PublicationIdentityConflict extends Model
{
    use HasFactory;

    public const ExternalIdUrlMismatch = 'external_id_url_mismatch';

    protected $fillable = [
        'user_id',
        'source',
        'external_id',
        'canonical_url',
        'identity_hash',
        'publication_id',
        'other_publication_id',
        'reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $conflict): void {
            if ($conflict->identity_hash !== null && $conflict->identity_hash !== '') {
                return;
            }

            $conflict->identity_hash = hash('sha256', implode('|', [
                $conflict->user_id,
                $conflict->source,
                $conflict->external_id ?? '',
                $conflict->canonical_url ?? '',
            ]));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function otherPublication(): BelongsTo
    {
        return $this->belongsTo(Publication::class, 'other_publication_id');
    }
}
