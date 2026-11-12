<?php

namespace App\Services\Discovery;

class LinkedInPublicationsDecoder
{
    private const SURFACE = 'linkedin_publications';

    /**
     * @param  list<array{block_id: string, kind: string, platform_id: ?string, permalink: ?string, content: string}>  $blocks
     * @return list<array{external_id: ?string, url: ?string, content: string, content_hash: string, evidence_block_ids: list<string>}>
     */
    public function decode(array $blocks): array
    {
        $publications = [];
        $seenExternal = [];
        $seenUrl = [];

        foreach ($blocks as $block) {
            if ($block['kind'] !== 'post') {
                throw PublicationExtractionException::parseFailed('A captura de publicações contém um bloco que não é post.');
            }

            $externalId = $block['platform_id'];
            $url = $block['permalink'];

            if ($url !== null && ! $this->isIndividualLinkedInUrl($url)) {
                throw PublicationExtractionException::parseFailed('Publicação sem URL individual do LinkedIn.');
            }

            if (($externalId !== null && isset($seenExternal[$externalId])) || ($url !== null && isset($seenUrl[$url]))) {
                throw PublicationExtractionException::parseFailed('Publicação repetida na captura.');
            }

            if ($externalId !== null) {
                $seenExternal[$externalId] = true;
            }

            if ($url !== null) {
                $seenUrl[$url] = true;
            }

            $publications[] = [
                'external_id' => $externalId,
                'url' => $url,
                'content' => $block['content'],
                'content_hash' => hash('sha256', $block['content']),
                'evidence_block_ids' => [$block['block_id']],
            ];
        }

        return $publications;
    }

    public function surface(): string
    {
        return self::SURFACE;
    }

    private function isIndividualLinkedInUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return false;
        }

        $host = mb_strtolower($parts['host']);

        return mb_strtolower($parts['scheme']) === 'https'
            && ($host === 'linkedin.com' || str_ends_with($host, '.linkedin.com'))
            && ! str_contains($parts['path'], '/search/')
            && $parts['path'] !== '/';
    }
}
