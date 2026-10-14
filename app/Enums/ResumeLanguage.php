<?php

namespace App\Enums;

enum ResumeLanguage: string
{
    case PtBr = 'pt-BR';
    case En = 'en';

    /**
     * Idioma a partir de um valor de entrada opcional; ausente ou não
     * reconhecido equivale a pt-BR. A validação (`Rule::enum`) já rejeita
     * valores inválidos antes da conversão nos pontos de entrada MCP.
     */
    public static function fromInput(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::PtBr;
    }

    /**
     * Rótulo do documento no idioma da versão: o currículo canônico é
     * "Currículo"; a versão em inglês é "Resume".
     */
    public function documentLabel(): string
    {
        return match ($this) {
            self::PtBr => 'Currículo',
            self::En => 'Resume',
        };
    }

    /**
     * Orientação exibida quando o idioma pedido não tem currículo cadastrado.
     */
    public function missingResumeGuidance(): string
    {
        return match ($this) {
            self::PtBr => 'Cadastre o currículo antes de adequá-lo a uma vaga.',
            self::En => 'Não há currículo em inglês. Cadastre ou gere a versão EN antes de adequá-lo a uma vaga.',
        };
    }
}
