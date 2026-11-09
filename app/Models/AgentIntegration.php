<?php

namespace App\Models;

use App\Enums\AgentIntegrationScope;
use Database\Factories\AgentIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentIntegration extends Model
{
    /** @use HasFactory<AgentIntegrationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'oauth_client_id',
        'name',
        'scopes',
        'revoked_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function can(AgentIntegrationScope|string $scope): bool
    {
        $ability = $scope instanceof AgentIntegrationScope ? $scope->value : $scope;

        return ! $this->isRevoked() && in_array($ability, $this->scopes ?? [], true);
    }
}
