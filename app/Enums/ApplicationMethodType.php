<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ApplicationMethodType: string implements HasLabel
{
    case Email = 'email';
    case ExternalUrl = 'external_url';
    case LinkedinApply = 'linkedin_apply';
    case IndeedApply = 'indeed_apply';
    case Whatsapp = 'whatsapp';
    case Phone = 'phone';
    case Form = 'form';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::ExternalUrl => 'Candidatura externa',
            self::LinkedinApply => 'Candidatura pelo LinkedIn',
            self::IndeedApply => 'Candidatura pelo Indeed',
            self::Whatsapp => 'WhatsApp',
            self::Phone => 'Telefone',
            self::Form => 'Formulário',
            self::Other => 'Outro',
        };
    }
}
