<?php
declare(strict_types=1);

namespace Hitrov;

/* Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * Product/support: https://builtnotbegged.com | SPDX-License-Identifier: MIT */

/** Read-only admission for private retry state and public snapshot bindings. */
final class Ipv6StatePaths
{
    public static function assertSeparate(string $journal, string $status): void
    {
        $private = [$journal, $journal . '.lock'];
        $public = [$status, $status . '.lock'];
        foreach ($private as $privatePath) {
            $privateBinding = self::canonicalPath($privatePath);
            $privateStat = @stat($privateBinding);
            foreach ($public as $publicPath) {
                $publicBinding = self::canonicalPath($publicPath);
                $publicStat = @stat($publicBinding);
                if ($privateBinding === $publicBinding
                    || ($privateStat !== false && $publicStat !== false
                        && $privateStat['dev'] === $publicStat['dev']
                        && $privateStat['ino'] === $publicStat['ino'])) {
                    throw new \RuntimeException('IPv6 public status and its lock must not overlap the private journal or operation lock.');
                }
            }
        }
    }

    private static function canonicalPath(string $path): string
    {
        if ($path === '' || strpos($path, "\0") !== false) {
            throw new \RuntimeException('IPv6 state and status paths must be valid filesystem paths.');
        }
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('Could not resolve the IPv6 state/status working directory.');
        }
        $absolute = $path[0] === '/' ? $path : $cwd . '/' . $path;
        $resolved = '/';
        foreach (explode('/', $absolute) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                $resolved = dirname($resolved);
                continue;
            }
            $candidate = rtrim($resolved, '/') . '/' . $component;
            clearstatcache(true, $candidate);
            $existing = realpath($candidate);
            if ($existing !== false) {
                $resolved = $existing;
            } elseif (is_link($candidate)) {
                throw new \RuntimeException('Could not resolve an IPv6 state/status symbolic link.');
            } else {
                // A future directory/file has no link target to follow. Keep
                // its components until any subsequent dot/dotdot is consumed.
                $resolved = $candidate;
            }
        }
        return $resolved;
    }
}
