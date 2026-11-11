<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationClefResult extends Model
{
    protected $fillable = [
        'publication_id',
        'content_version',
        'attempt',
        'status',
        'probability',
        'category',
        'confidence',
        'model',
        'classifier_identifier',
        'error',
        'lease_token',
        'started_at',
        'classified_at',
    ];

    protected function casts(): array
    {
        return [
            'content_version' => 'integer',
            'attempt' => 'integer',
            'probability' => 'float',
            'confidence' => 'float',
            'started_at' => 'datetime',
            'classified_at' => 'datetime',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
