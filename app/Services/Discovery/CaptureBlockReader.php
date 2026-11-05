<?php

namespace App\Services\Discovery;

use DOMDocument;
use DOMElement;
use DOMXPath;

class CaptureBlockReader
{
    /**
     * @return list<array{block_id: string, kind: string, platform_id: ?string, permalink: ?string, content: string, card_block_id: ?string, detail_block_id: ?string}>
     */
    public function read(string $html, string $surface, int $version, ?int $expectedBlocks = null): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw PublicationExtractionException::parseFailed('HTML inválido.');
        }

        $xpath = new DOMXPath($document);
        $root = $this->root($xpath, $surface, $version);
        $elements = $xpath->query('//*[@data-block-id]');

        if ($elements === false) {
            throw PublicationExtractionException::parseFailed();
        }

        $blocks = [];
        $seen = [];

        foreach ($elements as $element) {
            if (! $element instanceof DOMElement || ! $this->isInside($element, $root)) {
                throw PublicationExtractionException::parseFailed('Bloco fora da raiz da captura.');
            }

            $block = $this->block($element, $surface);

            if (isset($seen[$block['block_id']])) {
                throw PublicationExtractionException::parseFailed('Bloco repetido na captura.');
            }

            $seen[$block['block_id']] = true;
            $blocks[] = $block;
        }

        if ($expectedBlocks !== null && count($blocks) !== $expectedBlocks) {
            throw PublicationExtractionException::parseFailed('Quantidade de blocos diverge da captura.');
        }

        return $blocks;
    }

    private function root(DOMXPath $xpath, string $surface, int $version): DOMElement
    {
        $roots = $xpath->query('//*[@data-capture-surface or @data-capture-version]');

        if ($roots === false || $roots->length !== 1) {
            throw PublicationExtractionException::parseFailed('Captura exige exatamente uma raiz versionada.');
        }

        $root = $roots->item(0);

        if (! $root instanceof DOMElement
            || ! $root->hasAttribute('data-capture-surface')
            || ! $root->hasAttribute('data-capture-version')) {
            throw PublicationExtractionException::parseFailed('Raiz da captura incompleta.');
        }

        if ($root->getAttribute('data-capture-surface') !== $surface) {
            throw PublicationExtractionException::parseFailed('Superfície da captura diverge do esperado.');
        }

        if ($root->getAttribute('data-capture-version') !== (string) $version) {
            throw PublicationExtractionException::parseFailed('Versão da captura diverge do esperado.');
        }

        return $root;
    }

    /**
     * @return array{block_id: string, kind: string, platform_id: ?string, permalink: ?string, content: string, card_block_id: ?string, detail_block_id: ?string}
     */
    private function block(DOMElement $element, string $surface): array
    {
        $blockId = trim($element->getAttribute('data-block-id'));
        $kind = trim($element->getAttribute('data-kind'));
        $platformId = $element->hasAttribute('data-platform-id') ? trim($element->getAttribute('data-platform-id')) : null;
        $permalink = $element->hasAttribute('data-permalink') ? trim($element->getAttribute('data-permalink')) : null;
        $nullablePostIdentity = $surface === 'linkedin_publications' && $kind === 'post';
        $nullableIndeedPlatformId = $surface === 'indeed_jobs' && in_array($kind, ['card', 'detail'], true);

        if ($blockId === '' || $kind === ''
            || (! (($nullablePostIdentity || $nullableIndeedPlatformId) && $platformId === null) && ($platformId === null || $platformId === ''))
            || (! ($nullablePostIdentity && $permalink === null) && ($permalink === null || ! $this->isIndividualUrl($permalink)))) {
            throw PublicationExtractionException::parseFailed('Bloco sem identidade ou permalink individual.');
        }

        return [
            'block_id' => $blockId,
            'kind' => $kind,
            'platform_id' => $platformId,
            'permalink' => $permalink,
            'content' => $this->content($element),
            'card_block_id' => $this->relation($element, 'data-card-block-id'),
            'detail_block_id' => $this->relation($element, 'data-detail-block-id'),
        ];
    }

    private function relation(DOMElement $element, string $attribute): ?string
    {
        $value = trim($element->getAttribute($attribute));

        return $value === '' ? null : $value;
    }

    private function content(DOMElement $element): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($element->textContent));

        return is_string($text) ? $text : '';
    }

    private function isIndividualUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false
            && isset($parts['scheme'], $parts['host'])
            && mb_strtolower($parts['scheme']) === 'https'
            && $parts['host'] !== '';
    }

    private function isInside(DOMElement $element, DOMElement $root): bool
    {
        for ($node = $element->parentNode; $node !== null; $node = $node->parentNode) {
            if ($node === $root) {
                return true;
            }

            if ($node instanceof DOMDocument) {
                return false;
            }
        }

        return false;
    }
}
