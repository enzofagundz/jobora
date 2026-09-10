<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use LogicException;
use Throwable;

/**
 * Base das tools do jobora-mcp. Garante o envelope do contrato:
 * sucesso = {ok:true, data:{...}} (Response::structured) e
 * erro    = {ok:false, error:{code, message, hint}}.
 */
abstract class JoboraTool extends Tool
{
    public function __construct(protected readonly McpUserResolver $users) {}

    final public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return $this->execute($request);
        } catch (ToolException $exception) {
            return $this->fail($exception);
        } catch (ValidationException $exception) {
            return $this->fail(ToolException::validation(
                (string) (collect($exception->errors())->flatten()->first() ?? 'Dados inválidos.'),
            ));
        } catch (ModelNotFoundException) {
            return $this->fail(ToolException::notFound());
        } catch (AuthenticationException|AuthorizationException $exception) {
            return $this->fail(ToolException::forbidden($exception->getMessage() ?: 'Acesso negado.'));
        } catch (LogicException $exception) {
            return $this->fail(ToolException::precondition($exception->getMessage() ?: 'Operação não permitida.'));
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail(ToolException::internal());
        }
    }

    abstract protected function execute(Request $request): Response|ResponseFactory;

    protected function user(): User
    {
        return $this->users->resolve();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ok(array $data): ResponseFactory
    {
        return Response::structured(['ok' => true, 'data' => $data]);
    }

    protected function fail(ToolException $exception): Response
    {
        $payload = json_encode([
            'ok' => false,
            'error' => [
                'code' => $exception->errorCode->value,
                'message' => $exception->getMessage(),
                'hint' => $exception->hint,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Response::error($payload === false ? '{"ok":false,"error":{"code":"internal","message":"Erro interno."}}' : $payload);
    }
}
