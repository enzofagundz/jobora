<?php

namespace App\DTO;

use App\Services\ProfileImportException;
use JsonException;
use stdClass;

final class ProfileImportOutput
{
    /**
     * @param  array<string, string>  $blocks
     * @return array{candidate: array{experiences: array, education: array, competencies: list<string>}, evidence: list<array{path: string, block_id: string, quote: string}>}
     */
    public static function parse(string $text, array $blocks): array
    {
        if (strlen($text) > 1000000) {
            throw new ProfileImportException;
        }

        try {
            $output = json_decode($text, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProfileImportException;
        }

        self::object($output, ['candidate', 'evidence']);
        self::object($output->candidate, ['experiences', 'education', 'competencies']);
        $facts = [];
        foreach (['experiences' => ['company' => 255, 'role' => 255, 'period' => 100, 'description' => 5000], 'education' => ['institution' => 255, 'qualification' => 255, 'period' => 100]] as $section => $fields) {
            self::items($output->candidate->{$section}, 100);
            foreach ($output->candidate->{$section} as $index => $item) {
                self::object($item, array_keys($fields));
                foreach ($fields as $field => $limit) {
                    $value = $item->{$field};
                    if ($value !== null) {
                        self::text($value, $limit);
                        $facts["{$section}.{$index}.{$field}"] = $value;
                    }
                }
            }
        }
        self::items($output->candidate->competencies, 100);
        foreach ($output->candidate->competencies as $index => $value) {
            self::text($value, 100);
            $facts["competencies.{$index}"] = $value;
        }

        self::items($output->evidence, 1000);
        $supported = [];
        foreach ($output->evidence as $evidence) {
            self::object($evidence, ['path', 'block_id', 'quote']);
            self::text($evidence->path, 100);
            self::text($evidence->block_id, 100);
            self::text($evidence->quote, 5000);
            if (! isset($facts[$evidence->path], $blocks[$evidence->block_id])
                || ! str_contains($blocks[$evidence->block_id], $evidence->quote)) {
                throw new ProfileImportException;
            }
            $pattern = '/(?<![\p{L}\p{M}\p{N}_+#.])'.preg_quote($facts[$evidence->path], '/').'(?![\p{L}\p{M}\p{N}_+#]|\.[\p{L}\p{M}\p{N}_])/u';
            if (preg_match($pattern, $evidence->quote) !== 1 || preg_match($pattern, $blocks[$evidence->block_id]) !== 1) {
                throw new ProfileImportException;
            }
            $supported[$evidence->path] = true;
        }
        if (array_diff_key($facts, $supported) !== []) {
            throw new ProfileImportException;
        }

        return json_decode($text, true, 32, JSON_THROW_ON_ERROR);
    }

    private static function object(mixed $value, array $keys): void
    {
        if (! $value instanceof stdClass || count(get_object_vars($value)) !== count($keys)
            || array_diff(array_keys(get_object_vars($value)), $keys) !== []) {
            throw new ProfileImportException;
        }
    }

    private static function items(mixed $value, int $limit): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $limit) {
            throw new ProfileImportException;
        }
    }

    private static function text(mixed $value, int $limit): void
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $limit) {
            throw new ProfileImportException;
        }
    }
}
