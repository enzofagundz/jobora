<?php

namespace App\Models;

use App\Enums\AiOperationType;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Hidden(['user_id', 'deleted_at'])]
class PageCapture extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'source',
        'surface',
        'url',
        'html_path',
        'captured_at',
        'legacy_job_capture_id',
        'execution_id',
        'idempotency_key',
        'payload_hash',
        'format_version',
        'ingest_status',
        'coverage_status',
        'coverage_reason',
        'blocks_expected',
        'blocks_captured',
        'raw_expires_at',
        'raw_deleted_at',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'raw_expires_at' => 'datetime',
            'raw_deleted_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function legacyJobCapture(): BelongsTo
    {
        return $this->belongsTo(JobCapture::class, 'legacy_job_capture_id')->withTrashed();
    }

    public function observations(): HasMany
    {
        return $this->hasMany(PublicationObservation::class);
    }

    public function extractionOperation(): HasOne
    {
        return $this->hasOne(AiOperation::class, 'target_id')->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query
                ->where('target_type', 'page_capture')
                ->where('operation_type', AiOperationType::PublicationExtraction->value),
        );
    }
}
