<?php

namespace App\Services\Discovery;

use App\Enums\DiscoverySourceSurface;
use App\Enums\SearchPlatform;
use InvalidArgumentException;

class DiscoverySourceUrl
{
    private const TRACKING_PARAMETERS = [
        'trk', 'trkinfo', 'trackingid', 'refid', 'ref', 'lipi', 'midtoken',
        'midsig', 'trkemail', 'eid', 'gclid', 'fbclid', 'msclkid', 'yclid',
        'igshid', 'from', 'vjs', 'tk', 'advn', 'xkcb', 'spa', 'fccid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    ];

    private const HOSTS = [
        'linkedin' => ['www.linkedin.com', 'linkedin.com', 'br.linkedin.com'],
        'indeed' => ['br.indeed.com', 'www.indeed.com', 'indeed.com'],
    ];

    private const PERIOD_DAYS = [1, 3, 7, 14, 30];

    private const LINKEDIN_DATE_POSTED = ['["past-24h"]', '["past-week"]', '["past-month"]'];

    private const PATHS = [
        'linkedin_jobs' => ['/jobs/search/', '/jobs/search', '/jobs/search-results', '/jobs/search-results/'],
        'linkedin_publications' => ['/search/results/content/', '/search/results/content'],
        'indeed_jobs' => ['/jobs', '/m/jobs'],
    ];

    public function validate(SearchPlatform $platform, DiscoverySourceSurface $surface, string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('A URL da fonte é inválida.');
        }

        if (mb_strtolower($parts['scheme']) !== 'https') {
            throw new InvalidArgumentException('A URL da fonte deve usar HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('A URL da fonte não pode conter credenciais.');
        }

        $host = mb_strtolower($parts['host']);

        if (! in_array($host, self::HOSTS[$platform->value], true)) {
            throw new InvalidArgumentException('A URL da fonte não pertence à plataforma informada.');
        }

        $path = $parts['path'] ?? '/';

        if (! in_array(rtrim($path, '/') ?: '/', array_map(static fn (string $allowed): string => rtrim($allowed, '/') ?: '/', self::PATHS[$surface->value]), true)) {
            throw new InvalidArgumentException('A URL da fonte não corresponde à superfície informada.');
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $this->validateSemanticFilters($surface, $query);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function validateSemanticFilters(DiscoverySourceSurface $surface, array $query): void
    {
        $periodKeys = array_values(array_intersect(array_keys($query), ['f_TPR', 'fromage', 'datePosted']));

        foreach ($periodKeys as $key) {
            if (! is_string($query[$key])) {
                throw new InvalidArgumentException('O filtro de período da URL deve ser um valor único.');
            }
        }

        if (count($periodKeys) > 1) {
            throw new InvalidArgumentException('A URL da fonte mistura filtros de período conflitantes.');
        }

        if ($surface === DiscoverySourceSurface::IndeedJobs) {
            if (array_key_exists('fromage', $query) && ! in_array((string) $query['fromage'], array_map('strval', self::PERIOD_DAYS), true)) {
                throw new InvalidArgumentException('O filtro fromage da URL não é suportado.');
            }

            if (array_intersect(array_keys($query), ['f_TPR', 'datePosted']) !== []) {
                throw new InvalidArgumentException('A URL do Indeed não aceita filtros de período do LinkedIn.');
            }

            return;
        }

        if ($surface === DiscoverySourceSurface::LinkedInJobs) {
            if (array_key_exists('f_TPR', $query)) {
                $value = (string) $query['f_TPR'];
                $allowed = array_map(static fn (int $days): string => 'r'.($days * 86400), self::PERIOD_DAYS);

                if (! in_array($value, $allowed, true)) {
                    throw new InvalidArgumentException('O filtro f_TPR da URL não é suportado.');
                }
            }

            if (array_key_exists('datePosted', $query) || array_key_exists('fromage', $query)) {
                throw new InvalidArgumentException('A URL de vagas do LinkedIn não aceita filtros de outra superfície.');
            }

            return;
        }

        if (array_key_exists('datePosted', $query)) {
            if (! in_array((string) $query['datePosted'], self::LINKEDIN_DATE_POSTED, true)) {
                throw new InvalidArgumentException('O filtro datePosted da URL não é suportado.');
            }
        }

        if (array_key_exists('f_TPR', $query) || array_key_exists('fromage', $query)) {
            throw new InvalidArgumentException('A URL de publicações não aceita filtros de vagas.');
        }
    }

    public function canonical(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('A URL da fonte é inválida.');
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $query = array_filter(
            $query,
            static fn (mixed $value, string $key): bool => ! in_array(mb_strtolower($key), self::TRACKING_PARAMETERS, true)
                && $value !== '',
            ARRAY_FILTER_USE_BOTH,
        );
        ksort($query);

        $canonical = mb_strtolower($parts['scheme']).'://'.mb_strtolower($parts['host']);

        if (isset($parts['port']) && ! $this->isDefaultPort(mb_strtolower($parts['scheme']), (int) $parts['port'])) {
            $canonical .= ':'.$parts['port'];
        }

        $canonical .= $this->normalizePath($parts['path'] ?? '/');

        if ($query !== []) {
            $canonical .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $canonical;
    }

    public function hash(string $url): string
    {
        return hash('sha256', $this->canonical($url));
    }

    public function matchesObservation(DiscoverySourceSurface $surface, string $observed, string $reserved): bool
    {
        $this->validate($surface->platform(), $surface, $observed);
        $this->validate($surface->platform(), $surface, $reserved);

        if ($surface !== DiscoverySourceSurface::LinkedInJobs) {
            return $this->hash($observed) === $this->hash($reserved);
        }

        return mb_strtolower((string) parse_url($observed, PHP_URL_HOST)) === mb_strtolower((string) parse_url($reserved, PHP_URL_HOST))
            && (parse_url($observed, PHP_URL_PORT) ?? 443) === (parse_url($reserved, PHP_URL_PORT) ?? 443)
            && $this->observationParameters($observed) === $this->observationParameters($reserved);
    }

    /** @return array<string, list<string>> */
    private function observationParameters(string $url): array
    {
        $parameters = [];
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if (in_array(mb_strtolower($key), self::TRACKING_PARAMETERS, true)
                || in_array($key, ['currentJobId', 'position', 'pageNum', 'skipRedirect', 'infoNotice'], true)) {
                continue;
            }
            $parameters[$key][] = urldecode($value);
        }
        ksort($parameters);

        return $parameters;
    }

    private function normalizePath(string $path): string
    {
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    }
}
