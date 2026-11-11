<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileImportProposal extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'base_version' => 'integer', 'base_confirmed_version' => 'integer', 'result_version' => 'integer',
            'source' => 'encrypted:array', 'base_document' => 'encrypted:array',
            'candidate' => 'encrypted:array', 'evidence' => 'encrypted:array',
            'requester_actor' => 'encrypted:array', 'decision_actor' => 'encrypted:array',
            'decision_result' => 'encrypted:array', 'decided_at' => 'immutable_datetime',
            'prompt_tokens' => 'integer', 'completion_tokens' => 'integer',
        ];
    }
}
