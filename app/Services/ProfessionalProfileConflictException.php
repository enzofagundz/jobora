<?php

namespace App\Services;

use RuntimeException;

class ProfessionalProfileConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('O perfil foi alterado. Recarregue antes de salvar ou confirmar.');
    }
}
