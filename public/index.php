<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../bin/fetch-lib.php';

$perPage = 20;
$reportData = reportSummariesPage(1, $perPage);
$summaries = $reportData['summaries'] ?? [];
$total = (int)($reportData['total'] ?? 0);
$yearOptions = $reportData['year_options'] ?? [];
$monthOptions = $reportData['month_options'] ?? [];
$orgOptions = $reportData['org_options'] ?? [];
$tokenIndex = $reportData['token_index'] ?? [];
$mailboxConfigured = mailboxConfigured();

?>
<?php
renderHead('DMARC Report Visualizer');
renderHero('DMARC Report Visualizer', 'Inspect and track aggregate DMARC reports', 'dashboard', false, ['id' => 'total-reports', 'value' => $total, 'label' => 'Total reports']);
?>

  <main class="container layout">
    <section class="main">
      <?php if ($total === 0): ?>
        <div class="empty">
          <h2>No reports found yet</h2>
          <p>Drop XML, XML.GZ, ZIP, EML, or MSG files into <strong>/data/inbox</strong> to get started.</p>
        </div>
      <?php else: ?>
        <div class="table-scroll table-fill">
        <table class="reports">
          <thead>
            <tr>
              <th>Start</th>
              <th>End</th>
              <th>Org</th>
              <th>Domain</th>
              <th>Report ID</th>
              <th>Records</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
        <?php foreach ($summaries as $summary): ?>
          <tr data-year="<?= htmlspecialchars((string)$summary['year'], ENT_QUOTES) ?>"
              data-month="<?= htmlspecialchars((string)$summary['month'], ENT_QUOTES) ?>"
              data-org="<?= htmlspecialchars($summary['org'], ENT_QUOTES) ?>"
              data-token="<?= htmlspecialchars($summary['token'], ENT_QUOTES) ?>">
            <td><?= htmlspecialchars(!empty($summary['begin_ts']) ? date('Y-m-d', $summary['begin_ts']) : date('Y-m-d', $summary['timestamp']), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(!empty($summary['end_ts']) ? date('Y-m-d', $summary['end_ts']) : '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($summary['org'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($summary['domain'], ENT_QUOTES) ?></td>
              <td><span class="truncate" title="<?= htmlspecialchars($summary['report_id'], ENT_QUOTES) ?>"><?= htmlspecialchars($summary['report_id'], ENT_QUOTES) ?></span></td>
              <td><?= (int)$summary['records'] ?></td>
              <td>
                <?php if ($summary['token'] !== ''): ?>
                  <a href="/report.php?f=<?= urlencode($summary['token']) ?>">View</a>
                <?php else: ?>
                  <span class="muted">Unavailable</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <div class="pagination" id="pagination">
          <button type="button" class="page-btn" id="page-prev">Previous</button>
          <div class="page-info" id="page-info"></div>
          <button type="button" class="page-btn" id="page-next">Next</button>
        </div>
      <?php endif; ?>
    </section>

    <aside class="sidebar">
      <section class="card filter-card">
        <details class="filter-details">
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
                <?php foreach ($yearOptions as $year): ?>
                  <option value="<?= htmlspecialchars((string)$year, ENT_QUOTES) ?>"><?= htmlspecialchars((string)$year, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-group">
              <label for="filter-month">Month</label>
              <select id="filter-month">
                <option value="">All</option>
                <?php foreach ($monthOptions as $month): ?>
                  <option value="<?= htmlspecialchars((string)$month, ENT_QUOTES) ?>"><?= htmlspecialchars((string)$month, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-group">
              <label for="filter-org">Organization</label>
              <select id="filter-org">
                <option value="">All</option>
                <?php foreach ($orgOptions as $org): ?>
                  <option value="<?= htmlspecialchars($org, ENT_QUOTES) ?>"><?= htmlspecialchars($org, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-group">
              <div class="filter-actions">
                <button type="button" id="clear-filters">Clear filters</button>
                <button type="button" id="status-reload">Reload</button>
              </div>
            </div>
          </div>
        </details>
      </section>

      <section class="card">
        <h2>Upload</h2>
        <form id="upload-form" class="upload-form">
          <div class="dropzone" id="dropzone">
            <input type="file" name="files[]" id="file-input" multiple accept=".xml,.zip,.gz,.eml,.msg" />
            <div class="dropzone-content">
              <span class="dropzone-title">Drop files here</span>
              <span class="dropzone-sub">or click to choose (XML, XML.GZ, ZIP, EML, MSG)</span>
            </div>
          </div>
        </form>
        <div class="sidebar-divider"></div>

        <?php if ($mailboxConfigured): ?>
        <div class="mailbox-row">
          <button type="button" id="fetch-mailbox">Fetch mailbox</button>
          <span class="mailbox-last muted" id="mailbox-last-fetch">Last fetch: &ndash;</span>
        </div>
        <div class="sidebar-divider"></div>
        <?php endif; ?>

        <div class="status-header-row">
          <h2>Fetch Status</h2>
          <select id="status-filter" class="status-filter">
            <option value="all">All</option>
            <option value="errors">Errors only</option>
          </select>
        </div>
        <div id="status-list" class="status-list">
          <div class="muted">No activity yet.</div>
        </div>
      </section>
    </aside>
  </main>

  <div class="drag-overlay" id="drag-overlay">
    <div class="drag-overlay-card">
      <div class="drag-overlay-title">Drop to upload</div>
      <div class="drag-overlay-sub">XML, XML.GZ, ZIP, EML, or MSG</div>
    </div>
  </div>

  <?php renderFooter(); ?>

  <script>
    window.APP_INITIAL = {
      tokenIndex: <?= json_encode($tokenIndex, JSON_UNESCAPED_SLASHES) ?>,
      total: <?= (int)$total ?>,
      page: 1,
      perPage: <?= (int)$perPage ?>,
    };
  </script>
  <script src="/js/update-check.js" defer></script>
  <script src="/js/index.js" defer></script>
</body>
</html>
