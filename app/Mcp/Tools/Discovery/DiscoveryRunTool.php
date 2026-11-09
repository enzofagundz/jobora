<?php

namespace App\Mcp\Tools\Discovery;

use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\AgentIntegration;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Services\Discovery\DiscoveryRunConflictException;
use App\Services\Discovery\DiscoveryRunService;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class DiscoveryRunTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        protected readonly DiscoveryRunService $runs,
    ) {
        parent::__construct($users);
    }

    protected function integration(): ?AgentIntegration
    {
        return app()->bound(AgentIntegrationContext::class)
            ? app(AgentIntegrationContext::class)->integration
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentRun(DiscoveryRun $run): array
    {
        return [
            'id' => $run->id,
            'execution_id' => $run->execution_id,
            'source_id' => $run->discovery_source_id,
            'surface' => $run->surface,
            'initial_url' => $run->initial_url,
            'status' => $run->status->value,
            'reserved_at' => $run->reserved_at?->toIso8601String(),
            'expires_at' => $run->expires_at?->toIso8601String(),
            'last_access_at' => $run->last_access_at?->toIso8601String(),
            'page_capture_id' => $run->page_capture_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSource(DiscoverySource $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'source' => $source->source,
            'surface' => $source->surface,
            'url' => $source->url,
            'frequency_minutes' => $source->frequency_minutes,
            'next_due_at' => $source->next_due_at?->toIso8601String(),
            'last_attempt_at' => $source->last_attempt_at?->toIso8601String(),
            'last_success_at' => $source->last_success_at?->toIso8601String(),
            'version' => $source->version,
        ];
    }

    /**
     * @param  callable(): mixed  $callback
     */
    protected function respond(callable $callback): Response|ResponseFactory
    {
        try {
            return $this->ok($callback());
        } catch (DiscoveryRunConflictException $exception) {
            throw ToolException::conflict($exception->getMessage());
        }
    }
}
