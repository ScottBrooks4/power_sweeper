<?php

declare(strict_types=1);

namespace PowerSweeper;

final class StringSimilarity
{
    /**
     * Find the best candidate for $needle within $candidates.
     *
     * @param list<string> $candidates
     * @return null|array{match:string,distance:int,score:float}
     */
    public static function bestMatch(string $needle, array $candidates, int $maxDistance = 2): ?array
    {
        $needle = trim($needle);
        if ($needle === '' || $candidates === []) {
            return null;
        }

        $normalizedNeedle = self::normalize($needle);
        $best = null;

        foreach ($candidates as $candidate) {
            $candidate = (string) $candidate;
            if ($candidate === '') {
                continue;
            }
            if ($candidate === $needle) {
                return ['match' => $candidate, 'distance' => 0, 'score' => 100.0];
            }

            $normalizedCandidate = self::normalize($candidate);
            if ($normalizedNeedle === $normalizedCandidate) {
                return ['match' => $candidate, 'distance' => 0, 'score' => 99.0];
            }

            $distance = levenshtein($normalizedNeedle, $normalizedCandidate);
            similar_text($normalizedNeedle, $normalizedCandidate, $percent);

            // Allow slightly longer names more slack
            $limit = $maxDistance;
            if (strlen($normalizedNeedle) >= 12) {
                $limit = max($maxDistance, 3);
            }

            if ($distance > $limit && $percent < 82.0) {
                continue;
            }
            if ($distance > $limit) {
                continue;
            }

            $score = $percent - ($distance * 4);
            if ($best === null || $score > $best['score'] || ($score === $best['score'] && $distance < $best['distance'])) {
                $best = ['match' => $candidate, 'distance' => $distance, 'score' => $score];
            }
        }

        return $best;
    }

    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        $value = str_replace(['_', '-'], '', $value);
        return $value;
    }
}
