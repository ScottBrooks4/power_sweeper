<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Locates Filter/LookUp predicate spans whose first argument is a connector data source.
 *
 * @phpstan-type Span array{start:int,end:int,source:string}
 */
final class DelegationPredicateExtractor
{
    /**
     * @return list<Span>
     */
    public static function extract(string $formula, AppDataContext $dataContext): array
    {
        $body = ltrim(trim($formula), '=');
        $spans = [];

        if (preg_match_all('/\b(Filter|LookUp)\s*\(/i', $body, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $fnStart = (int) $match[1];
                $openParen = $fnStart + strlen(rtrim($match[0])) - 1;
                $args = self::splitTopLevelArgs($body, $openParen);
                if ($args === null || count($args) < 2) {
                    continue;
                }
                $source = trim($args[0]);
                if (!self::isConnectorSource($source, $dataContext)) {
                    continue;
                }
                $predicate = $args[1];
                $predStart = strpos($body, $predicate, $openParen);
                if ($predStart === false) {
                    continue;
                }
                $spans[] = [
                    'start' => $predStart,
                    'end' => $predStart + strlen($predicate),
                    'source' => self::unquoteSource($source),
                ];
            }
        }

        return $spans;
    }

    public static function offsetInSpan(int $offset, array $spans): bool
    {
        foreach ($spans as $span) {
            if ($offset >= $span['start'] && $offset < $span['end']) {
                return true;
            }
        }

        return false;
    }

    private static function isConnectorSource(string $source, AppDataContext $dataContext): bool
    {
        $name = self::unquoteSource($source);
        if ($name === '') {
            return false;
        }
        if (preg_match('/^(col|var|gbl)[A-Z_]/', $name)) {
            return false;
        }

        return $dataContext->isDataSource($name);
    }

    private static function unquoteSource(string $source): string
    {
        $source = trim($source);
        if (str_starts_with($source, "'") && str_ends_with($source, "'")) {
            return str_replace("''", "'", substr($source, 1, -1));
        }

        return $source;
    }

    /**
     * @return list<string>|null
     */
    private static function splitTopLevelArgs(string $body, int $openParen): ?array
    {
        if ($body[$openParen] !== '(') {
            return null;
        }

        $args = [];
        $depth = 0;
        $current = '';
        $len = strlen($body);
        for ($i = $openParen + 1; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') {
                $depth++;
                $current .= $ch;
                continue;
            }
            if ($ch === ')') {
                if ($depth === 0) {
                    $args[] = trim($current);
                    return $args;
                }
                $depth--;
                $current .= $ch;
                continue;
            }
            if ($ch === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }

        return null;
    }
}
