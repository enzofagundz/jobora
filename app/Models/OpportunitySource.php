<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunitySource extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_posting_id',
        'publication_id',
        'is_legacy',
        'publication_content_version',
        'publication_triage_result_id',
        'evidence_block_ids',
        'evidence',
    ];

    protected function casts(): array
    {
        return [
            'is_legacy' => 'boolean',
            'publication_content_version' => 'integer',
            'evidence_block_ids' => 'array',
            'evidence' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $source): void {
            if ($source->job_posting_id === null || $source->publication_id === null) {
                return;
            }

            $postingOwner = JobPosting::query()->whereKey($source->job_posting_id)->value('user_id');
            $publicationOwner = Publication::query()->whereKey($source->publication_id)->value('user_id');

            if ($postingOwner === null || $publicationOwner === null) {
                throw new \LogicException('Origem exige oportunidade e publicação existentes.');
            }

            if ((int) $postingOwner !== (int) $publicationOwner) {
                throw new \LogicException('Origem não pode ligar oportunidade e publicação de proprietários distintos.');
            }
        });
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function triageResult(): BelongsTo
    {
        return $this->belongsTo(PublicationTriageResult::class, 'publication_triage_result_id');
    }
}
