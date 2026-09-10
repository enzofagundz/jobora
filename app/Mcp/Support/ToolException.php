<?php

namespace App\Mcp\Support;

use RuntimeException;

/**
 * Falha tipada de uma tool MCP. O envelope {code, message, hint} é montado
 * por JoboraTool::fail() e nunca inclui stack trace, SQL ou credenciais.
 */
final class ToolException extends RuntimeException
{
    private function __construct(
        public readonly McpErrorCode $errorCode,
        string $message,
        public readonly ?string $hint = null,
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message, ?string $hint = null): self
    {
        return new self(McpErrorCode::Validation, $message, $hint);
    }

    public static function notFound(string $message = 'Recurso não encontrado.', ?string $hint = null): self
    {
        return new self(McpErrorCode::NotFound, $message, $hint);
    }

    public static function forbidden(string $message = 'Acesso negado.', ?string $hint = null): self
    {
        return new self(McpErrorCode::Forbidden, $message, $hint);
    }

    public static function conflict(string $message, ?string $hint = null): self
    {
        return new self(McpErrorCode::Conflict, $message, $hint);
    }

    public static function precondition(string $message, ?string $hint = null): self
    {
        return new self(McpErrorCode::Precondition, $message, $hint);
    }

    public static function aiUnavailable(string $message, ?string $hint = null): self
    {
        return new self(McpErrorCode::AiUnavailable, $message, $hint);
    }

    public static function configuration(string $message, ?string $hint = null): self
    {
        return new self(McpErrorCode::Configuration, $message, $hint);
    }

    public static function internal(string $message = 'Erro interno ao processar a solicitação.', ?string $hint = null): self
    {
        return new self(McpErrorCode::Internal, $message, $hint);
    }
}
