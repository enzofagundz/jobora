<?php

namespace App\Mcp\Tools\Captures;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Services\Discovery\CaptureConflictException;
use App\Services\Discovery\CaptureIngestionService;
use App\Services\Discovery\CaptureStorageException;
use App\Services\Discovery\CaptureTooLargeException;
use App\Services\Discovery\CaptureValidationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Ingere um envelope de captura consolidada v1 no mesmo serviço da API, após sanitizar e persistir HTML privado.')]
class IngestPageCaptureTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly CaptureIngestionService $ingestion,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        try {
            [$capture, $created] = $this->ingestion->ingest($this->user(), $request->all());
        } catch (CaptureTooLargeException $exception) {
            throw ToolException::validation($exception->getMessage());
        } catch (CaptureValidationException $exception) {
            throw ToolException::validation($exception->getMessage());
        } catch (CaptureConflictException $exception) {
            throw ToolException::conflict($exception->getMessage());
        } catch (CaptureStorageException $exception) {
            report($exception);

            throw ToolException::internal();
        }

        return $this->ok([
            'capture' => [
                'id' => $capture->id,
                'execution_id' => $capture->execution_id,
                'coverage' => $capture->coverage_status,
                'ingest_status' => $capture->ingest_status,
            ],
            'replay' => ! $created,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'format' => $schema->string()->required()->description('jobora.capture-envelope'),
            'version' => $schema->integer()->required()->description('Versão do contrato (1).'),
            'capture' => $schema->array()->required()->description('Objeto capture do contrato v1.'),
            'blocks' => $schema->array()->required()->description('Lista de blocos do contrato v1.'),
        ];
    }
}
