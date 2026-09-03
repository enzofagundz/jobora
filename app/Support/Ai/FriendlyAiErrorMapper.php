<?php

namespace App\Support\Ai;

use InvalidArgumentException;
use Throwable;

final class FriendlyAiErrorMapper
{
    public static function for(Throwable $e): string
    {
        $message = $e->getMessage();
        $lower = mb_strtolower($message);

        if ($e instanceof InvalidArgumentException && str_contains($message, 'JSON válido')) {
            return 'Não conseguimos analisar esta vaga porque a IA devolveu um formato inválido. Tente novamente — se persistir, revise a descrição.';
        }

        if (str_contains($lower, 'concurrent lock') || str_contains($lower, 'rate limit') || str_contains($message, 'Muitas análises')) {
            return 'Muitas análises ao mesmo tempo. Aguarde alguns segundos e tente novamente.';
        }

        if (str_contains($lower, 'timeout') || str_contains($message, 'demorou demais')) {
            return 'A análise demorou demais e foi interrompida. Tente novamente.';
        }

        if (TransientAiClassifier::isTransient($e)) {
            return 'A análise falhou por instabilidade temporária do provedor de IA. Tente novamente em instantes.';
        }

        return 'Não foi possível concluir a análise com IA. Tente novamente.';
    }
}
