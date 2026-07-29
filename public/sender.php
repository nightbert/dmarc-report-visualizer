<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_layout.php';

$ip = isset($_GET['ip']) && is_string($_GET['ip']) ? trim($_GET['ip']) : '';
$range = isset($_GET['range']) && is_string($_GET['range']) ? trim($_GET['range']) : '30d';
$org = isset($_GET['org']) && is_string($_GET['org']) ? trim($_GET['org']) : '';
$domain = isset($_GET['domain']) && is_string($_GET['domain']) ? trim($_GET['domain']) : '';

$range = array_key_exists($range, reportRangeOptions()) ? $range : '30d';
$valid = filter_var($ip, FILTER_VALIDATE_IP) !== false;
$data = $valid ? reportSenderData($ip, $range, $org, $domain) : ['available' => false];
$available = (bool)($data['available'] ?? false);

$filterQuery = http_build_query(array_filter([
    'range' => $range,
    'org' => $org,
    'domain' => $domain,
]));
$backUrl = '/trends.php' . ($filterQuery !== '' ? '?' . $filterQuery : '');

$summary = $data['summary'] ?? null;
$byDomain = $data['by_domain'] ?? [];
$reports = $data['reports'] ?? [];

// A sender with no records inside the selected range gets the empty state, not
// a page full of zeros — the range switch above it stays usable either way.
$hasData = $available && (int)($summary['total'] ?? 0) > 0;

// Pass percentage of a total.
function passRate(int $pass, int $total): int
{
    return $total > 0 ? (int)round($pass / $total * 100) : 0;
}

?>
<?php
renderHead('Sender ' . $ip);
renderHero();
renderSubHero('Sender details', $ip !== '' ? $ip : 'Unknown IP', $backUrl, 'Back to trends');
?>

  <main class="container trends">

    <section class="filter-bar">
      <div class="range-switch" role="group" aria-label="Time range">
        <?php foreach (array_keys(reportRangeOptions()) as $key): ?>
          <a class="range-option<?= $range === $key ? ' is-active' : '' ?>"
             href="/sender.php?<?= htmlspecialchars(http_build_query(array_filter(['ip' => $ip, 'range' => $key, 'org' => $org, 'domain' => $domain])), ENT_QUOTES) ?>"
             <?= $range === $key ? 'aria-current="true"' : '' ?>><?= htmlspecialchars(strtoupper($key), ENT_QUOTES) ?></a>
        <?php endforeach; ?>
      </div>
    </section>

    <?php if (!$hasData || !$valid): ?>
      <div class="empty">
        <h2>No data for this sender</h2>
        <p><?= $valid ? 'No records were found for this source IP in the selected range. Try a longer one.' : 'The provided source IP is not valid.' ?></p>
      </div>
    <?php else: ?>
      <?php if ($summary): ?>
      <section class="kpi-row">
        <div class="kpi kpi--lead">
          <span class="kpi-label">Pass rate</span>
          <span class="kpi-figure"><strong class="<?= passRateClass((float)$summary['pass_rate']) ?>"><?= htmlspecialchars((string)$summary['pass_rate'], ENT_QUOTES) ?>%</strong></span>
          <span class="kpi-note muted"><?= number_format((int)$summary['pass']) ?> of <?= number_format((int)$summary['total']) ?> messages aligned</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Messages</span>
          <span class="kpi-figure"><strong><?= number_format((int)$summary['total']) ?></strong></span>
          <span class="kpi-note muted">Across <?= number_format((int)$summary['domains']) ?> domain<?= (int)$summary['domains'] === 1 ? '' : 's' ?></span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Failing</span>
          <span class="kpi-figure"><strong class="<?= (int)$summary['fail'] > 0 ? 'is-warn' : '' ?>"><?= number_format((int)$summary['fail']) ?></strong></span>
          <span class="kpi-note muted">Neither SPF nor DKIM aligned</span>
        </div>
      </section>
      <?php endif; ?>

      <section class="panel">
        <div class="panel-head">
          <h2>Messages by alignment</h2>
          <span class="panel-note">Click a segment for the reports behind it</span>
        </div>
        <div class="chart-legend">
          <span class="legend-item"><span class="legend-swatch swatch-full"></span>Full</span>
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
                <th data-col="action" data-nosort>Action</th>
              </tr>
            </thead>
            <tbody id="bucket-body"></tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>By domain</h2></div>
        <div class="table-scroll">
          <table class="reports senders-table" data-sortable>
            <thead>
              <tr><th>Domain</th><th class="num" data-sort-default="desc">Messages</th><th class="num" data-sort-default="desc">Pass</th><th class="num" data-sort-default="desc">Fail</th><th data-sort-default="desc">Pass rate</th></tr>
            </thead>
            <tbody>
              <?php foreach ($byDomain as $d): $pr = passRate((int)$d['pass'], (int)$d['total']); ?>
                <tr>
                  <td><?= $d['domain'] !== '' ? htmlspecialchars($d['domain'], ENT_QUOTES) : '<span class="muted">(none)</span>' ?></td>
                  <td class="num" data-sort="<?= (int)$d['total'] ?>"><?= number_format((int)$d['total']) ?></td>
                  <td class="num" data-sort="<?= (int)$d['pass'] ?>"><?= number_format((int)$d['pass']) ?></td>
                  <td class="num" data-sort="<?= (int)$d['fail'] ?>"><?= number_format((int)$d['fail']) ?></td>
                  <td data-sort="<?= $pr ?>">
                    <div class="rate-bar" title="<?= $pr ?>% pass">
                      <span class="rate-pass" style="width:<?= $pr ?>%"></span>
                      <span class="rate-fail" style="width:<?= 100 - $pr ?>%"></span>
                    </div><span class="rate-text"><?= $pr ?>%</span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head"><h2>Reports from this sender</h2></div>
        <div class="table-scroll">
          <table class="reports senders-table" data-sortable>
            <thead>
              <tr><th data-sort-default="desc">Start</th><th data-sort-default="desc">End</th><th>Org</th><th>Domain</th><th class="num" data-sort-default="desc">Messages</th><th data-nosort>Action</th></tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['begin_ts'] !== null ? date('Y-m-d', $r['begin_ts']) : '', ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($r['end_ts'] !== null ? date('Y-m-d', $r['end_ts']) : '', ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($r['org'], ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($r['domain'], ENT_QUOTES) ?></td>
                  <td class="num" data-sort="<?= (int)$r['total'] ?>"><?= number_format((int)$r['total']) ?></td>
                  <td>
                    <?php if (($r['token'] ?? '') !== ''): ?>
                      <a href="/report.php?f=<?= urlencode($r['token']) ?>">View</a>
                    <?php else: ?>
                      <span class="muted">Unavailable</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  </main>

  <?php renderFooter(); ?>

  <?php if ($hasData && $valid): ?>
  <script>
    window.SENDER_TIMESERIES = <?= jsonForScript($data['timeseries'] ?? []) ?>;
    window.SENDER_FILTERS = <?= jsonForScript(array_filter([
        'ip' => $ip,
        'range' => $range,
        'org' => $org,
        'domain' => $domain,
    ])) ?>;
  </script>
  <script src="/js/chart.js" defer></script>
  <script src="/js/bucket-reports.js" defer></script>
  <script src="/js/sender.js" defer></script>
  <?php endif; ?>
  <script src="/js/sort-table.js" defer></script>
  <script src="/js/update-check.js" defer></script>
</body>
</html>
