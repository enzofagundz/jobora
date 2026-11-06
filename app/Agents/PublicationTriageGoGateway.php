<?php

namespace App\Agents;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Usage;

final class PublicationTriageGoGateway extends OpenAiCompatibleGateway
{
    public ?int $promptTokens = null;

    public ?int $completionTokens = null;

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return parent::client($provider, $timeout)->withoutRedirecting();
    }

    protected function extractUsage(array $data): Usage
    {
        $this->promptTokens = is_int($data['usage']['prompt_tokens'] ?? null) ? $data['usage']['prompt_tokens'] : null;
        $this->completionTokens = is_int($data['usage']['completion_tokens'] ?? null) ? $data['usage']['completion_tokens'] : null;

        return parent::extractUsage($data);
    }
}
