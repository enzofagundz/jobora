<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'status',
        'title',
        'notes',
        'contact',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
