<?php

namespace App\DTO;

use App\Services\MatchingException;
use App\Services\MatchingService;
use JsonException;
use stdClass;

final class MatchingOutput
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/matching-v1.schema.json')), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array{profile_id: int, confirmed_version: int, opportunity_id: int, opportunity_version: int, profile_hash: string, opportunity_hash: string} */
    public static function basis(array $frozen): array
    {
        return [
            'profile_id' => $frozen['profile']['id'], 'confirmed_version' => $frozen['profile']['confirmed_version'],
            'opportunity_id' => $frozen['opportunity']['id'], 'opportunity_version' => $frozen['opportunity']['opportunity_version'],
            'profile_hash' => $frozen['profile']['hash'], 'opportunity_hash' => $frozen['opportunity']['hash'],
        ];
    }

    /** @return array{schema_version: string, criteria_version: string, input_fingerprint: string, basis: array, criteria: array, summary: array} */
    public static function parse(string $text, array $frozen, string $fingerprint): array
    {
        if (strlen($text) > 2000000) {
            throw new MatchingException;
        }
        try {
            $output = json_decode($text, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MatchingException;
        }
        $schema = self::schema();
        self::object($output, $schema['required']);
        self::object($output->basis, $schema['$defs']['basis']['required']);
        self::object($output->summary, $schema['$defs']['summary']['required']);
        if ($output->schema_version !== $frozen['schema_version'] || $output->schema_version !== 'matching-result-v1'
            || $output->criteria_version !== 'matching-v1' || $output->criteria_version !== $frozen['criteria_version']
            || $output->input_fingerprint !== $fingerprint
            || MatchingService::hash((array) $output->basis) !== MatchingService::hash(self::basis($frozen))) {
            throw new MatchingException;
        }
        self::items($output->criteria, 405);
        $inventory = MatchingService::inventory($frozen);
        $anchors = array_column($frozen['anchors'], null, 'id');
        $counts = ['met' => 0, 'unsupported' => 0, 'incompatible' => 0, 'uncertain' => 0];
        $seen = [];
        foreach ($output->criteria as $criterion) {
            self::object($criterion, $schema['$defs']['criterion']['required']);
            self::text($criterion->id, 100);
            self::text($criterion->kind, 100);
            self::text($criterion->status, 100);
            self::text($criterion->reason_code, 100);
            self::text($criterion->explanation, 2000);
            if (isset($seen[$criterion->id]) || ($inventory[$criterion->id] ?? null) !== $criterion->kind
                || ! in_array($criterion->status, $schema['$defs']['criterion']['properties']['status']['enum'], true)) {
                throw new MatchingException;
            }
            $seen[$criterion->id] = true;
            $rule = collect($schema['$defs']['criterion']['allOf'])->first(fn (array $rule): bool => $rule['if']['properties']['status']['const'] === $criterion->status)['then']['properties']['reason_code'];
            if (! in_array($criterion->reason_code, $rule['enum'] ?? [$rule['const']], true)) {
                throw new MatchingException;
            }
            self::items($criterion->evidence, 100);
            self::items($criterion->missing_paths, 100);
            $sources = [];
            foreach ($criterion->evidence as $evidence) {
                self::object($evidence, $schema['$defs']['evidence']['required']);
                self::text($evidence->anchor_id, 512);
                self::text($evidence->quote, 5000);
                $anchor = $anchors[$evidence->anchor_id] ?? null;
                if ($anchor === null || ! self::literal($evidence->quote, $anchor['text'])) {
                    throw new MatchingException;
                }
                $sources[] = $anchor['source'];
                if ($criterion->status === 'met' && $anchor['source'] === 'profile' && in_array($criterion->kind, ['technology', 'requirement'], true)) {
                    $index = (int) explode(':', $criterion->id)[1];
                    $field = $criterion->kind === 'technology' ? 'technologies' : 'requirements';
                    $target = $frozen['opportunity']['snapshot'][$field][$index];
                    if (preg_match('/^[\p{L}\p{N}.][\p{L}\p{M}\p{N}_+#.]*$/u', $target)
                        && mb_stripos($evidence->quote, $target, 0, 'UTF-8') !== false && ! self::literal($target, $evidence->quote)) {
                        throw new MatchingException;
                    }
                }
            }
            if (in_array($criterion->status, ['met', 'incompatible'], true)
                && (! in_array('profile', $sources, true) || array_intersect(['opportunity', 'publication'], $sources) === [])) {
                throw new MatchingException;
            }
            if ($criterion->status === 'uncertain' && $criterion->evidence === []) {
                throw new MatchingException;
            }
            if (($criterion->status === 'unsupported') !== ($criterion->missing_paths !== [])) {
                throw new MatchingException;
            }
            if (count(array_unique($criterion->missing_paths, SORT_REGULAR)) !== count($criterion->missing_paths)) {
                throw new MatchingException;
            }
            foreach ($criterion->missing_paths as $path) {
                self::text($path, 512);
                if (! preg_match('/^(profile|opportunity):(\/(?:[^~]|~[01])*)$/u', $path, $parts)
                    || ! self::pointerExists($frozen[$parts[1]]['snapshot'], $parts[2])) {
                    throw new MatchingException;
                }
                if (($criterion->reason_code === 'profile_information_missing' && $parts[1] !== 'profile')
                    || ($criterion->reason_code === 'opportunity_information_missing' && $parts[1] !== 'opportunity')) {
                    throw new MatchingException;
                }
            }
            $counts[$criterion->status]++;
        }
        foreach ($counts as $status => $count) {
            if ($output->summary->{$status} !== $count) {
                throw new MatchingException;
            }
        }
        if (array_diff_key($inventory, $seen) !== []) {
            throw new MatchingException;
        }

        return json_decode($text, true, 64, JSON_THROW_ON_ERROR);
    }

    private static function literal(string $value, string $text): bool
    {
        $pattern = '/(?<![\p{L}\p{M}\p{N}_+#.])'.preg_quote($value, '/').'(?![\p{L}\p{M}\p{N}_+#]|\.[\p{L}\p{M}\p{N}_])/u';

        return preg_match($pattern, $text) === 1;
    }

    private static function pointerExists(array $snapshot, string $pointer): bool
    {
        $value = $snapshot;
        foreach (explode('/', substr($pointer, 1)) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $part);
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return false;
            }
            $value = $value[$part];
        }

        return true;
    }

    private static function object(mixed $value, array $keys): void
    {
        if (! $value instanceof stdClass || count(get_object_vars($value)) !== count($keys) || array_diff(array_keys(get_object_vars($value)), $keys) !== []) {
            throw new MatchingException;
        }
    }

    private static function items(mixed $value, int $max): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $max) {
            throw new MatchingException;
        }
    }

    private static function text(mixed $value, int $max): void
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
            throw new MatchingException;
        }
    }
}
