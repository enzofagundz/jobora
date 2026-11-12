<?php

namespace App\Services\Discovery;

class LinkedInJobsDecoder
{
    private const SURFACE = 'linkedin_jobs';

    /**
     * @param  list<array{block_id: string, kind: string, platform_id: string, permalink: string, content: string, card_block_id: ?string, detail_block_id: ?string}>  $blocks
     * @return list<array{external_id: string, url: string, content: string, content_hash: string, evidence_block_ids: list<string>}>
     */
    public function decode(array $blocks): array
    {
        $byId = [];

        foreach ($blocks as $block) {
            if (! in_array($block['kind'], ['card', 'detail'], true)) {
                throw PublicationExtractionException::parseFailed('A captura de vagas contém um bloco que não é card nem detalhe.');
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
                || $card['detail_block_id'] !== $block['block_id']
                || $card['platform_id'] !== $block['platform_id']) {
                throw PublicationExtractionException::parseFailed('Detalhe sem card explícito e coerente.');
            }
        }

        $jobs = [];
        $seenExternal = [];
        $seenUrl = [];

        foreach ($byId as $block) {
            if ($block['kind'] !== 'card') {
                continue;
            }

            $detail = null;

            if ($block['detail_block_id'] !== null) {
                $detail = $byId[$block['detail_block_id']] ?? null;

                if ($detail === null
                    || $detail['kind'] !== 'detail'
                    || $detail['card_block_id'] !== $block['block_id']
                    || $detail['platform_id'] !== $block['platform_id']) {
                    throw PublicationExtractionException::parseFailed('Card aponta para detalhe ausente ou incoerente.');
                }
            }

            $externalId = $block['platform_id'];
            $url = $block['permalink'];

            if (! $this->isIndividualJobUrl($url)) {
                throw PublicationExtractionException::parseFailed('Vaga sem URL individual /jobs/view/{id}.');
            }

            if (isset($seenExternal[$externalId]) || isset($seenUrl[$url])) {
                throw PublicationExtractionException::parseFailed('Vaga repetida na captura.');
            }

            $seenExternal[$externalId] = true;
            $seenUrl[$url] = true;

            $content = $detail['content'] ?? $block['content'];
            $evidence = [$block['block_id']];

            if ($detail !== null) {
                $evidence[] = $detail['block_id'];
            }

            $jobs[] = [
                'external_id' => $externalId,
                'url' => $url,
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

    private function isIndividualJobUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return false;
        }

        $host = mb_strtolower($parts['host']);

        return mb_strtolower($parts['scheme']) === 'https'
            && ($host === 'linkedin.com' || str_ends_with($host, '.linkedin.com'))
            && preg_match('#^/jobs/view/[^/]+/?$#', $parts['path']) === 1;
    }
}
