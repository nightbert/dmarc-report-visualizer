<?php

declare(strict_types=1);

const APP_FAVICON = 'data:image/svg+xml,%3Csvg%20xmlns=%27http://www.w3.org/2000/svg%27%20viewBox=%270%200%2016%2016%27%3E%3Crect%20width=%2716%27%20height=%2716%27%20rx=%274%27%20fill=%27%2322577a%27/%3E%3Cpath%20d=%27M4%208h8v2H4z%27%20fill=%27%23fff%27/%3E%3C/svg%3E';

// Output the document head and opening body tag.
function renderHead(string $title): void
{
    $title = htmlspecialchars($title, ENT_QUOTES);
    $favicon = APP_FAVICON;
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="icon" type="image/svg+xml" href="{$favicon}">
  </head>
<body>

HTML;
}

// Output the sticky hero header with navigation.
function renderHero(string $title, string $subtitle, string $active = '', bool $subMono = false, ?array $stat = null): void
{
    $title = htmlspecialchars($title, ENT_QUOTES);
    $subClass = 'hero-sub' . ($subMono ? ' mono' : '');
    $subtitle = htmlspecialchars($subtitle, ENT_QUOTES);
    $dash = $active === 'dashboard' ? ' class="is-active" aria-current="page"' : '';
    $trends = $active === 'trends' ? ' class="is-active" aria-current="page"' : '';

    echo <<<HTML
  <header class="hero">
    <div class="hero-content">
      <div class="hero-text">
        <h1>{$title}</h1>
        <p class="{$subClass}">{$subtitle}</p>
      </div>
      <nav class="hero-nav">
        <a href="/"{$dash}>Dashboard</a>
        <a href="/trends.php"{$trends}>Trends</a>
      </nav>

HTML;

    if ($stat !== null) {
        $id = isset($stat['id']) ? ' id="' . htmlspecialchars((string)$stat['id'], ENT_QUOTES) . '"' : '';
        $value = htmlspecialchars((string)($stat['value'] ?? ''), ENT_QUOTES);
        $label = htmlspecialchars((string)($stat['label'] ?? ''), ENT_QUOTES);
        echo <<<HTML
      <div class="hero-stat">
        <strong{$id}>{$value}</strong>
        <span class="hero-stat-label">{$label}</span>
      </div>

HTML;
    }

    echo "    </div>\n  </header>\n";
}

// Output the site footer (author, repo, version, update link).
function renderFooter(): void
{
    $repoUrl = appRepoUrl();
    $version = appVersion();
    $releaseUrl = appReleaseUrl($repoUrl, $version);
    $author = htmlspecialchars(appAuthor(), ENT_QUOTES);

    echo "  <footer class=\"site-footer\">\n    <div class=\"footer-content\">\n";
    echo "      <span>Author {$author}</span>\n";
    if ($repoUrl !== '') {
        $repo = htmlspecialchars($repoUrl, ENT_QUOTES);
        echo "      <span class=\"footer-sep\">•</span>\n";
        echo "      <a href=\"{$repo}\" target=\"_blank\" rel=\"noopener\">GitHub project</a>\n";
    }
    if ($releaseUrl !== '' && $version !== '') {
        $rel = htmlspecialchars($releaseUrl, ENT_QUOTES);
        $ver = htmlspecialchars($version, ENT_QUOTES);
        echo "      <span class=\"footer-sep\">•</span>\n";
        echo "      <a href=\"{$rel}\" target=\"_blank\" rel=\"noopener\">Version {$ver}</a>\n";
    } elseif ($version !== '') {
        $ver = htmlspecialchars($version, ENT_QUOTES);
        echo "      <span class=\"footer-sep\">•</span>\n";
        echo "      <span>Version {$ver}</span>\n";
    }
    echo "      <a id=\"update-link\" class=\"update-link\" target=\"_blank\" rel=\"noopener\" hidden>(Update available)</a>\n";
    echo "    </div>\n  </footer>\n";
}
