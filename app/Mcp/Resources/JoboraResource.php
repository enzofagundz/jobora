<?php

namespace App\Mcp\Resources;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Models\User;
use Illuminate\Support\Str;
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

    /**
     * Nome do resource em snake_case, sem o sufixo "Resource".
     */
    public function name(): string
    {
        $base = class_basename(static::class);

        if (str_ends_with($base, 'Resource')) {
            $base = substr($base, 0, -8);
        }

        return Str::snake($base);
    }

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
     * Idioma opcional da leitura. Aceita o argumento `language` da chamada
     * ou a query da URI do resource (ex.: jobora://resume?language=en);
     * omitido equivale a pt-BR.
     */
    protected function language(Request $request): ResumeLanguage
    {
        $value = $request->get('language');

        if ($value === null) {
            $query = [];
            parse_str((string) parse_url((string) $request->uri(), PHP_URL_QUERY), $query);
            $value = $query['language'] ?? null;
        }

        return ResumeLanguage::fromInput($value);
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
