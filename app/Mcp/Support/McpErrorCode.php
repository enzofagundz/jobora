<?php

namespace App\Mcp\Support;

/**
 * Códigos estáveis do envelope de erro das tools MCP (MCP_SPEC §5.4).
 */
enum McpErrorCode: string
{
    case Validation = 'validation';
    case NotFound = 'not_found';
    case Forbidden = 'forbidden';
    case Conflict = 'conflict';
    case Precondition = 'precondition';
    case AiUnavailable = 'ai_unavailable';
    case Configuration = 'configuration';
    case Internal = 'internal';
}
