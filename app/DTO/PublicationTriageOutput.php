<?php

namespace App\DTO;

use App\Enums\ApplicationMethodType;
use InvalidArgumentException;

final class PublicationTriageOutput
{
    /**
     * @param list<array{
     *     role: string|null,
     *     company: string|null,
     *     description: string|null,
     *     location: string|null,
     *     work_mode: string|null,
     *     employment_type: string|null,
     *     salary_min: float|null,
     *     salary_max: float|null,
     *     salary_currency: string|null,
     *     salary_period: string|null,
     *     seniority: string|null,
     *     requirements: list<string>,
     *     technologies: list<string>,
     *     application_methods: list<array{type: string, platform: string|null, url: string|null, email: string|null, phone: string|null, instructions: string|null}>,
     *     evidence: list<array{quote: string, block_ids: list<string>, fields: list<string>}>,
     *     readiness: 'ready'|'needs_review',
     *     review_reasons: list<string>
     * }> $opportunities
     */
    public function __construct(public readonly array $opportunities) {}

    /**
     * @param  list<string>  $sourceBlockIds
     */
    public static function fromStructured(mixed $data, string $content, array $sourceBlockIds, ?string $sourceUrl): self
    {
        if (! is_array($data) || array_keys($data) !== ['opportunities'] || ! is_array($data['opportunities']) || ! array_is_list($data['opportunities'])) {
            throw new InvalidArgumentException('Structured output envelope is invalid.');
        }

        if (count($data['opportunities']) > 20) {
            throw new InvalidArgumentException('Structured output has too many opportunities.');
        }

        $opportunities = [];

        foreach ($data['opportunities'] as $opportunity) {
            $opportunities[] = self::opportunity($opportunity, $content, $sourceBlockIds, $sourceUrl);
        }

        return new self($opportunities);
    }

    /**
     * @param array{
     *     role?: mixed,
     *     company?: mixed,
     *     description?: mixed,
     *     location?: mixed,
     *     work_mode?: mixed,
     *     employment_type?: mixed,
     *     salary_min?: mixed,
     *     salary_max?: mixed,
     *     salary_currency?: mixed,
     *     salary_period?: mixed,
     *     seniority?: mixed,
     *     requirements?: list<mixed>,
     *     technologies?: list<mixed>,
     *     application_methods?: list<array<string, mixed>>,
     *     evidence?: list<array{quote?: mixed, block_ids?: list<mixed>, fields?: list<mixed>}>
     * } $opportunity
     * @return list<string>
     */
    public static function unsupportedFields(array $opportunity): array
    {
        $unsupported = [];

        foreach ([
            'role', 'company', 'description', 'location', 'work_mode', 'employment_type',
            'salary_currency', 'salary_period', 'seniority',
        ] as $field) {
            $value = $opportunity[$field] ?? null;

            if ($value !== null && (! is_string($value) || ! self::quoteContains(self::quotesForField($opportunity, $field), $value))) {
                $unsupported[] = $field;
            }
        }

        foreach (['salary_min', 'salary_max'] as $field) {
            $value = $opportunity[$field] ?? null;

            if ($value !== null && ((! is_int($value) && ! is_float($value) && ! is_string($value))
                || ! self::quoteContainsNumber(self::quotesForField($opportunity, $field), (float) $value))) {
                $unsupported[] = $field;
            }
        }

        foreach (['requirements', 'technologies'] as $field) {
            foreach ($opportunity[$field] ?? [] as $value) {
                if (! is_string($value) || ! self::quoteContains(self::quotesForField($opportunity, $field), $value)) {
                    $unsupported[] = $field;
                    break;
                }
            }
        }

        foreach ($opportunity['application_methods'] ?? [] as $method) {
            $type = $method['type'] ?? null;
            $quotes = self::quotesForField($opportunity, 'application_methods');

            if (! is_string($type) || ! self::methodTypeSupported($type, $method, $quotes)) {
                $unsupported[] = 'application_methods';

                continue;
            }

            foreach (['platform', 'url', 'email', 'phone', 'instructions'] as $field) {
                $value = $method[$field] ?? null;

                if ($value !== null && (! is_string($value) || ! self::quoteContains($quotes, $value))) {
                    $unsupported[] = 'application_methods';
                    break;
                }
            }
        }

        return array_values(array_unique($unsupported));
    }

    /** @return list<string> */
    public static function evidenceFields(): array
    {
        return [
            'role', 'company', 'description', 'location', 'work_mode', 'employment_type',
            'salary_min', 'salary_max', 'salary_currency', 'salary_period', 'seniority',
            'requirements', 'technologies', 'application_methods',
        ];
    }

    /**
     * @param  list<string>  $sourceBlockIds
     * @return array{
     *     role: string|null,
     *     company: string|null,
     *     description: string|null,
     *     location: string|null,
     *     work_mode: string|null,
     *     employment_type: string|null,
     *     salary_min: float|null,
     *     salary_max: float|null,
     *     salary_currency: string|null,
     *     salary_period: string|null,
     *     seniority: string|null,
     *     requirements: list<string>,
     *     technologies: list<string>,
     *     application_methods: list<array{type: string, platform: string|null, url: string|null, email: string|null, phone: string|null, instructions: string|null}>,
     *     evidence: list<array{quote: string, block_ids: list<string>, fields: list<string>}>,
     *     readiness: 'ready'|'needs_review',
     *     review_reasons: list<string>
     * }
     */
    private static function opportunity(mixed $data, string $content, array $sourceBlockIds, ?string $sourceUrl): array
    {
        $fields = [
            'role', 'company', 'description', 'location', 'work_mode', 'employment_type',
            'salary_min', 'salary_max', 'salary_currency', 'salary_period', 'seniority',
            'requirements', 'technologies', 'application_methods', 'evidence',
        ];

        if (! is_array($data) || array_diff(array_keys($data), $fields) !== []) {
            throw new InvalidArgumentException('Opportunity structure is invalid.');
        }

        $opportunity = [
            'role' => self::string($data, 'role', 255),
            'company' => self::string($data, 'company', 255),
            'description' => self::string($data, 'description', 100000),
            'location' => self::string($data, 'location', 255),
            'work_mode' => self::string($data, 'work_mode', 100),
            'employment_type' => self::string($data, 'employment_type', 100),
            'salary_min' => self::number($data, 'salary_min'),
            'salary_max' => self::number($data, 'salary_max'),
            'salary_currency' => self::string($data, 'salary_currency', 3),
            'salary_period' => self::string($data, 'salary_period', 50),
            'seniority' => self::string($data, 'seniority', 100),
            'requirements' => self::strings($data, 'requirements', 100),
            'technologies' => self::strings($data, 'technologies', 100),
            'application_methods' => self::applicationMethods($data['application_methods'] ?? []),
            'evidence' => self::evidence($data['evidence'] ?? []),
        ];

        $reviewReasons = [];

        if (blank($opportunity['role'])) {
            $reviewReasons[] = 'missing_role';
        }

        if ($sourceUrl !== null && (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $reviewReasons[] = 'invalid_publication_url';
        }

        if ($opportunity['evidence'] === []) {
            $reviewReasons[] = 'missing_evidence';
        }

        foreach ($opportunity['evidence'] as $evidence) {
            if ($evidence['block_ids'] === []) {
                $reviewReasons[] = 'missing_evidence_block';
            }

            if (! str_contains(mb_strtolower($content), mb_strtolower($evidence['quote']))) {
                $reviewReasons[] = 'evidence_not_in_publication';
            }

            if (array_diff($evidence['block_ids'], $sourceBlockIds) !== []) {
                $reviewReasons[] = 'unknown_evidence_block';
            }
        }

        if (self::unsupportedFields($opportunity) !== []) {
            $reviewReasons[] = 'unsupported_field_evidence';
        }

        if ($opportunity['salary_min'] !== null && $opportunity['salary_max'] !== null
            && $opportunity['salary_min'] > $opportunity['salary_max']) {
            $reviewReasons[] = 'salary_range_inconsistent';
        }

        if (($opportunity['salary_min'] ?? 0) < 0 || ($opportunity['salary_max'] ?? 0) < 0) {
            $reviewReasons[] = 'salary_range_inconsistent';
        }

        foreach ($opportunity['application_methods'] as $method) {
            if ($method['url'] !== null && (filter_var($method['url'], FILTER_VALIDATE_URL) === false
                || ! in_array(strtolower((string) parse_url($method['url'], PHP_URL_SCHEME)), ['http', 'https'], true))) {
                $reviewReasons[] = 'application_url_inconsistent';
            }
        }

        $opportunity['readiness'] = $reviewReasons === [] ? 'ready' : 'needs_review';
        $opportunity['review_reasons'] = array_values(array_unique($reviewReasons));

        return $opportunity;
    }

    private static function string(array $data, string $key, int $maximumLength): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && (! is_string($value) || mb_strlen($value) > $maximumLength)) {
            throw new InvalidArgumentException("Structured output field {$key} is invalid.");
        }

        return $value;
    }

    private static function number(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ((! is_int($value) && ! is_float($value))
            || ! is_finite((float) $value)
            || abs((float) $value) > 999999999999.99
            || abs((float) $value - round((float) $value, 2)) > 0.000001)) {
            throw new InvalidArgumentException("Structured output field {$key} is invalid.");
        }

        return $value === null ? null : (float) $value;
    }

    private static function strings(array $data, string $key, int $maximumLength): array
    {
        $values = $data[$key] ?? [];

        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException("Structured output field {$key} is invalid.");
        }

        foreach ($values as $value) {
            if (! is_string($value) || $value === '' || mb_strlen($value) > $maximumLength) {
                throw new InvalidArgumentException("Structured output field {$key} contains an invalid value.");
            }
        }

        return $values;
    }

    /**
     * @return list<array{type: string, platform: string|null, url: string|null, email: string|null, phone: string|null, instructions: string|null}>
     */
    private static function applicationMethods(mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('Structured output application methods are invalid.');
        }

        $methods = [];

        foreach ($values as $method) {
            if (! is_array($method) || array_diff(array_keys($method), ['type', 'platform', 'url', 'email', 'phone', 'instructions']) !== []) {
                throw new InvalidArgumentException('Structured output application method is invalid.');
            }

            $type = $method['type'] ?? null;

            if (! is_string($type) || ApplicationMethodType::tryFrom($type) === null) {
                throw new InvalidArgumentException('Structured output application method type is invalid.');
            }

            $methods[] = [
                'type' => $type,
                'platform' => self::string($method, 'platform', 100),
                'url' => self::string($method, 'url', 2048),
                'email' => self::string($method, 'email', 320),
                'phone' => self::string($method, 'phone', 50),
                'instructions' => self::string($method, 'instructions', 10000),
            ];
        }

        return $methods;
    }

    /** @return list<array{quote: string, block_ids: list<string>, fields: list<string>}> */
    private static function evidence(mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('Structured output evidence is invalid.');
        }

        $evidenceItems = [];

        foreach ($values as $evidence) {
            if (! is_array($evidence) || array_diff(array_keys($evidence), ['quote', 'block_ids', 'fields']) !== []) {
                throw new InvalidArgumentException('Structured output evidence item is invalid.');
            }

            $quote = $evidence['quote'] ?? null;

            if (! is_string($quote) || $quote === '' || mb_strlen($quote) > 2000) {
                throw new InvalidArgumentException('Structured output evidence quote is invalid.');
            }

            $fields = self::strings($evidence, 'fields', 64);

            if (array_diff($fields, self::evidenceFields()) !== []) {
                throw new InvalidArgumentException('Structured output evidence fields are invalid.');
            }

            $evidenceItems[] = [
                'quote' => $quote,
                'block_ids' => self::strings($evidence, 'block_ids', 128),
                'fields' => array_values(array_unique($fields)),
            ];
        }

        return $evidenceItems;
    }

    /**
     * @param  array{evidence?: list<array{quote?: mixed, fields?: list<mixed>}>}  $opportunity
     * @return list<string>
     */
    private static function quotesForField(array $opportunity, string $field): array
    {
        $quotes = [];

        foreach ($opportunity['evidence'] ?? [] as $evidence) {
            if (in_array($field, $evidence['fields'] ?? [], true) && is_string($evidence['quote'] ?? null)) {
                $quotes[] = $evidence['quote'];
            }
        }

        return $quotes;
    }

    /** @param list<string> $quotes */
    private static function quoteContains(array $quotes, string $value): bool
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));

        if ($value === '') {
            return false;
        }

        foreach ($quotes as $quote) {
            $quote = trim(preg_replace('/\s+/u', ' ', $quote));
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $quote) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $quotes */
    private static function quoteContainsNumber(array $quotes, float $value): bool
    {
        foreach ($quotes as $quote) {
            preg_match_all('/(?<!\d)\d+(?:[.,]\d+)*(?!\d)/u', $quote, $matches);

            foreach ($matches[0] as $token) {
                $lastSeparator = max((int) strrpos($token, '.'), (int) strrpos($token, ','));

                if ($lastSeparator === 0) {
                    if (abs((float) $token - $value) < 0.000001) {
                        return true;
                    }

                    continue;
                }

                $decimalDigits = strlen($token) - $lastSeparator - 1;

                if ($decimalDigits === 3) {
                    continue;
                }

                $wholeNumber = preg_replace('/\D+/', '', substr($token, 0, $lastSeparator));
                $normalized = $wholeNumber.'.'.substr($token, $lastSeparator + 1);

                if (is_numeric($normalized) && abs((float) $normalized - $value) < 0.000001) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $method
     * @param  list<string>  $quotes
     */
    private static function methodTypeSupported(string $type, array $method, array $quotes): bool
    {
        return match (ApplicationMethodType::tryFrom($type)) {
            ApplicationMethodType::Email => self::quoteContains($quotes, (string) ($method['email'] ?? '')),
            ApplicationMethodType::ExternalUrl => self::quoteContainsAny($quotes, ['apply', 'application', 'candid', 'inscrev']),
            ApplicationMethodType::LinkedinApply => self::quoteContainsAny($quotes, ['linkedin']),
            ApplicationMethodType::IndeedApply => self::quoteContainsAny($quotes, ['indeed']),
            ApplicationMethodType::Whatsapp => self::quoteContainsAny($quotes, ['whatsapp']),
            ApplicationMethodType::Phone => self::quoteContainsAny($quotes, ['phone', 'telephone', 'telefone', 'ligue', 'call']),
            ApplicationMethodType::Form => self::quoteContainsAny($quotes, ['form', 'formulário', 'formulario']),
            ApplicationMethodType::Other, null => false,
        };
    }

    /** @param list<string> $quotes @param list<string> $values */
    private static function quoteContainsAny(array $quotes, array $values): bool
    {
        foreach ($values as $value) {
            foreach ($quotes as $quote) {
                if (mb_stripos($quote, $value) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
