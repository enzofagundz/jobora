<?php

namespace App\Services;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class PublicationTriageInvalidOutput extends RuntimeException
{
    public function __construct(
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Publication triage returned invalid structured output.', previous: $previous);
    }

    /** @return array{validation_code: string, field?: string} */
    public function diagnostic(): array
    {
        $previous = $this->getPrevious();
        if ($previous instanceof JsonException) {
            return ['validation_code' => 'invalid_json'];
        }
        if (! $previous instanceof InvalidArgumentException) {
            return ['validation_code' => 'unknown'];
        }

        $message = $previous->getMessage();
        $known = [
            'Structured output envelope is invalid.' => ['validation_code' => 'invalid_envelope'],
            'Structured output has too many opportunities.' => ['validation_code' => 'invalid_envelope', 'field' => 'opportunities'],
            'Opportunity structure is invalid.' => ['validation_code' => 'invalid_opportunity_shape'],
            'Structured output application methods are invalid.' => ['validation_code' => 'invalid_field', 'field' => 'application_methods'],
            'Structured output application method is invalid.' => ['validation_code' => 'invalid_field', 'field' => 'application_methods'],
            'Structured output application method type is invalid.' => ['validation_code' => 'invalid_application_method_type', 'field' => 'type'],
            'Structured output evidence is invalid.' => ['validation_code' => 'invalid_evidence', 'field' => 'evidence'],
            'Structured output evidence item is invalid.' => ['validation_code' => 'invalid_evidence', 'field' => 'evidence'],
            'Structured output evidence quote is invalid.' => ['validation_code' => 'invalid_evidence', 'field' => 'quote'],
            'Structured output evidence fields are invalid.' => ['validation_code' => 'invalid_evidence', 'field' => 'fields'],
        ];
        if (isset($known[$message])) {
            return $known[$message];
        }

        foreach ([
            'role', 'company', 'description', 'location', 'work_mode', 'employment_type',
            'salary_min', 'salary_max', 'salary_currency', 'salary_period', 'seniority',
            'requirements', 'technologies', 'platform', 'url', 'email', 'phone',
            'instructions', 'fields', 'block_ids',
        ] as $field) {
            if ($message === "Structured output field {$field} is invalid."
                || $message === "Structured output field {$field} contains an invalid value.") {
                return ['validation_code' => 'invalid_field', 'field' => $field];
            }
        }

        return ['validation_code' => 'unknown'];
    }
}
