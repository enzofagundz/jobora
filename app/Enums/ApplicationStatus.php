<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ApplicationStatus: string implements HasLabel
{
    case Planned = 'planned';
    case Preparing = 'preparing';
    case Submitted = 'submitted';
    case Waiting = 'waiting';
    case Assessment = 'assessment';
    case Interview = 'interview';
    case Offer = 'offer';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => 'Planejada',
            self::Preparing => 'Em preparação',
            self::Submitted => 'Enviada',
            self::Waiting => 'Aguardando retorno',
            self::Assessment => 'Avaliação',
            self::Interview => 'Entrevista',
            self::Offer => 'Oferta',
            self::Completed => 'Concluída',
            self::Rejected => 'Rejeitada',
            self::Withdrawn => 'Retirada',
        };
    }

    /**
     * Ordem visual da pipeline principal (orientação, não regra de transição).
     *
     * @return array<int, self>
     */
    public static function pipelineStages(): array
    {
        return [
            self::Planned,
            self::Preparing,
            self::Submitted,
            self::Waiting,
            self::Assessment,
            self::Interview,
            self::Offer,
            self::Completed,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Withdrawn], true);
    }

    /**
     * Próxima etapa sugerida na sequência visual, ou null se for a última.
     */
    public function nextPipelineStage(): ?self
    {
        $stages = self::pipelineStages();
        $index = array_search($this, $stages, true);

        if ($index === false || $index === array_key_last($stages)) {
            return null;
        }

        return $stages[$index + 1];
    }
}
