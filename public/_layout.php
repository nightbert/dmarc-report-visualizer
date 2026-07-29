<?php

declare(strict_types=1);

const APP_FAVICON = 'data:image/svg+xml,%3Csvg%20xmlns=%27http://www.w3.org/2000/svg%27%20viewBox=%270%200%2016%2016%27%3E%3Crect%20width=%2716%27%20height=%2716%27%20rx=%274%27%20fill=%27%2322577a%27/%3E%3Cpath%20d=%27M4%208h8v2H4z%27%20fill=%27%23fff%27/%3E%3C/svg%3E';

const APP_TITLE = 'DMARC Report Visualizer';
const APP_TAGLINE = 'Inspect and track aggregate DMARC reports';

// The stylesheets in cascade order. They are linked one by one rather than
// pulled together by @import, because an imported file keeps its own cache
// entry: busting the wrapper would leave the browser using the old imports, so
// a deploy could mix new markup with stale rules.
function styleSheets(): array
{
    return [
        'base',
        'components',
        'header',
        'layout',
        'sidebar',
        'reports',
        'health',
        'status',
        'upload',
        'report-detail',
        'trends',
        'footer',
        'responsive',
    ];
}

// Encode data for an inline <script> block. JSON_HEX_TAG is what makes it safe:
// without it a value holding "</script>" — a filter echoed back from the query
// string, an org name out of a report — closes the block and everything after it
// is parsed as markup.
function jsonForScript($data): string
{
    return json_encode($data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

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

HTML;

    foreach (styleSheets() as $name) {
        // The file's mtime is the cache key, so an edited stylesheet reaches
        // the browser without anyone having to remember to bump a version.
        $version = @filemtime(__DIR__ . '/css/' . $name . '.css') ?: 0;
        echo '    <link rel="stylesheet" href="/css/' . $name . '.css?v=' . $version . '">' . "\n";
    }

    echo <<<HTML
    <link rel="icon" type="image/svg+xml" href="{$favicon}">
  </head>
<body>

HTML;
}

// Output the sticky masthead. Title, tagline and the report count are fixed
// here rather than passed in, so every page carries the identical header — what
// page you are on is shown by the nav, and by the headings in the content.
function renderHero(string $active = ''): void
{
    $title = htmlspecialchars(APP_TITLE, ENT_QUOTES);
    $tagline = htmlspecialchars(APP_TAGLINE, ENT_QUOTES);
    $dash = $active === 'dashboard' ? ' class="is-active" aria-current="page"' : '';
    $trends = $active === 'trends' ? ' class="is-active" aria-current="page"' : '';
    $total = number_format(reportTotalCount());

    echo <<<HTML
  <header class="hero">
    <div class="hero-content">
      <div class="hero-text">
        <h1>{$title}</h1>
        <p class="hero-sub">{$tagline}</p>
      </div>
      <nav class="hero-nav">
        <a href="/"{$dash}>Dashboard</a>
        <a href="/trends.php"{$trends}>Trends</a>
      </nav>
      <div class="hero-stat">
        <strong id="total-reports">{$total}</strong>
        <span class="hero-stat-label">reports</span>
      </div>
    </div>
  </header>

HTML;
}

// Output the second header row used by the detail views: what you are looking
// at, and the way back out of it. The masthead above it stays identical
// everywhere, so this is where a page names itself; the back link sits in the
// same row, right-aligned under the nav, on every detail view.
function renderSubHero(string $title, string $identity, string $backHref = '', string $backLabel = ''): void
{
    $title = htmlspecialchars($title, ENT_QUOTES);
    $identity = htmlspecialchars($identity, ENT_QUOTES);

    echo <<<HTML
  <div class="hero-secondary">
    <div class="hero-content">
      <div class="hero-text">
        <h1>{$title}</h1>
        <p class="hero-sub mono">{$identity}</p>
      </div>

HTML;

    if ($backHref !== '') {
        $href = htmlspecialchars($backHref, ENT_QUOTES);
        $label = htmlspecialchars($backLabel, ENT_QUOTES);
        echo "      <a class=\"hero-back\" href=\"{$href}\">&larr; {$label}</a>\n";
    }

    echo <<<HTML
    </div>
  </div>

HTML;
}

// Output the filter bar shared by the dashboard and trends: a range switch plus
// the domain and reporter selects. Both pages render the same markup so their
// controls cannot drift apart; the range options stay real links so the switch
// works without JavaScript.
function renderFilterBar(string $page, string $range, string $org, string $domain, array $orgOptions, array $domainOptions): void
{
    echo "      <section class=\"filter-bar\">\n";
    echo "        <div class=\"range-switch\" role=\"group\" aria-label=\"Time range\">\n";
    foreach (array_keys(reportRangeOptions()) as $key) {
        $query = http_build_query(array_filter(['range' => $key, 'org' => $org, 'domain' => $domain]));
        $href = htmlspecialchars($page . '?' . $query, ENT_QUOTES);
        $active = $range === $key;
        $class = 'range-option' . ($active ? ' is-active' : '');
        $current = $active ? ' aria-current="true"' : '';
        $label = htmlspecialchars(strtoupper($key), ENT_QUOTES);
        echo "          <a class=\"{$class}\" href=\"{$href}\"{$current}>{$label}</a>\n";
    }
    echo "        </div>\n";

    renderFilterSelect('filter-domain', 'Domain', $domain, $domainOptions);
    renderFilterSelect('filter-org', 'Reporter', $org, $orgOptions);

    echo "      </section>\n";
}

// Output the line under the filter bar: what the deltas are measured against on
// the left, the window the filters resolved to flush right. Kept out of the bar
// itself so it cannot push the controls onto a second line.
function renderRangeNote(string $window, string $comparison = ''): void
{
    $hidden = ($window === '' && $comparison === '') ? ' hidden' : '';
    $windowText = htmlspecialchars($window, ENT_QUOTES);
    $comparisonText = htmlspecialchars($comparison, ENT_QUOTES);

    echo <<<HTML
      <p class="section-note range-note" id="filter-range"{$hidden}>
        <span class="range-comparison">{$comparisonText}</span>
        <span class="range-window">{$windowText}</span>
      </p>

HTML;
}

// Output one labelled select for the filter bar.
function renderFilterSelect(string $id, string $label, string $selected, array $options): void
{
    $id = htmlspecialchars($id, ENT_QUOTES);
    $label = htmlspecialchars($label, ENT_QUOTES);
    echo "        <div class=\"filter-inline\">\n";
    echo "          <label for=\"{$id}\">{$label}</label>\n";
    echo "          <select id=\"{$id}\">\n";
    echo "            <option value=\"\">All</option>\n";
    foreach ($options as $option) {
        $value = htmlspecialchars((string)$option, ENT_QUOTES);
        $isSelected = $selected === (string)$option ? ' selected' : '';
        echo "            <option value=\"{$value}\"{$isSelected}>{$value}</option>\n";
    }
    echo "          </select>\n";
    echo "        </div>\n";
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
