<?php

declare(strict_types=1);

// Absolute path to the repo's bundled ./data directory (cached).
function repoDataRoot(): string
{
    static $root = null;
    if ($root === null) {
        $candidate = __DIR__ . '/data';
        $root = rtrim($candidate, DIRECTORY_SEPARATOR);
    }
    return $root;
}

// Build a path inside the repo's bundled data directory.
function repoDataPath(string $relative): string
{
    $relative = ltrim($relative, DIRECTORY_SEPARATOR);
    return repoDataRoot() . DIRECTORY_SEPARATOR . $relative;
}

// Resolve a data path from its env override, system default, or repo fallback.
function resolveDataPath(string $envKey, string $systemDefault, string $repoRelative): string
{
    $envValue = getenv($envKey);
    if ($envValue !== false && $envValue !== '') {
        return $envValue;
    }

    $hasExtension = pathinfo($systemDefault, PATHINFO_EXTENSION) !== '';
    if (!$hasExtension && ensureWritableDir($systemDefault)) {
        return $systemDefault;
    }

    $defaultDir = $hasExtension ? dirname($systemDefault) : '';
    if ($defaultDir !== '' && ensureWritableDir($defaultDir)) {
        return $systemDefault;
    }

    return repoDataPath($repoRelative);
}

// Whether a directory exists (or can be created) and is writable.
function ensureWritableDir(string $dir): bool
{
    if ($dir === '') {
        return false;
    }

    if (is_dir($dir)) {
        return is_writable($dir);
    }

    if (!@mkdir($dir, 0775, true)) {
        return false;
    }

    return is_writable($dir);
}
