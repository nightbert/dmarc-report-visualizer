<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_layout.php';

$ip = isset($_GET['ip']) && is_string($_GET['ip']) ? trim($_GET['ip']) : '';
$year = isset($_GET['year']) && is_string($_GET['year']) ? trim($_GET['year']) : '';
$month = isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : '';
$org = isset($_GET['org']) && is_string($_GET['org']) ? trim($_GET['org']) : '';

$valid = filter_var($ip, FILTER_VALIDATE_IP) !== false;
$data = $valid ? reportSenderData($ip, $year, $month, $org) : ['available' => false];
$available = (bool)($data['available'] ?? false);

$filterQuery = http_build_query(array_filter([
    'year' => $year,
    'month' => $month,
    'org' => $org,
]));
$backUrl = '/trends.php' . ($filterQuery !== '' ? '?' . $filterQuery : '');

$summary = $data['summary'] ?? null;
$byDomain = $data['by_domain'] ?? [];
$reports = $data['reports'] ?? [];

// Pass percentage of a total.
function passRate(int $pass, int $total): int
{
    return $total > 0 ? (int)round($pass / $total * 100) : 0;
}

?>
<?php
renderHead('Sender ' . $ip);
renderHero('Sender details', $ip !== '' ? $ip : 'Unknown IP', '', true);
?>

  <main class="container trends">
    <div class="breadcrumb">
      <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>">&larr; Back to trends</a>
    </div>

    <?php if (!$available || !$valid): ?>
      <div class="empty">
        <h2>No data for this sender</h2>
        <p><?= $valid ? 'No records were found for this source IP in the selected range.' : 'The provided source IP is not valid.' ?></p>
      </div>
    <?php else: ?>
      <?php if ($summary): ?>
      <section class="stat-cards">
        <div class="stat-card"><strong><?= number_format((int)$summary['total']) ?></strong><span>Messages</span></div>
        <div class="stat-card <?= $summary['pass_rate'] >= 98 ? 'is-good' : ($summary['pass_rate'] >= 90 ? 'is-mid' : 'is-warn') ?>"><strong><?= htmlspecialchars((string)$summary['pass_rate'], ENT_QUOTES) ?>%</strong><span>Pass rate</span></div>
        <div class="stat-card"><strong><?= number_format((int)$summary['pass']) ?></strong><span>Passing</span></div>
        <div class="stat-card <?= (int)$summary['fail'] > 0 ? 'is-warn' : '' ?>"><strong><?= number_format((int)$summary['fail']) ?></strong><span>Failing</span></div>
        <div class="stat-card"><strong><?= number_format((int)$summary['domains']) ?></strong><span>Domains</span></div>
      </section>
      <?php endif; ?>

      <section class="card chart-card">
        <div class="card-header">
          <h2>Messages by alignment</h2>
        </div>
        <div class="chart-legend">
          <span class="legend-item"><span class="legend-swatch swatch-full"></span>Full</span>
          <span class="legend-item"><span class="legend-swatch swatch-dkim"></span>DKIM only</span>
          <span class="legend-item"><span class="legend-swatch swatch-spf"></span>SPF only</span>
          <span class="legend-item"><span class="legend-swatch swatch-fail"></span>Fail</span>
        </div>
        <div class="chart-wrap" id="chart-wrap"></div>
      </section>

      <section class="card">
        <h2>By domain</h2>
        <div class="table-scroll">
          <table class="reports senders-table">
            <thead>
              <tr><th>Domain</th><th class="num">Messages</th><th class="num">Pass</th><th class="num">Fail</th><th>Pass rate</th></tr>
            </thead>
            <tbody>
              <?php foreach ($byDomain as $d): $pr = passRate((int)$d['pass'], (int)$d['total']); ?>
                <tr>
                  <td><?= $d['domain'] !== '' ? htmlspecialchars($d['domain'], ENT_QUOTES) : '<span class="muted">(none)</span>' ?></td>
                  <td class="num"><?= number_format((int)$d['total']) ?></td>
                  <td class="num"><?= number_format((int)$d['pass']) ?></td>
                  <td class="num"><?= number_format((int)$d['fail']) ?></td>
                  <td>
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

      <section class="card">
        <h2>Reports from this sender</h2>
        <div class="table-scroll">
          <table class="reports senders-table">
            <thead>
              <tr><th>Start</th><th>End</th><th>Org</th><th>Domain</th><th class="num">Messages</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['begin_ts'] !== null ? date('Y-m-d', $r['begin_ts']) : '', ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($r['end_ts'] !== null ? date('Y-m-d', $r['end_ts']) : '', ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($r['org'], ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($r['domain'], ENT_QUOTES) ?></td>
                  <td class="num"><?= number_format((int)$r['total']) ?></td>
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

  <?php if ($available && $valid): ?>
  <script>
    window.SENDER_TIMESERIES = <?= json_encode($data['timeseries'] ?? [], JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="/js/chart.js" defer></script>
  <script src="/js/sender.js" defer></script>
  <?php endif; ?>
  <script src="/js/update-check.js" defer></script>
</body>
</html>
