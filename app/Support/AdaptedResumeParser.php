<?php

namespace App\Support;

/**
 * Converte o texto puro do currículo adaptado (adapted_content) em blocos
 * de exibição para a view de impressão. Apenas estiliza linhas — nunca
 * adiciona, remove ou reordena conteúdo (integridade preservada).
 *
 * Regras:
 * - bloco inicial = cabeçalho: 1ª linha não vazia é o nome; linhas seguintes
 *   até a 1ª linha em branco ou título completam o contato. Exceção: se a
 *   1ª linha é um título (toda maiúscula) e a 2ª é vazia ou também título,
 *   não há cabeçalho (dados antigos sem nome);
 * - título = linha não vazia, toda em maiúsculas (mb_strtoupper === linha),
 *   com ao menos uma letra maiúscula (linhas só com dígitos/pontuação não
 *   são títulos);
 * - bullet = linha iniciada por "- " ou "• "; bullets consecutivas viram
 *   um bloco "bullets";
 * - entrada = linha (não título, não bullet) contendo " | "; partes
 *   separadas por " | ";
 * - demais linhas = parágrafos.
 */
final class AdaptedResumeParser
{
    /**
     * @return list<array{
     *     type: 'header'|'heading'|'bullets'|'entry'|'paragraph',
     *     lines?: list<string>,
     *     text?: string,
     *     items?: list<string>,
     *     parts?: list<string>,
     * }>
     */
    public static function parse(string $content): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $blocks = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n && trim($lines[$i]) === '') {
            $i++;
        }

        $header = [];
        if ($i < $n && ! self::headingStartsBlock($lines, $i)) {
            $header[] = trim($lines[$i]);
            $i++;

            while ($i < $n) {
                $line = trim($lines[$i]);
                if ($line === '' || self::isHeading($line)) {
                    break;
                }
                $header[] = $line;
                $i++;
            }
        }

        if ($header !== []) {
            $blocks[] = ['type' => 'header', 'lines' => $header];
        }

        $bullets = [];

        for (; $i < $n; $i++) {
            $line = trim($lines[$i]);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^[-•]\s+/u', $line)) {
                $bullets[] = (string) preg_replace('/^[-•]\s+/u', '', $line);
                continue;
            }

            if ($bullets !== []) {
                $blocks[] = ['type' => 'bullets', 'items' => $bullets];
                $bullets = [];
            }

            if (self::isHeading($line)) {
                $blocks[] = ['type' => 'heading', 'text' => $line];
            } elseif (str_contains($line, ' | ')) {
                $blocks[] = ['type' => 'entry', 'parts' => explode(' | ', $line)];
            } else {
                $blocks[] = ['type' => 'paragraph', 'text' => $line];
            }
        }

        if ($bullets !== []) {
            $blocks[] = ['type' => 'bullets', 'items' => $bullets];
        }

        return $blocks;
    }

    private static function headingStartsBlock(array $lines, int $i): bool
    {
        if (! self::isHeading(trim($lines[$i]))) {
            return false;
        }

        $next = trim($lines[$i + 1] ?? '');

        return $next === '' || self::isHeading($next);
    }

    private static function isHeading(string $line): bool
    {
        return preg_match('/\p{Lu}/u', $line) === 1 && mb_strtoupper($line) === $line;
    }
}
