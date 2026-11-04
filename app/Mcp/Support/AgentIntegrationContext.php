<?php

namespace App\Mcp\Support;

use App\Enums\AgentIntegrationScope;
use App\Models\AgentIntegration;
use App\Models\User;

final class AgentIntegrationContext
{
    public function __construct(
        public readonly User $user,
        public readonly AgentIntegration $integration,
    ) {}

    public function can(AgentIntegrationScope $scope): bool
    {
        return $this->integration->can($scope);
    }
}
