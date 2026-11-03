<?php

namespace App\Services\Triage;

interface JobPrefilter
{
    /**
     * @throws PrefilterException
     */
    public function classify(PrefilterInput $input): PrefilterVerdict;

    /**
     * Classifica as publicações em paralelo e devolve, para cada índice,
     * um veredito ou a falha daquela captura (fail-open por captura).
     *
     * @param  array<int, PrefilterInput>  $inputs
     * @return array<int, PrefilterVerdict|PrefilterException>
     */
    public function classifyMany(array $inputs): array;
}
