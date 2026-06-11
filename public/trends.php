<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_layout.php';

$year = isset($_GET['year']) && is_string($_GET['year']) ? trim($_GET['year']) : '';
$month = isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : '';
$org = isset($_GET['org']) && is_string($_GET['org']) ? trim($_GET['org']) : '';

$trends = reportTrendsData($year, $month, $org);
$available = (bool)($trends['available'] ?? false);
$yearOptions = $trends['year_options'] ?? [];
$monthOptions = $trends['month_options'] ?? [];
$orgOptions = $trends['org_options'] ?? [];

?>
<?php
renderHead('DMARC Trends');
renderHero('DMARC Trends', 'Aggregate pass rates and top senders over time', 'trends');
?>

  <main class="container<?= $available ? ' layout' : '' ?>">
    <?php if (!$available): ?>
      <div class="empty">
        <h2>Trend data is unavailable</h2>
        <p>Aggregate trends require the SQLite report index (the <code>pdo_sqlite</code> PHP extension). Once it is available, trends are built automatically from the stored reports.</p>
      </div>
    <?php else: ?>
      <section class="main">
        <section class="stat-cards" id="stat-cards" aria-live="polite"></section>

        <section class="card chart-card">
          <div class="card-header">
            <h2>Messages by alignment</h2>
          </div>
          <div class="chart-legend">
            <span class="legend-item"><span class="legend-swatch swatch-full"></span>Full (SPF+DKIM)</span>
            <span class="legend-item"><span class="legend-swatch swatch-dkim"></span>DKIM only</span>
            <span class="legend-item"><span class="legend-swatch swatch-spf"></span>SPF only</span>
            <span class="legend-item"><span class="legend-swatch swatch-fail"></span>Fail</span>
          </div>
          <div class="chart-wrap" id="chart-wrap"></div>
        </section>

        <section class="card">
          <div class="card-header">
            <h2>Top senders</h2>
            <span class="muted small">By message volume &middot; click an IP for details</span>
          </div>
          <div class="table-scroll">
            <table class="reports senders-table">
              <thead>
                <tr>
                  <th>Source IP</th>
                  <th class="num">Messages</th>
                  <th class="num">Pass</th>
                  <th class="num">Fail</th>
                  <th>Pass rate</th>
                </tr>
              </thead>
              <tbody id="senders-body">
                <tr><td colspan="5" class="muted">Loading&hellip;</td></tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <aside class="sidebar">
        <section class="card filter-card">
          <details class="filter-details" open>
            <summary class="filter-summary">
              <h2>Filters</h2>
              <svg class="filter-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"></polyline>
              </svg>
            </summary>
            <div class="filters">
              <div class="filter-group">
                <label for="filter-year">Year</label>
                <select id="filter-year">
                  <option value="">All</option>
                  <?php foreach ($yearOptions as $opt): ?>
                    <option value="<?= htmlspecialchars((string)$opt, ENT_QUOTES) ?>" <?= $year === (string)$opt ? 'selected' : '' ?>><?= htmlspecialchars((string)$opt, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="filter-group">
                <label for="filter-month">Month</label>
                <select id="filter-month">
                  <option value="">All</option>
                  <?php foreach ($monthOptions as $opt): ?>
                    <option value="<?= htmlspecialchars((string)$opt, ENT_QUOTES) ?>" <?= $month === (string)$opt ? 'selected' : '' ?>><?= htmlspecialchars((string)$opt, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="filter-group">
                <label for="filter-org">Organization</label>
                <select id="filter-org">
                  <option value="">All</option>
                  <?php foreach ($orgOptions as $opt): ?>
                    <option value="<?= htmlspecialchars((string)$opt, ENT_QUOTES) ?>" <?= $org === (string)$opt ? 'selected' : '' ?>><?= htmlspecialchars((string)$opt, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="filter-group">
                <div class="filter-actions">
                  <button type="button" id="clear-filters">Clear filters</button>
                </div>
              </div>
            </div>
          </details>
        </section>
      </aside>
    <?php endif; ?>
  </main>

  <?php renderFooter(); ?>

  <?php if ($available): ?>
  <script>
    window.TRENDS_INITIAL = <?= json_encode([
        'summary' => $trends['summary'] ?? null,
        'timeseries' => $trends['timeseries'] ?? [],
        'top_senders' => $trends['top_senders'] ?? [],
        'filters' => ['year' => $year, 'month' => $month, 'org' => $org],
    ], JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="/js/chart.js" defer></script>
  <script src="/js/trends.js" defer></script>
  <?php endif; ?>
  <script src="/js/update-check.js" defer></script>
</body>
</html>
