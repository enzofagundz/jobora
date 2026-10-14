<?php

namespace App\Mcp\Server\Methods;

use InvalidArgumentException;
use Laravel\Mcp\Server\Methods\ReadResource as BaseReadResource;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\ServerContext;

/**
 * `resources/read` do jobora-mcp.
 *
 * O framework resolve resources de URI fixa por igualdade exata, então
 * `jobora://resume?language=en` não chega ao handler. A leitura do idioma
 * via query da URI (contrato documentado no MCP_SPEC) exige casar a URI com
 * a query removida; templates seguem a resolução padrão do framework.
 */
class ReadResource extends BaseReadResource
{
    protected function resolveResource(?string $uri, ServerContext $context): Resource
    {
        if (! $uri) {
            throw new InvalidArgumentException('Missing [uri] parameter.');
        }

        $resource = $context->resources()->first(fn ($resource): bool => $resource->uri() === $uri);

        if (! $resource) {
            $withoutQuery = $this->withoutQuery($uri);

            if ($withoutQuery !== $uri) {
                $resource = $context->resources()->first(fn ($resource): bool => $resource->uri() === $withoutQuery);
            }
        }

        $resource ??= $context->resourceTemplates()->first(
            fn ($template): bool => (string) $template->uriTemplate() === $uri
                || $template->uriTemplate()->match($uri) !== null,
        );

        if (! $resource) {
            throw new InvalidArgumentException("Resource [{$uri}] not found.");
        }

        return $resource;
    }

    private function withoutQuery(string $uri): string
    {
        $queryPosition = strpos($uri, '?');

        return $queryPosition === false ? $uri : substr($uri, 0, $queryPosition);
    }
}
