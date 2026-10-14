<?php

namespace App\Models;

use App\Enums\ResumeLanguage;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['user_id'])]
class Resume extends Model
{
    use HasFactory;

    protected $fillable = ['content', 'language', 'source_fingerprint'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'language' => ResumeLanguage::class,
        ];
    }
}
