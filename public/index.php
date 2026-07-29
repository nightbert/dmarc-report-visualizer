<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../bin/fetch-lib.php';

$range = isset($_GET['range']) && is_string($_GET['range']) ? trim($_GET['range']) : '30d';
$org = isset($_GET['org']) && is_string($_GET['org']) ? trim($_GET['org']) : '';
$domain = isset($_GET['domain']) && is_string($_GET['domain']) ? trim($_GET['domain']) : '';

$perPage = 20;
$reportData = reportSummariesPage(1, $perPage, $range, $org, $domain);
$summaries = $reportData['summaries'] ?? [];
$total = (int)($reportData['total'] ?? 0);
$range = (string)($reportData['range'] ?? '30d');
$window = $reportData['window'] ?? [];
$orgOptions = $reportData['org_options'] ?? [];
$domainOptions = $reportData['domain_options'] ?? [];
$tokenIndex = $reportData['token_index'] ?? [];
$mailboxConfigured = mailboxConfigured();

// gmdate, not date: the window is snapped to whole UTC days, and the trends
// view re-renders this same line client-side in UTC.
$rangeNote = ($window['start_ts'] ?? 0) > 0
    ? gmdate('Y-m-d', $window['start_ts']) . ' – ' . gmdate('Y-m-d', $window['end_ts'])
    : '';

$health = reportHealthData($range, $org, $domain);
$healthAvailable = (bool)($health['available'] ?? false);
$kpis = [];
if ($healthAvailable) {
    $current = $health['current'];
    $previous = $health['previous'];
    $hasPrevious = (bool)$health['has_previous'];
    $newSourceStart = (int)$health['new_source_start_ts'];

    $kpis = [
        [
            'label' => 'Pass rate',
            'value' => rtrim(rtrim(number_format($current['pass_rate'], 1), '0'), '.') . '%',
            'accent' => passRateClass((float)$current['pass_rate']),
            'delta' => $hasPrevious ? formatDelta($current['pass_rate'] - $previous['pass_rate'], 1) . ' pp' : '',
            'delta_class' => $hasPrevious ? deltaClass($current['pass_rate'] - $previous['pass_rate'], true) : '',
            'note' => number_format($current['pass']) . ' of ' . number_format($current['total']) . ' messages aligned',
            'lead' => true,
        ],
        [
            'label' => 'Failing messages',
            'value' => number_format($current['fail']),
            'accent' => $current['fail'] > 0 ? 'is-warn' : '',
            'delta' => $hasPrevious ? formatDelta((float)($current['fail'] - $previous['fail'])) : '',
            'delta_class' => $hasPrevious ? deltaClass((float)($current['fail'] - $previous['fail']), false) : '',
            'note' => 'Neither SPF nor DKIM aligned',
        ],
        [
            'label' => 'Failing sources',
            'value' => number_format($current['failing_sources']),
            'accent' => '',
            'delta' => $hasPrevious ? formatDelta((float)($current['failing_sources'] - $previous['failing_sources'])) : '',
            'delta_class' => $hasPrevious ? deltaClass((float)($current['failing_sources'] - $previous['failing_sources']), false) : '',
            'note' => 'of ' . number_format($current['sources']) . ' sending IPs',
        ],
        [
            'label' => 'New sources',
            'value' => number_format($health['new_sources']),
            'accent' => $health['new_sources'] > 0 ? 'is-mid' : '',
            'delta' => '',
            'delta_class' => '',
            'note' => 'First seen in the last ' . $health['new_source_days'] . ' days',
        ],
    ];
}

?>
<?php
renderHead('DMARC Report Visualizer');
renderHero('dashboard');
?>

  <main class="container layout">
    <section class="main">
      <?php renderFilterBar('/', $range, $org, $domain, $orgOptions, $domainOptions); ?>
      <?php renderRangeNote($rangeNote, !empty($hasPrevious) ? 'Change measured against the preceding ' . (int)$health['window']['days'] . ' days' : ''); ?>

      <?php if ($healthAvailable): ?>
        <section class="health">
          <div class="kpi-row">
            <?php foreach ($kpis as $kpi): ?>
              <div class="kpi<?= !empty($kpi['lead']) ? ' kpi--lead' : '' ?>">
                <span class="kpi-label"><?= htmlspecialchars($kpi['label'], ENT_QUOTES) ?></span>
                <span class="kpi-figure">
                  <strong class="<?= htmlspecialchars($kpi['accent'], ENT_QUOTES) ?>"><?= htmlspecialchars($kpi['value'], ENT_QUOTES) ?></strong>
                  <?php if ($kpi['delta'] !== ''): ?>
                    <span class="delta <?= htmlspecialchars($kpi['delta_class'], ENT_QUOTES) ?>"><?= htmlspecialchars($kpi['delta'], ENT_QUOTES) ?></span>
                  <?php endif; ?>
                </span>
                <span class="kpi-note muted"><?= htmlspecialchars($kpi['note'], ENT_QUOTES) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2>Domains</h2>
            <span class="panel-note">Click a domain for its trend</span>
          </div>
          <?php if ($health['domains'] === []): ?>
            <p class="muted">No records in this window.</p>
          <?php else: ?>
            <div class="table-scroll">
              <table class="reports health-table" data-sortable>
                <thead>
                  <tr>
                    <th>Domain</th>
                    <th class="num" data-sort-default="desc">Messages</th>
                    <th data-sort-default="desc">Pass rate</th>
                    <th class="num" data-sort-default="desc">Failing</th>
                    <th class="num" data-sort-default="desc">Sources</th>
                    <th class="num" data-sort-default="desc" title="Messages quarantined or rejected">Enforced</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($health['domains'] as $row): ?>
                    <tr>
                      <td><a href="/trends.php?<?= htmlspecialchars(http_build_query(['range' => $health['range'], 'domain' => $row['domain']]), ENT_QUOTES) ?>"><?= htmlspecialchars($row['domain'], ENT_QUOTES) ?></a></td>
                      <td class="num" data-sort="<?= (int)$row['total'] ?>"><?= number_format($row['total']) ?></td>
                      <td data-sort="<?= htmlspecialchars((string)$row['pass_rate'], ENT_QUOTES) ?>">
                        <div class="rate-bar" title="<?= htmlspecialchars((string)$row['pass_rate'], ENT_QUOTES) ?>% pass">
                          <span class="rate-pass" style="width:<?= (float)$row['pass_rate'] ?>%"></span>
                          <span class="rate-fail" style="width:<?= 100 - (float)$row['pass_rate'] ?>%"></span>
                        </div><span class="rate-text"><?= htmlspecialchars((string)$row['pass_rate'], ENT_QUOTES) ?>%</span>
                      </td>
                      <td class="num" data-sort="<?= (int)$row['fail'] ?>"><?= number_format($row['fail']) ?></td>
                      <td class="num" data-sort="<?= (int)$row['sources'] ?>"><?= number_format($row['sources']) ?></td>
                      <td class="num" data-sort="<?= (int)$row['enforced'] ?>"><?= number_format($row['enforced']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="panel">
          <div class="panel-head">
            <h2>Needs attention</h2>
            <span class="panel-note">Sources sending unaligned mail &middot; click an IP for details</span>
          </div>
          <?php if ($health['failing_sources'] === []): ?>
            <p class="muted">Every source in this window was aligned.</p>
          <?php else: ?>
            <div class="table-scroll">
              <table class="reports health-table" data-sortable>
                <thead>
                  <tr>
                    <th>Source IP</th>
                    <th class="num" data-sort-default="desc">Failing</th>
                    <th class="num" data-sort-default="desc">Messages</th>
                    <th class="num" data-sort-default="desc">Domains</th>
                    <th data-sort-default="desc">First seen</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($health['failing_sources'] as $row): ?>
                    <tr>
                      <td class="mono"><a href="/sender.php?<?= htmlspecialchars(http_build_query(['ip' => $row['source_ip'], 'range' => $health['range']]), ENT_QUOTES) ?>"><?= htmlspecialchars($row['source_ip'], ENT_QUOTES) ?></a></td>
                      <td class="num" data-sort="<?= (int)$row['fail'] ?>"><?= number_format($row['fail']) ?></td>
                      <td class="num" data-sort="<?= (int)$row['total'] ?>"><?= number_format($row['total']) ?></td>
                      <td class="num" data-sort="<?= (int)$row['domains'] ?>"><?= number_format($row['domains']) ?></td>
                      <td data-sort="<?= (int)$row['first_seen'] ?>">
                        <?= $row['first_seen'] > 0 ? htmlspecialchars(date('Y-m-d', $row['first_seen']), ENT_QUOTES) : '' ?>
                        <?php if ($row['first_seen'] >= $newSourceStart): ?><span class="badge-new">new</span><?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

      <?php endif; ?>

      <section class="panel">
        <div class="panel-head">
          <h2>Reports</h2>
          <span class="panel-note"><?= number_format($total) ?> in this range</span>
        </div>
      <?php if ($total === 0): ?>
        <div class="empty">
          <h2>No reports in this range</h2>
          <p>Widen the range above, or drop XML, XML.GZ, ZIP, EML, or MSG files into <strong>/data/inbox</strong> to get started.</p>
        </div>
      <?php else: ?>
        <div class="table-scroll table-fill">
        <table class="reports" id="reports-table">
          <thead>
            <tr>
              <th class="is-sortable is-sorted is-sorted-desc" data-sort-key="start" data-sort-default="desc" tabindex="0" aria-sort="descending">Start</th>
              <th class="is-sortable" data-sort-key="end" data-sort-default="desc" tabindex="0" aria-sort="none">End</th>
              <th class="is-sortable" data-sort-key="org" tabindex="0" aria-sort="none">Org</th>
              <th class="is-sortable" data-sort-key="domain" tabindex="0" aria-sort="none">Domain</th>
              <th class="is-sortable" data-sort-key="report_id" tabindex="0" aria-sort="none">Report ID</th>
              <th class="is-sortable" data-sort-key="records" data-sort-default="desc" tabindex="0" aria-sort="none">Records</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
        <?php foreach ($summaries as $summary): ?>
          <tr>
            <td><?= htmlspecialchars(!empty($summary['begin_ts']) ? date('Y-m-d', $summary['begin_ts']) : date('Y-m-d', $summary['timestamp']), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(!empty($summary['end_ts']) ? date('Y-m-d', $summary['end_ts']) : '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($summary['org'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($summary['domain'], ENT_QUOTES) ?></td>
              <td><span class="truncate" title="<?= htmlspecialchars($summary['report_id'], ENT_QUOTES) ?>"><?= htmlspecialchars($summary['report_id'], ENT_QUOTES) ?></span></td>
              <td class="num"><?= (int)$summary['records'] ?></td>
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
    </section>

    <aside class="sidebar">
      <section class="panel">
        <div class="panel-head">
          <h2>Upload</h2>
        </div>
        <form id="upload-form" class="upload-form">
          <div class="dropzone" id="dropzone">
            <input type="file" name="files[]" id="file-input" multiple accept=".xml,.zip,.gz,.eml,.msg" />
            <div class="dropzone-content">
              <span class="dropzone-title">Drop files here</span>
              <span class="dropzone-sub">or click to choose (XML, XML.GZ, ZIP, EML, MSG)</span>
            </div>
          </div>
        </form>
      </section>

      <!-- Pulling reports in and watching what happens to them are one job, so
           the mailbox button and the status feed share a panel. -->
      <section class="panel">
        <div class="panel-head">
          <h2>Fetch</h2>
          <select id="status-filter" class="status-filter">
            <option value="all">All</option>
            <option value="errors">Errors only</option>
          </select>
        </div>

        <?php if ($mailboxConfigured): ?>
        <div class="mailbox-row">
          <button type="button" id="fetch-mailbox">Fetch mailbox</button>
          <span class="mailbox-last" id="mailbox-last-fetch">
            <span class="mailbox-last-label">Last fetch:</span>
            <span class="mailbox-last-value">&ndash;</span>
          </span>
        </div>
        <?php endif; ?>

        <div id="status-overall" class="status-overall" hidden>
          <div class="status-overall-head">
            <span class="status-overall-label">Queue</span>
            <span class="status-overall-count" id="status-overall-count"></span>
          </div>
          <div class="progress progress--overall">
            <div class="progress-bar" id="status-overall-bar" style="width:0%"></div>
          </div>
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
      tokenIndex: <?= jsonForScript($tokenIndex) ?>,
      total: <?= (int)$total ?>,
      page: 1,
      perPage: <?= (int)$perPage ?>,
      sort: <?= jsonForScript((string)($reportData['sort'] ?? 'start')) ?>,
      dir: <?= jsonForScript((string)($reportData['dir'] ?? 'desc')) ?>,
      range: <?= jsonForScript($range) ?>,
      org: <?= jsonForScript($org) ?>,
      domain: <?= jsonForScript($domain) ?>,
    };
  </script>
  <script src="/js/sort-table.js" defer></script>
  <script src="/js/update-check.js" defer></script>
  <script src="/js/index.js" defer></script>
</body>
</html>
