<?php

namespace App\Services\Discovery;

use App\Models\Publication;
use App\Models\PublicationIdentityConflict;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class PublicationIdentityResolver
{
    private const TRACKING_PARAMETERS = [
        'trk',
        'trkinfo',
        'trackingid',
        'refid',
        'ref',
        'originalSubdomain',
        'lipi',
        'midtoken',
        'midsig',
        'trkemail',
        'eid',
        'gclid',
        'fbclid',
        'msclkid',
        'yclid',
        'igshid',
        'from',
        'vjs',
        'tk',
        'advn',
        'xkcb',
        'spa',
        'fccid',
    ];

    public function resolve(User $user, string $source, ?string $externalId, ?string $url, ?string $fingerprint = null): Publication
    {
        $source = mb_strtolower(trim($source));
        $externalId = $this->clean($externalId);
        $url = $this->clean($url);
        $canonicalUrl = $url !== null ? $this->canonicalUrl($url) : null;
        $canonicalUrlHash = $canonicalUrl !== null ? hash('sha256', $canonicalUrl) : null;
        $fingerprint = $externalId === null && $canonicalUrl === null ? $this->fingerprint($fingerprint) : null;

        if ($externalId === null && $canonicalUrl === null && $fingerprint === null) {
            throw new InvalidArgumentException('Publicação exige external_id, URL canônica válida ou fingerprint.');
        }

        $lastFailure = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $publication = $this->find($user, $source, $externalId, $url, $canonicalUrl, $canonicalUrlHash, $fingerprint);

                if ($publication !== null) {
                    return $publication;
                }

                return DB::transaction(fn (): Publication => Publication::create([
                    'user_id' => $user->id,
                    'source' => $source,
                    'external_id' => $externalId,
                    'url' => $url,
                    'canonical_url' => $canonicalUrl,
                    'canonical_url_hash' => $canonicalUrlHash,
                    'fingerprint' => $fingerprint,
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                $lastFailure = $exception;
            }
        }

        throw new RuntimeException('Não foi possível resolver a identidade da publicação após 3 tentativas.', 0, $lastFailure);
    }

    public function canonicalUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = mb_strtolower($parts['scheme']);
        $host = mb_strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        if ($port !== null && ! $defaultPort) {
            $host .= ':'.$port;
        }

        $path = $parts['path'] ?? '';

        if ($path !== '' && $path !== '/') {
            $path = rtrim($path, '/');
        }

        $query = $this->canonicalQuery($parts['query'] ?? null);

        return $scheme.'://'.$host.$path.($query !== '' ? '?'.$query : '');
    }

    private function canonicalQuery(?string $query): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $key = mb_strtolower(urldecode(explode('=', $pair, 2)[0]));

            if (str_starts_with($key, 'utm_') || in_array($key, self::TRACKING_PARAMETERS, true)) {
                continue;
            }

            $pairs[] = $pair;
        }

        sort($pairs);

        return implode('&', $pairs);
    }

    private function find(User $user, string $source, ?string $externalId, ?string $url, ?string $canonicalUrl, ?string $canonicalUrlHash, ?string $fingerprint): ?Publication
    {
        $byExternal = $externalId === null ? null : Publication::query()
            ->where('user_id', $user->id)
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->first();

        $byUrl = $canonicalUrlHash === null ? null : Publication::query()
            ->where('user_id', $user->id)
            ->where('source', $source)
            ->where('canonical_url_hash', $canonicalUrlHash)
            ->first();

        if ($byExternal !== null && $byUrl !== null && ! $byExternal->is($byUrl)) {
            $this->recordConflict($user, $source, $externalId, $canonicalUrl, $byExternal, $byUrl);

            return $byExternal;
        }

        if ($byExternal !== null) {
            $this->enrich($byExternal, [
                'url' => $url,
                'canonical_url' => $canonicalUrl,
                'canonical_url_hash' => $canonicalUrlHash,
            ]);

            return $byExternal;
        }

        if ($byUrl !== null) {
            if ($externalId !== null && $byUrl->external_id !== null && $byUrl->external_id !== $externalId) {
                $this->recordConflict($user, $source, $externalId, $canonicalUrl, $byUrl, null);

                return $byUrl;
            }

            $this->enrich($byUrl, [
                'external_id' => $externalId,
                'url' => $url,
                'canonical_url' => $canonicalUrl,
                'canonical_url_hash' => $canonicalUrlHash,
            ]);

            return $byUrl;
        }

        if ($fingerprint === null) {
            return null;
        }

        return Publication::query()
            ->where('user_id', $user->id)
            ->where('source', $source)
            ->where('fingerprint', $fingerprint)
            ->first();
    }

    private function enrich(Publication $publication, array $attributes): void
    {
        $changes = [];

        foreach ($attributes as $key => $value) {
            if ($value !== null && $publication->getAttribute($key) === null) {
                $changes[$key] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        DB::transaction(fn () => $publication->forceFill($changes)->save());
    }

    private function recordConflict(User $user, string $source, ?string $externalId, ?string $canonicalUrl, Publication $publication, ?Publication $other): void
    {
        $identityHash = hash('sha256', implode('|', [
            $user->id,
            $source,
            $externalId ?? '',
            $canonicalUrl ?? '',
        ]));

        PublicationIdentityConflict::firstOrCreate(
            ['identity_hash' => $identityHash],
            [
                'user_id' => $user->id,
                'source' => $source,
                'external_id' => $externalId,
                'canonical_url' => $canonicalUrl,
                'publication_id' => $publication->id,
                'other_publication_id' => $other?->id,
                'reason' => PublicationIdentityConflict::ExternalIdUrlMismatch,
            ],
        );
    }

    private function fingerprint(?string $fingerprint): ?string
    {
        $fingerprint = $this->clean($fingerprint);

        if ($fingerprint === null) {
            return null;
        }

        return strlen($fingerprint) > 255 ? hash('sha256', $fingerprint) : $fingerprint;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
