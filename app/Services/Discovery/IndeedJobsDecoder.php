<?php

namespace App\Services\Discovery;

class IndeedJobsDecoder
{
    private const SURFACE = 'indeed_jobs';

    /**
     * @param  list<array{block_id: string, kind: string, platform_id: ?string, permalink: string, content: string, card_block_id: ?string, detail_block_id: ?string}>  $blocks
     * @return list<array{external_id: ?string, url: string, content: string, content_hash: string, evidence_block_ids: list<string>}>
     */
    public function decode(array $blocks): array
    {
        $byId = [];

        foreach ($blocks as $block) {
            if (! in_array($block['kind'], ['card', 'detail'], true) || isset($byId[$block['block_id']])) {
                throw PublicationExtractionException::parseFailed('A captura do Indeed contém blocos inválidos ou repetidos.');
            }

            $byId[$block['block_id']] = $block;
        }

        foreach ($byId as $block) {
            if ($block['kind'] !== 'detail') {
                continue;
            }

            $card = $block['card_block_id'] === null ? null : ($byId[$block['card_block_id']] ?? null);

            if ($card === null
                || $card['kind'] !== 'card'
                || $card['detail_block_id'] !== $block['block_id']) {
                throw PublicationExtractionException::parseFailed('Detalhe do Indeed sem card explícito e coerente.');
            }

            $this->assertSameJob($card, $block);
        }

        $jobs = [];
        $seenExternal = [];
        $seenJobKeys = [];

        foreach ($byId as $block) {
            if ($block['kind'] !== 'card') {
                continue;
            }

            $detail = null;

            if ($block['detail_block_id'] !== null) {
                $detail = $byId[$block['detail_block_id']] ?? null;

                if ($detail === null
                    || $detail['kind'] !== 'detail'
                    || $detail['card_block_id'] !== $block['block_id']) {
                    throw PublicationExtractionException::parseFailed('Card do Indeed aponta para detalhe ausente ou incoerente.');
                }

                $this->assertSameJob($block, $detail);
            }

            $jobKey = $this->jobKey($block['permalink']);

            if (isset($seenJobKeys[$jobKey])) {
                throw PublicationExtractionException::parseFailed('Anúncio Indeed repetido na captura.');
            }

            $seenJobKeys[$jobKey] = true;
            $externalId = $block['platform_id'] ?? $detail['platform_id'] ?? null;

            if ($externalId !== null) {
                if (isset($seenExternal[$externalId])) {
                    throw PublicationExtractionException::parseFailed('ID externo Indeed repetido na captura.');
                }

                $seenExternal[$externalId] = true;
            }

            $evidence = [$block['block_id']];

            if ($detail !== null) {
                $evidence[] = $detail['block_id'];
            }

            $content = $detail['content'] ?? $block['content'];
            $jobs[] = [
                'external_id' => $externalId,
                'url' => $block['permalink'],
                'content' => $content,
                'content_hash' => hash('sha256', $content),
                'evidence_block_ids' => $evidence,
            ];
        }

        return $jobs;
    }

    public function surface(): string
    {
        return self::SURFACE;
    }

    /**
     * @param  array{block_id: string, kind: string, platform_id: ?string, permalink: string, content: string, card_block_id: ?string, detail_block_id: ?string}  $card
     * @param  array{block_id: string, kind: string, platform_id: ?string, permalink: string, content: string, card_block_id: ?string, detail_block_id: ?string}  $detail
     */
    private function assertSameJob(array $card, array $detail): void
    {
        if (($card['platform_id'] !== null && $detail['platform_id'] !== null && $card['platform_id'] !== $detail['platform_id'])
            || $this->jobKey($card['permalink']) !== $this->jobKey($detail['permalink'])) {
            throw PublicationExtractionException::parseFailed('Card e detalhe do Indeed identificam anúncios diferentes.');
        }
    }

    private function jobKey(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'], $parts['path'], $parts['query'])) {
            throw PublicationExtractionException::parseFailed('Vaga sem URL individual do Indeed com jk.');
        }

        $host = mb_strtolower($parts['host']);
        parse_str($parts['query'], $query);

        if (mb_strtolower($parts['scheme']) !== 'https'
            || ! in_array($host, ['br.indeed.com', 'indeed.com', 'www.indeed.com'], true)
            || rtrim($parts['path'], '/') !== '/viewjob'
            || ! is_string($query['jk'] ?? null)
            || preg_match('/^[a-zA-Z0-9_-]{1,255}$/', $query['jk']) !== 1) {
            throw PublicationExtractionException::parseFailed('Vaga sem URL individual do Indeed com jk.');
        }

        return $query['jk'];
    }
}
