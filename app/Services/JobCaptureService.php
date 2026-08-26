<?php

namespace App\Services;

use App\Models\JobCapture;
use App\Models\User;
use Illuminate\Database\QueryException;

class JobCaptureService
{
    /**
     * Ordem de precedência — identidade definida pelo backend (fonte de verdade é
     * a tabela, unique(user_id, source, dedup_key); espelha utils/capture-identity.ts do cliente):
     * 1. external_id → 'ext:<external_id>' (trim externo; source case-insensitive)
     * 2. url → 'url:<sha1 da URL canônica>' (scheme://host/path, sem query/fragment)
     * 3. fingerprint → 'fingerprint:<sha256 do JSON composto>' com source, URL
     *    canônica, título, empresa, local, author_profile_url e descrição canônica.
     *    author_name é volátil (NULL vs "Ver perfil de ...") e nunca participa;
     *    o marcador final de expansão da UI ("… mais" ou "... mais", com whitespace
     *    opcional, somente no fim) é descartado somente no fingerprint, não na
     *    descrição persistida. search_url nunca participa. O antigo 'content:'
     *    (só descrição hash) é legado: linhas antigas ficam intactas e são
     *    reconhecidas por compatibilidade de leitura.
     */
    public function dedupKey(array $data): string
    {
        $externalId = isset($data['external_id']) ? trim((string) $data['external_id']) : '';

        if ($externalId !== '') {
            return 'ext:'.$externalId;
        }

        if (! empty($data['url']) && ($canonical = $this->normalizeUrl((string) $data['url'])) !== null) {
            return 'url:'.sha1($canonical);
        }

        return 'fingerprint:'.hash('sha256', $this->fingerprintJson($data));
    }

    /**
     * JSON com ordem fixa de chaves sobre o payload normalizado — nunca só a
     * descrição. Espelha o objeto composto de deriveCaptureIdentity().
     */
    private function fingerprintJson(array $data): string
    {
        return json_encode([
            'source' => $this->normalizeText($data['source'] ?? null),
            'url' => ! empty($data['url']) ? $this->normalizeUrl((string) $data['url']) : null,
            'title' => $this->normalizeText($data['title'] ?? null),
            'company' => $this->normalizeText($data['company'] ?? null),
            'location' => $this->normalizeText($data['location'] ?? null),
            'author_profile_url' => $this->normalizeText($data['author_profile_url'] ?? null),
            'description' => $this->normalizeDescriptionForIdentity($data['description'] ?? null),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalizeText(mixed $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($value ?? ''))) ?? '');
    }

    private function normalizeDescriptionForIdentity(mixed $value): string
    {
        $text = $this->normalizeText($value);

        // Remove apenas o marcador final de expansão da UI ("… mais" ou "... mais").
        $stripped = preg_replace('/\s*(?:…|\.\.\.)\s*mais\s*$/u', '', $text);

        return $stripped === null ? $text : trim($stripped);
    }

    /**
     * Normaliza a URL para dedup: mantém apenas scheme://host/path, descartando
     * query e fragment (parâmetros de tracking). Sem scheme+host válido → null
     * (o payload cai no fingerprint composto, igual ao cliente).
     */
    public function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '');
    }

    private function isFingerprintPayload(array $data): bool
    {
        $externalId = isset($data['external_id']) ? trim((string) $data['external_id']) : '';

        if ($externalId !== '') {
            return false;
        }

        return empty($data['url']) || $this->normalizeUrl((string) $data['url']) === null;
    }

    private function findHistoricalCompatibility(array $data, User $user): ?JobCapture
    {
        // ponytail: busca linear limitada a registros do mesmo user e source sem external_id e sem URL válida
        $candidates = $user->jobCaptures()->withTrashed()
            ->where('source', $data['source'])
            ->whereNull('external_id')
            ->where(function ($query): void {
                $query->where('dedup_key', 'like', 'content:%')
                    ->orWhere('dedup_key', 'like', 'fingerprint:%');
            })
            ->get();

        $incomingDescription = $this->normalizeDescriptionForIdentity($data['description'] ?? null);
        $incomingFingerprint = hash('sha256', $this->fingerprintJson($data));

        $matches = [];

        foreach ($candidates as $candidate) {
            // Candidatos com URL válida não participam do fingerprint histórico.
            if (! empty($candidate->url) && $this->normalizeUrl((string) $candidate->url) !== null) {
                continue;
            }

            if (str_starts_with($candidate->dedup_key, 'content:')) {
                $candidateDescription = $this->normalizeDescriptionForIdentity($candidate->description);

                if ($candidateDescription !== '' && $candidateDescription === $incomingDescription) {
                    $matches[] = $candidate;
                }

                continue;
            }

            if (str_starts_with($candidate->dedup_key, 'fingerprint:')) {
                $candidateFingerprint = hash('sha256', $this->fingerprintJson([
                    'source' => $candidate->source,
                    'url' => $candidate->url,
                    'title' => $candidate->title,
                    'company' => $candidate->company,
                    'location' => $candidate->location,
                    'author_profile_url' => $candidate->author_profile_url,
                    'description' => $candidate->description,
                ]));

                if ($candidateFingerprint === $incomingFingerprint) {
                    $matches[] = $candidate;
                }
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, function (JobCapture $a, JobCapture $b): int {
            $aPromoted = $a->job_posting_id !== null ? 0 : 1;
            $bPromoted = $b->job_posting_id !== null ? 0 : 1;

            if ($aPromoted !== $bPromoted) {
                return $aPromoted <=> $bPromoted;
            }

            return $a->id <=> $b->id;
        });

        return $matches[0];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'UNIQUE') || str_contains($message, 'unique') || str_contains($message, 'Duplicate') || str_contains($message, 'duplicate')) {
            return true;
        }

        $code = $exception->getCode();

        if ($code === '23000' || $code === 23000 || $code === '23505') {
            return true;
        }

        $previous = $exception->getPrevious();

        if ($previous) {
            $pCode = $previous->getCode();

            if ($pCode === '23000' || $pCode === 23000 || $pCode === '23505' || $pCode === 19 || $pCode === '19') {
                return true;
            }

            $pMessage = $previous->getMessage();

            if (str_contains($pMessage, 'UNIQUE') || str_contains($pMessage, 'unique') || str_contains($pMessage, 'Duplicate') || str_contains($pMessage, 'duplicate')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Armazena a captura, deduplicando por usuário. Retorna [JobCapture, bool $duplicate].
     *
     * O unique(user_id, source, dedup_key) é a garantia; a re-consulta após QueryException
     * cobre a corrida real.
     */
    public function store(array $data, User $user): array
    {
        $data['source'] = mb_strtolower(trim((string) ($data['source'] ?? '')));
        $data['external_id'] = isset($data['external_id']) ? trim((string) $data['external_id']) : null;

        if ($data['external_id'] === '') {
            $data['external_id'] = null;
        }

        $data['dedup_key'] = $this->dedupKey($data);

        $existing = $user->jobCaptures()->withTrashed()
            ->where('source', $data['source'])
            ->where('dedup_key', $data['dedup_key'])
            ->first();

        if ($existing) {
            return [$existing, true];
        }

        if ($this->isFingerprintPayload($data)) {
            $historical = $this->findHistoricalCompatibility($data, $user);

            if ($historical) {
                return [$historical, true];
            }
        }

        try {
            $capture = $user->jobCaptures()->create($data);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $capture = $user->jobCaptures()->withTrashed()
                ->where('source', $data['source'])
                ->where('dedup_key', $data['dedup_key'])
                ->first();

            if (! $capture) {
                throw $e;
            }

            return [$capture, true];
        }

        return [$capture, false];
    }
}
