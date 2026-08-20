<?php

namespace App\Services;

use App\Models\Resume;

class ResumeService
{
    /**
     * Save the current resume. A single row is guaranteed: saving always
     * updates the existing row (new submissions replace the previous text).
     */
    public function save(string $content): Resume
    {
        $resume = Resume::query()->first() ?? new Resume;

        $resume->content = $content;
        $resume->save();

        return $resume;
    }

    public function current(): ?Resume
    {
        return Resume::query()->first();
    }

    public function content(): ?string
    {
        return $this->current()?->content;
    }

    /**
     * Converte o conteúdo legado (HTML do RichEditor) para texto puro.
     * Idempotente: texto sem '<' retorna como está.
     */
    public static function plainText(?string $content): string
    {
        if (blank($content)) {
            return '';
        }

        if (! str_contains($content, '<')) {
            return trim($content);
        }

        $text = preg_replace('/<(br|li|p|h[1-6]|ul|ol|div|section|tr)\b[^>]*>/i', "\n", $content) ?? $content;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
