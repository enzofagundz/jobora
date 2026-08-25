<?php

namespace App\DTO;

use App\Enums\ConceptCategory;
use App\Services\ConceptNormalizer;
use InvalidArgumentException;

/**
 * Resultado estruturado e validado da extração da IA.
 *
 * Falhas estruturais (tipos errados) lançam InvalidArgumentException —
 * o JobAnalysisService as converte em status=failed. Conteúdo ruidoso
 * (nomes vazios/longos, duplicados) é limpo silenciosamente.
 */
final class JobAnalysisResult
{
    public const MAX_ITEMS = 100;

    public const MAX_NAME_LENGTH = 100;

    public const MAX_APPLICATION_METHODS = 20;

    /**
     * @param  array<string, list<string>>  $items  category value => normalized names
     */
    public function __construct(
        public readonly ?string $role,
        public readonly ?string $company,
        public readonly array $items,
        public readonly array $applicationMethods = [],
    ) {}

    /**
     * Build a validated result from the agent's structured output.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromStructured(array $data, ConceptNormalizer $normalizer): self
    {
        $role = self::nullableString($data['role'] ?? null, 'role');
        $company = self::nullableString($data['company'] ?? null, 'company');

        $items = [];
        $total = 0;

        foreach (ConceptCategory::all() as $category) {
            if ($total >= self::MAX_ITEMS) {
                break;
            }

            $key = self::schemaKey($category);
            $raw = $data[$key] ?? [];

            if ($raw === null || $raw === []) {
                continue;
            }

            if (! is_array($raw) || ! array_is_list($raw)) {
                throw new InvalidArgumentException("Campo \"{$key}\" deve ser uma lista de strings.");
            }

            $names = [];

            foreach ($raw as $entry) {
                if (! is_string($entry)) {
                    throw new InvalidArgumentException("Campo \"{$key}\" deve conter apenas strings.");
                }

                $name = $normalizer->normalize($entry);

                if ($name === '' || strlen($name) > self::MAX_NAME_LENGTH) {
                    continue;
                }

                if (in_array($name, $names, true)) {
                    continue;
                }

                $names[] = $name;
            }

            if ($names === []) {
                continue;
            }

            // Keep category order; cut the tail once the 100-item cap is hit.
            $items[$category->value] = array_slice($names, 0, self::MAX_ITEMS - $total);
            $total += count($items[$category->value]);
        }

        $methods = [];
        $seen = [];
        $rawMethods = $data['application_methods'] ?? [];
        if ($rawMethods === null) {
            $rawMethods = [];
        }
        if (! is_array($rawMethods) || ! array_is_list($rawMethods)) {
            throw new InvalidArgumentException('Campo "application_methods" deve ser uma lista de objetos.');
        }
        foreach ($rawMethods as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Campo "application_methods" deve conter apenas objetos.');
            }
            $method = ApplicationMethodData::fromArray($entry);
            if ($method === null || isset($seen[$method->dedupKey()])) {
                continue;
            }
            $seen[$method->dedupKey()] = true;
            if (count($methods) >= self::MAX_APPLICATION_METHODS) {
                break;
            }
            $methods[] = $method;
        }

        return new self($role, $company, $items, $methods);
    }

    /**
     * Array shape stored in job_postings.suggested_data for the review page.
     *
     * @return array{role: string|null, company: string|null, items: array<int, array{category: string, name: string}>, application_methods: list<array<string, string|null>>}
     */
    public function toSuggestedData(): array
    {
        $items = [];

        foreach ($this->items as $category => $names) {
            foreach ($names as $name) {
                $items[] = ['category' => $category, 'name' => $name];
            }
        }

        return [
            'role' => $this->role,
            'company' => $this->company,
            'items' => $items,
            'application_methods' => array_map(fn (ApplicationMethodData $m) => $m->toArray(), $this->applicationMethods),
        ];
    }

    private static function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("Campo \"{$field}\" deve ser uma string ou null.");
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }

    private static function schemaKey(ConceptCategory $category): string
    {
        return match ($category) {
            ConceptCategory::Skill => 'skills',
            ConceptCategory::Tool => 'tools',
            ConceptCategory::Methodology => 'methodologies',
            ConceptCategory::Requirement => 'requirements',
            ConceptCategory::Experience => 'experience',
        };
    }
}
