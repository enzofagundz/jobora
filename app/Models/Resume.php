<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['user_id'])]
class Resume extends Model
{
    use HasFactory;

    protected $fillable = ['content'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
