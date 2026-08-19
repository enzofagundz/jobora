<?php

namespace App\Services;

use App\Models\JobCapture;
use Illuminate\Database\QueryException;

class JobCaptureService
{
    /**
     * Calcula a chave de deduplicação da captura.
     *
     * Ordem de precedência (fonte de verdade é a tabela, unique(source, dedup_key)):
     * 1. external_id → 'ext:<external_id>'
     * 2. url → 'url:<sha1 da URL normalizada>' (sem query/fragment — o tracking
     *    do LinkedIn usa query com lipi/trk que varia por clique)
     * 3. descrição → 'content:<sha256 da descrição normalizada>'
     */
    public function dedupKey(array $data): string
    {
        if (! empty($data['external_id'])) {
            return 'ext:'.$data['external_id'];
        }

        if (! empty($data['url'])) {
            return 'url:'.sha1($this->normalizeUrl($data['url']));
        }

        $description = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($data['description'] ?? ''))));

        return 'content:'.hash('sha256', $description);
    }

    /**
     * Normaliza a URL para dedup: mantém apenas scheme://host/path, descartando
     * query e fragment (parâmetros de tracking). URLs sem host válido voltam cruas.
     */
    public function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! isset($parts['scheme'], $parts['host'])) {
            return trim($url);
        }

        return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '');
    }

    /**
     * Armazena a captura, deduplicando. Retorna [JobCapture, bool $duplicate].
     *
     * O unique(source, dedup_key) é a garantia; a re-consulta após QueryException
     * cobre a corrida teórica (single-user).
     */
    public function store(array $data): array
    {
        $data['dedup_key'] = $this->dedupKey($data);

        $existing = JobCapture::query()
            ->where('source', $data['source'])
            ->where('dedup_key', $data['dedup_key'])
            ->first();

        if ($existing) {
            return [$existing, true];
        }

        try {
            $capture = JobCapture::create($data);
        } catch (QueryException) {
            $capture = JobCapture::query()
                ->where('source', $data['source'])
                ->where('dedup_key', $data['dedup_key'])
                ->firstOrFail();

            return [$capture, true];
        }

        return [$capture, false];
    }
}
