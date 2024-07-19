<?php
declare(strict_types=1);

namespace Chialab\ObjectStorage\Utils;

/**
 * Path-related utility methods.
 *
 * @internal
 */
class Path
{
    /**
     * Private constructor to disable instantiating this class.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Split a path into its segments.
     *
     * @param string $path Path to split.
     * @return array<string|null>
     */
    public static function split(string $path): array
    {
        $segments = [];
        $segment = '';
        while ($path !== '') {
            $nextEsc = strpos($path, '\\');
            $nextSep = strpos($path, '/');
            if ($nextSep === false) {
                $nextSep = strlen($path);
            }

            if ($nextEsc !== false && $nextEsc < $nextSep) {
                $segment .= substr($path, 0, $nextEsc + 2);
                $path = substr($path, $nextEsc + 2);

                continue;
            }

            $segments[] = $segment . substr($path, 0, $nextSep);
            $segment = '';
            $path = substr($path, $nextSep + 1);
        }

        if ($segment !== '') {
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * Join multiple paths together.
     *
     * @param string ...$paths Paths to join.
     * @return string
     */
    public static function join(string ...$paths): string
    {
        return implode('/', array_reduce(
            array_reverse($paths),
            function (array $path, string $next): array {
                if (($path[0] ?? null) === '') {
                    // Already found the rightmost absolute path.
                    return $path;
                }

                return array_merge(static::split($next), $path);
            },
            [],
        ));
    }
}
