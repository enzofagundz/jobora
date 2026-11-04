<?php

namespace App\Services\Discovery;

use DOMDocument;
use DOMElement;
use DOMXPath;

class CaptureSanitizer
{
    private const REMOVED_TAGS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'link', 'meta', 'base',
        'svg', 'math', 'template', 'noscript',
    ];

    private const SENSITIVE_ATTRIBUTES = [
        'password', 'passwd', 'secret', 'token', 'csrf', 'authorization', 'auth',
        'cookie', 'session', 'api_key', 'apikey', 'access_token', 'refresh_token',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function sanitize(string $html, array $blocks, string $surface, int $version): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new CaptureValidationException('HTML inválido.');
        }

        $xpath = new DOMXPath($document);

        foreach (self::REMOVED_TAGS as $tag) {
            foreach (iterator_to_array($xpath->query('//'.$tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach ($xpath->query('//*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = mb_strtolower($attribute->name);

                if ($this->isUnsafeAttribute($name) || $this->isUnsafeUrlAttribute($name, $attribute->value)) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }

        $root = $this->assertRoot($xpath, $surface, $version);
        $this->assertBlocksPresent($xpath, $blocks, $root);

        return $document->saveHTML() ?: '';
    }

    private function isUnsafeAttribute(string $name): bool
    {
        if (str_starts_with($name, 'on') || in_array($name, ['style', 'srcdoc', 'srcset'], true)) {
            return true;
        }

        foreach (self::SENSITIVE_ATTRIBUTES as $sensitive) {
            if (str_contains($name, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function isUnsafeUrlAttribute(string $name, string $value): bool
    {
        if (! in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'], true)) {
            return false;
        }

        $scheme = mb_strtolower((string) parse_url(trim($value), PHP_URL_SCHEME));

        return in_array($scheme, ['javascript', 'data', 'vbscript', 'file'], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function assertRoot(DOMXPath $xpath, string $surface, int $version): DOMElement
    {
        $roots = $xpath->query('//*[@data-capture-surface or @data-capture-version]');

        if ($roots === false || $roots->length !== 1) {
            throw new CaptureValidationException('HTML exige exatamente uma raiz com data-capture-surface e data-capture-version.');
        }

        $root = $roots->item(0);

        if (! $root instanceof DOMElement
            || ! $root->hasAttribute('data-capture-surface')
            || ! $root->hasAttribute('data-capture-version')) {
            throw new CaptureValidationException('HTML exige exatamente uma raiz com data-capture-surface e data-capture-version.');
        }

        if ($root->getAttribute('data-capture-surface') !== $surface) {
            throw new CaptureValidationException('data-capture-surface do HTML diverge do envelope.');
        }

        if ($root->getAttribute('data-capture-version') !== (string) $version) {
            throw new CaptureValidationException('data-capture-version do HTML diverge do envelope.');
        }

        return $root;
    }

    private function assertBlocksPresent(DOMXPath $xpath, array $blocks, DOMElement $root): void
    {
        $elements = $xpath->query('//*[@data-block-id]');

        if ($elements === false || $elements->length !== count($blocks)) {
            throw new CaptureValidationException('HTML não contém exatamente um elemento por bloco.');
        }

        $found = [];

        foreach ($elements as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            if (! $this->isInside($element, $root)) {
                throw new CaptureValidationException('Bloco do HTML está fora da raiz da captura.');
            }

            $id = $element->getAttribute('data-block-id');

            if (isset($found[$id])) {
                throw new CaptureValidationException('Bloco repetido no HTML.');
            }

            $found[$id] = $element;
        }

        foreach ($blocks as $block) {
            $element = $found[$block['block_id']] ?? null;

            if ($element === null
                || $element->getAttribute('data-kind') !== $block['kind']
                || $element->getAttribute('data-platform-id') !== $block['platform_id']
                || $element->getAttribute('data-permalink') !== $block['permalink']) {
                throw new CaptureValidationException('Bloco do HTML diverge do envelope.');
            }

            $this->assertBlockRelations($element, $block);
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function assertBlockRelations(DOMElement $element, array $block): void
    {
        $kind = $block['kind'];
        $hasCard = $element->hasAttribute('data-card-block-id');
        $hasDetail = $element->hasAttribute('data-detail-block-id');

        if ($kind === 'post') {
            if ($hasCard || $hasDetail) {
                throw new CaptureValidationException('Bloco post não pode ter relação no HTML.');
            }

            return;
        }

        if ($kind === 'card') {
            if ($hasCard) {
                throw new CaptureValidationException('Bloco card não pode ter data-card-block-id no HTML.');
            }

            $expectedDetail = $block['detail_block_id'];

            if ($expectedDetail === null && $hasDetail) {
                throw new CaptureValidationException('data-detail-block-id do HTML diverge do envelope.');
            }

            if ($expectedDetail !== null && (! $hasDetail || $element->getAttribute('data-detail-block-id') !== $expectedDetail)) {
                throw new CaptureValidationException('data-detail-block-id do HTML diverge do envelope.');
            }

            return;
        }

        if ($hasDetail) {
            throw new CaptureValidationException('Bloco detail não pode ter data-detail-block-id no HTML.');
        }

        if (! $hasCard || $element->getAttribute('data-card-block-id') !== $block['card_block_id']) {
            throw new CaptureValidationException('data-card-block-id do HTML diverge do envelope.');
        }
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
