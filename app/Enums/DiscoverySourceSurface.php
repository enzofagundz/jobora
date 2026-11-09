<?php

namespace App\Enums;

enum DiscoverySourceSurface: string
{
    case LinkedInJobs = 'linkedin_jobs';
    case LinkedInPublications = 'linkedin_publications';
    case IndeedJobs = 'indeed_jobs';

    public function platform(): SearchPlatform
    {
        return match ($this) {
            self::LinkedInJobs, self::LinkedInPublications => SearchPlatform::LinkedIn,
            self::IndeedJobs => SearchPlatform::Indeed,
        };
    }

    public function mode(): SearchMode
    {
        return match ($this) {
            self::LinkedInJobs, self::IndeedJobs => SearchMode::Jobs,
            self::LinkedInPublications => SearchMode::Publications,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function forPlatform(SearchPlatform $platform): array
    {
        return array_values(array_map(
            static fn (self $surface): string => $surface->value,
            array_filter(self::cases(), static fn (self $surface): bool => $surface->platform() === $platform),
        ));
    }
}
