<?php

namespace App\Services\Triage;

use RuntimeException;

/**
 * Falha do pré-filtro: o chamador deve seguir para o LLM (fail-open).
 */
final class PrefilterException extends RuntimeException {}
