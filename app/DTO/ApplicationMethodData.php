<?php

namespace App\DTO;

use App\Enums\ApplicationMethodType;
use InvalidArgumentException;

class ApplicationMethodData
{
    public function __construct(
        public readonly ApplicationMethodType $type,
        public readonly ?string $platform,
        public readonly ?string $url,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $instructions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $get = fn (string $key): ?string => self::normalizeText($data[$key] ?? null);

        $typeValue = $data['type'] ?? '';
        if ($typeValue instanceof ApplicationMethodType) {
            $typeValue = $typeValue->value;
        }

        if (! is_string($typeValue)) {
            throw new InvalidArgumentException('Campo "type" deve ser string.');
        }

        $typeValue = trim($typeValue);
        $type = ApplicationMethodType::tryFrom($typeValue)
            ?? throw new InvalidArgumentException("Tipo de candidatura inválido: {$typeValue}.");

        $url = $get('url');
        $email = $get('email');

        if ($url !== null) {
            if (str_starts_with(strtolower($url), 'mailto:')) {
                $email ??= strtolower(substr($url, 7));
                $url = null;
            } elseif (! preg_match('#^https?://#i', $url)) {
                throw new InvalidArgumentException("URL deve usar esquema http(s): {$url}");
            }
        }

        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("E-mail inválido: {$email}");
        }

        if (! self::hasUsefulData($type, $url, $email, $get('phone'), $get('instructions'))) {
            return null;
        }

        return new self(
            type: $type,
            platform: $get('platform'),
            url: $url,
            email: $email !== null ? strtolower($email) : null,
            phone: $get('phone'),
            instructions: $get('instructions'),
        );
    }

    /**
     * @return array{type: string, platform: string|null, url: string|null, email: string|null, phone: string|null, instructions: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'platform' => $this->platform,
            'url' => $this->url,
            'email' => $this->email,
            'phone' => $this->phone,
            'instructions' => $this->instructions,
        ];
    }

    public function dedupKey(): string
    {
        $payload = [
            'type' => $this->type->value,
            'platform' => mb_strtolower((string) $this->platform),
            'url' => rtrim((string) $this->url, '/'),
            'email' => (string) $this->email,
            'phone' => preg_replace('/\D+/', '', (string) $this->phone),
            'instructions' => (string) $this->instructions,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', $value));

        return $normalized === '' ? null : $normalized;
    }

    private static function hasUsefulData(
        ApplicationMethodType $type,
        ?string $url,
        ?string $email,
        ?string $phone,
        ?string $instructions,
    ): bool {
        $direct = $url !== null || $email !== null || $phone !== null;
        if ($direct) {
            return true;
        }

        // linkedin_apply/indeed_apply podem existir sem URL quando o texto afirma candidatura direta.
        if (in_array($type, [ApplicationMethodType::LinkedinApply, ApplicationMethodType::IndeedApply], true)) {
            return true;
        }

        if ($type === ApplicationMethodType::Other && $instructions !== null) {
            return true;
        }

        if ($type === ApplicationMethodType::Form && $instructions !== null) {
            return true;
        }

        return false;
    }
}
