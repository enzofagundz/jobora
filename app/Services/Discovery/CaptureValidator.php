<?php

namespace App\Services\Discovery;

use App\Enums\DiscoverySourceSurface;
use App\Enums\SearchPlatform;
use InvalidArgumentException;

class CaptureValidator
{
    private const SOURCES = ['linkedin', 'indeed'];

    private const SURFACES = ['linkedin_publications', 'linkedin_jobs', 'indeed_jobs'];

    private const REASONS = ['cancelled', 'navigation_interrupted', 'timeout', 'no_content'];

    public function validate(array $payload): void
    {
        $this->assertKeys($payload, ['format', 'version', 'capture', 'blocks'], 'envelope');

        if (($payload['format'] ?? null) !== 'jobora.capture-envelope' || ($payload['version'] ?? null) !== 1) {
            throw new CaptureValidationException('Formato ou versão do envelope inválidos.');
        }

        $capture = $payload['capture'] ?? null;

        if (! is_array($capture)) {
            throw new CaptureValidationException('Capture ausente.');
        }

        $this->assertKeys($capture, [
            'idempotency_key', 'execution_id', 'source', 'surface', 'initial_url',
            'final_url', 'captured_at', 'coverage', 'html',
        ], 'capture');

        $key = $capture['idempotency_key'] ?? null;

        if (! is_string($key) || $key === '' || mb_strlen($key) > 200) {
            throw new CaptureValidationException('idempotency_key inválida.');
        }

        if (! in_array($capture['source'] ?? null, self::SOURCES, true) || ! in_array($capture['surface'] ?? null, self::SURFACES, true)) {
            throw new CaptureValidationException('source ou surface inválidos.');
        }

        $surface = DiscoverySourceSurface::from($capture['surface']);
        $platform = SearchPlatform::from($capture['source']);

        if ($surface->platform() !== $platform) {
            throw new CaptureValidationException('surface não pertence ao source.');
        }

        $execution = $capture['execution_id'] ?? null;

        if ($execution !== null && (! is_string($execution) || $execution === '' || mb_strlen($execution) > 200)) {
            throw new CaptureValidationException('execution_id deve ser string de 1 a 200 caracteres ou null.');
        }

        try {
            app(DiscoverySourceUrl::class)->validate($platform, $surface, (string) ($capture['initial_url'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            throw new CaptureValidationException($exception->getMessage(), 0, $exception);
        }

        $final = $capture['final_url'] ?? null;

        if (! is_string($final) || ! $this->isHttpsUrl($final)) {
            throw new CaptureValidationException('final_url inválida.');
        }

        if (! is_string($capture['captured_at'] ?? null) || strtotime((string) $capture['captured_at']) === false) {
            throw new CaptureValidationException('captured_at inválida.');
        }

        $html = $capture['html'] ?? null;

        if (! is_string($html) || $html === '') {
            throw new CaptureValidationException('html obrigatório.');
        }

        if (strlen($html) > (int) config('capture.ingestion.html_max_bytes', 10_485_760)) {
            throw new CaptureTooLargeException('HTML excede o limite configurado.');
        }

        $this->validateBlocks($payload['blocks'] ?? null, $capture['surface']);
        $this->validateCoverage($capture['coverage'] ?? null, count($payload['blocks'] ?? []));
    }

    private function validateCoverage(mixed $coverage, int $blockCount): void
    {
        if (! is_array($coverage)) {
            throw new CaptureValidationException('coverage ausente.');
        }

        $this->assertKeys($coverage, ['status', 'reason', 'blocks_expected', 'blocks_captured'], 'coverage');

        $status = $coverage['status'] ?? null;

        if (! in_array($status, ['complete', 'partial'], true)) {
            throw new CaptureValidationException('coverage.status inválido.');
        }

        $reason = $coverage['reason'] ?? null;

        if ($status === 'complete' && $reason !== null) {
            throw new CaptureValidationException('coverage.reason deve ser null quando completa.');
        }

        if ($status === 'partial' && ! in_array($reason, self::REASONS, true)) {
            throw new CaptureValidationException('coverage.reason inválido para parcial.');
        }

        $expected = $coverage['blocks_expected'] ?? null;

        if ($expected !== null && (! is_int($expected) || $expected < 0 || $expected > (int) config('capture.ingestion.blocks_max', 1000))) {
            throw new CaptureValidationException('blocks_expected inválido.');
        }

        $captured = $coverage['blocks_captured'] ?? null;

        if (! is_int($captured) || $captured < 0) {
            throw new CaptureValidationException('blocks_captured inválido.');
        }

        if ($captured !== $blockCount) {
            throw new CaptureValidationException('coverage.blocks_captured diverge da quantidade de blocos.');
        }

        if ($status === 'complete' && $expected !== null && $expected !== $captured) {
            throw new CaptureValidationException('Captura completa exige blocks_expected igual a blocks_captured.');
        }

        if ($status === 'partial' && $expected !== null && $expected < $captured) {
            throw new CaptureValidationException('Captura parcial não pode ter blocks_expected menor que blocks_captured.');
        }
    }

    private function validateBlocks(mixed $blocks, string $surface): void
    {
        if (! is_array($blocks) || array_is_list($blocks) === false) {
            throw new CaptureValidationException('blocks deve ser lista.');
        }

        if (count($blocks) > (int) config('capture.ingestion.blocks_max', 1000)) {
            throw new CaptureTooLargeException('Quantidade de blocos excede o limite.');
        }

        $ids = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                throw new CaptureValidationException('Bloco inválido.');
            }

            $this->assertKeys($block, ['block_id', 'kind', 'platform_id', 'permalink', 'card_block_id', 'detail_block_id'], 'block');

            $id = $block['block_id'] ?? null;

            if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) || isset($ids[$id])) {
                throw new CaptureValidationException('block_id inválido ou duplicado.');
            }

            $ids[$id] = $block;

            if (! in_array($block['kind'] ?? null, ['post', 'card', 'detail'], true)) {
                throw new CaptureValidationException('kind inválido.');
            }

            $nullablePostIdentity = $surface === 'linkedin_publications' && $block['kind'] === 'post';
            $nullableIndeedPlatformId = $surface === 'indeed_jobs' && in_array($block['kind'], ['card', 'detail'], true);
            $platformId = $block['platform_id'];
            $permalink = $block['permalink'];

            if (! (($nullablePostIdentity || $nullableIndeedPlatformId) && $platformId === null)
                && (! is_string($platformId) || trim($platformId) === '' || mb_strlen($platformId) > 255)) {
                throw new CaptureValidationException('platform_id inválido.');
            }

            if (! ($nullablePostIdentity && $permalink === null)
                && (! is_string($permalink) || trim($permalink) === '' || ! $this->isHttpsUrl($permalink) || mb_strlen($permalink) > 2000)) {
                throw new CaptureValidationException('permalink inválido.');
            }
        }

        foreach ($blocks as $block) {
            $kind = $block['kind'];
            $card = $block['card_block_id'] ?? null;
            $detail = $block['detail_block_id'] ?? null;

            if ($kind === 'post' && ($card !== null || $detail !== null)) {
                throw new CaptureValidationException('Bloco post não pode ter relações.');
            }

            if ($kind === 'card' && $card !== null) {
                throw new CaptureValidationException('Bloco card não pode ter card_block_id.');
            }

            if ($kind === 'detail' && ! is_string($card)) {
                throw new CaptureValidationException('Bloco detail exige card_block_id.');
            }

            if ($detail !== null && (! is_string($detail) || ($ids[$detail]['kind'] ?? null) !== 'detail')) {
                throw new CaptureValidationException('detail_block_id não referencia um detail.');
            }

            if (is_string($card) && ($ids[$card]['kind'] ?? null) !== 'card') {
                throw new CaptureValidationException('card_block_id não referencia um card.');
            }
        }
    }

    private function assertKeys(array $value, array $allowed, string $context): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        $missing = array_diff($allowed, array_keys($value));

        if ($unknown !== []) {
            throw new CaptureValidationException("Propriedade não permitida em {$context}.");
        }

        foreach ($missing as $key) {
            if (! array_key_exists($key, $value)) {
                throw new CaptureValidationException("Propriedade obrigatória ausente em {$context}: {$key}.");
            }
        }
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false && isset($parts['scheme'], $parts['host']) && mb_strtolower($parts['scheme']) === 'https';
    }
}
