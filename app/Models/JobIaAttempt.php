<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobIaAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_posting_id',
        'user_id',
        'operation',
        'provider',
        'model',
        'status',
        'error_class',
        'error_message',
        'transient',
        'latency_ms',
        'input_chars',
        'prompt_tokens',
        'completion_tokens',
        'cache_write_input_tokens',
        'cache_read_input_tokens',
        'reasoning_tokens',
        'attempt_no',
        'trigger',
    ];

    protected function casts(): array
    {
        return [
            'transient' => 'boolean',
            'latency_ms' => 'integer',
            'input_chars' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cache_write_input_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'attempt_no' => 'integer',
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
