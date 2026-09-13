<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use Throwable;

/**
 * Base dos resources do jobora-mcp. Resources são somente leitura e
 * devolvem JSON; erros viram {ok:false, error:{code,message,hint}}.
 */
abstract class JoboraResource extends Resource
{
    public function __construct(protected readonly McpUserResolver $users) {}

    final public function handle(Request $request): Response
    {
        try {
            return $this->read($request);
        } catch (ToolException $exception) {
            return $this->fail($exception);
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail(ToolException::internal());
        }
    }

    abstract protected function read(Request $request): Response;

    protected function user(): User
    {
        return $this->users->resolve();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ok(array $data): Response
    {
        return Response::json(['ok' => true, 'data' => $data]);
    }

    protected function fail(ToolException $exception): Response
    {
        return Response::json([
            'ok' => false,
            'error' => [
                'code' => $exception->errorCode->value,
                'message' => $exception->getMessage(),
                'hint' => $exception->hint,
            ],
        ]);
    }
}
