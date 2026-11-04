<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationObservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_capture_id',
        'publication_id',
        'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
        ];
    }

    public function pageCapture(): BelongsTo
    {
        return $this->belongsTo(PageCapture::class);
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
