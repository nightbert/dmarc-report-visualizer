<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_layout.php';

$range = isset($_GET['range']) && is_string($_GET['range']) ? trim($_GET['range']) : '30d';
$org = isset($_GET['org']) && is_string($_GET['org']) ? trim($_GET['org']) : '';
$domain = isset($_GET['domain']) && is_string($_GET['domain']) ? trim($_GET['domain']) : '';

$trends = reportTrendsData($range, $org, $domain);
$available = (bool)($trends['available'] ?? false);
$range = (string)($trends['range'] ?? '30d');
$orgOptions = $trends['org_options'] ?? [];
$domainOptions = $trends['domain_options'] ?? [];
$window = $trends['window'] ?? [];
// gmdate, not date: the window is snapped to whole UTC days, and trends.js
// re-renders this same line client-side in UTC after every filter change.
$rangeNote = ($window['start_ts'] ?? 0) > 0
    ? gmdate('Y-m-d', $window['start_ts']) . ' – ' . gmdate('Y-m-d', $window['end_ts'])
    : '';
$comparisonNote = ($trends['previous'] ?? null) !== null
    ? 'Change measured against the preceding ' . (int)($window['days'] ?? 0) . ' days'
    : '';

?>
<?php
renderHead('DMARC Report Visualizer');
renderHero('trends');
?>

  <main class="container">
    <?php if (!$available): ?>
      <div class="empty">
        <h2>Trend data is unavailable</h2>
        <p>Aggregate trends require the SQLite report index (the <code>pdo_sqlite</code> PHP extension). Once it is available, trends are built automatically from the stored reports.</p>
      </div>
    <?php else: ?>
      <?php renderFilterBar('/trends.php', $range, $org, $domain, $orgOptions, $domainOptions); ?>
      <?php renderRangeNote($rangeNote, $comparisonNote); ?>

      <section class="kpi-row" id="kpi-cards" aria-live="polite"></section>

      <section class="panel">
        <div class="panel-head">
          <h2>Messages by alignment</h2>
          <span class="panel-note">Click a segment for the reports behind it</span>
        </div>
        <div class="chart-legend">
          <span class="legend-item"><span class="legend-swatch swatch-full"></span>Full (SPF+DKIM)</span>
          <span class="legend-item"><span class="legend-swatch swatch-dkim"></span>DKIM only</span>
          <span class="legend-item"><span class="legend-swatch swatch-spf"></span>SPF only</span>
          <span class="legend-item"><span class="legend-swatch swatch-fail"></span>Fail</span>
        </div>
        <div class="chart-wrap" id="chart-wrap"></div>
      </section>

      <section class="bucket-panel" id="bucket-panel" hidden>
        <div class="card-header">
          <div class="bucket-heading">
            <h2 id="bucket-title">Reports</h2>
            <span class="muted small" id="bucket-meta"></span>
          </div>
          <button type="button" class="page-btn btn-small" id="bucket-close">Close</button>
        </div>
        <div class="table-scroll">
          <table class="reports senders-table" data-sortable>
            <thead>
              <tr>
                <th data-col="start" data-sort-default="desc">Start</th>
                <th data-col="end" data-sort-default="desc">End</th>
                <th data-col="org">Org</th>
                <th data-col="domain">Domain</th>
                <th data-col="messages" class="num" data-sort-default="desc">Messages</th>
                <th data-col="sources" class="num" data-sort-default="desc">Sources</th>
                <th data-col="action" data-nosort>Action</th>
              </tr>
            </thead>
            <tbody id="bucket-body"></tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2>Policy applied</h2>
          <span class="panel-note">What receivers did with the mail</span>
        </div>
        <div id="disposition-panel"></div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2>Top senders</h2>
          <span class="panel-note">By message volume &middot; click an IP for details</span>
        </div>
        <div class="table-scroll">
          <table class="reports senders-table" id="senders-table" data-sortable>
            <thead>
              <tr>
                <th>Source IP</th>
                <th class="num" data-sort-default="desc">Messages</th>
                <th class="num" data-sort-default="desc">Pass</th>
                <th class="num" data-sort-default="desc">Fail</th>
                <th data-sort-default="desc">Pass rate</th>
                <th>Aligned by</th>
                <th data-sort-default="desc">First seen</th>
              </tr>
            </thead>
            <tbody id="senders-body">
              <tr><td colspan="7" class="muted">Loading&hellip;</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  </main>

  <?php renderFooter(); ?>

  <?php if ($available): ?>
  <script>
    window.TRENDS_INITIAL = <?= jsonForScript([
        'summary' => $trends['summary'] ?? null,
        'previous' => $trends['previous'] ?? null,
        'dispositions' => $trends['dispositions'] ?? [],
        'timeseries' => $trends['timeseries'] ?? [],
        'top_senders' => $trends['top_senders'] ?? [],
        'window' => $window,
        'filters' => ['range' => $range, 'org' => $org, 'domain' => $domain],
    ]) ?>;
  </script>
  <script src="/js/chart.js" defer></script>
  <script src="/js/sort-table.js" defer></script>
  <script src="/js/bucket-reports.js" defer></script>
  <script src="/js/trends.js" defer></script>
  <?php endif; ?>
  <script src="/js/update-check.js" defer></script>
</body>
</html>
