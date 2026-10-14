<?php

namespace App\Services;

use App\Enums\ResumeLanguage;
use App\Models\Resume;
use App\Models\User;

class ResumeService
{
    /**
     * Save the resume for a given language. One row per (user, language) is
     * guaranteed. The canonical resume is the pt-BR version.
     */
    public function save(User $user, string $content, ResumeLanguage $language): Resume
    {
        return $this->persist($user, $language, $content);
    }

    /**
     * Persist the EN version produced by the AI translation, recording the
     * fingerprint of the PT-BR it was derived from so drift can be detected.
     */
    public function saveTranslation(User $user, string $content, string $sourceFingerprint): Resume
    {
        return $this->persist($user, ResumeLanguage::En, $content, $sourceFingerprint);
    }

    /**
     * Caminho único de gravação de um currículo: conteúdo e, quando houver,
     * fingerprint de origem vão numa só escrita.
     */
    private function persist(User $user, ResumeLanguage $language, string $content, ?string $sourceFingerprint = null): Resume
    {
        $resume = $user->resumes()
            ->where('language', $language->value)
            ->first() ?? $user->resumes()->make(['language' => $language]);

        $resume->content = $content;

        if ($sourceFingerprint !== null) {
            $resume->source_fingerprint = $sourceFingerprint;
        }

        $resume->save();

        return $resume;
    }

    public function current(User $user, ResumeLanguage $language): ?Resume
    {
        return $user->resumes()
            ->where('language', $language->value)
            ->first();
    }

    public function content(User $user, ResumeLanguage $language): ?string
    {
        return $this->current($user, $language)?->content;
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
