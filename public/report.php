<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_layout.php';

$root = reportsRoot();
$token = $_GET['f'] ?? '';
$filePath = $token !== '' ? resolveFileToken($root, $token) : null;

if ($filePath === null) {
    http_response_code(404);
    echo 'Report not found.';
    exit;
}

$content = @file_get_contents($filePath);
$xml = $content !== false ? loadXml($content) : null;

$summary = $xml ? parseReportSummary($filePath) : [
    'org' => 'Unknown',
    'domain' => '',
    'report_id' => '',
    'date_range' => '',
    'records' => 0,
];

$records = [];
$recordHeaders = [];
$fieldPresence = [];
if ($xml) {
    $recordNodes = $xml->xpath('//*[local-name()="record"]');
    if (is_array($recordNodes)) {
        foreach ($recordNodes as $record) {
            $sourceIp = xmlValue($record, './*[local-name()="row"]/*[local-name()="source_ip"]');
            $count = xmlValue($record, './*[local-name()="row"]/*[local-name()="count"]');
            $disposition = xmlValue($record, './*[local-name()="row"]/*[local-name()="policy_evaluated"]/*[local-name()="disposition"]');
            $dkim = xmlValue($record, './*[local-name()="row"]/*[local-name()="policy_evaluated"]/*[local-name()="dkim"]');
            $spf = xmlValue($record, './*[local-name()="row"]/*[local-name()="policy_evaluated"]/*[local-name()="spf"]');
            $headerFrom = xmlValue($record, './*[local-name()="identifiers"]/*[local-name()="header_from"]');
            $envelopeTo = xmlValue($record, './*[local-name()="identifiers"]/*[local-name()="envelope_to"]');

            $spfDomains = xmlValues($record, './*[local-name()="auth_results"]/*[local-name()="spf"]/*[local-name()="domain"]');
            $spfResults = xmlValues($record, './*[local-name()="auth_results"]/*[local-name()="spf"]/*[local-name()="result"]');
            $dkimDomains = xmlValues($record, './*[local-name()="auth_results"]/*[local-name()="dkim"]/*[local-name()="domain"]');
            $dkimSelectors = xmlValues($record, './*[local-name()="auth_results"]/*[local-name()="dkim"]/*[local-name()="selector"]');
            $dkimResults = xmlValues($record, './*[local-name()="auth_results"]/*[local-name()="dkim"]/*[local-name()="result"]');

            $authSpfDomain = $spfDomains[0] ?? '';
            $authSpfResult = $spfResults[0] ?? '';
            $authDkimDomain = $dkimDomains[0] ?? '';
            $authDkimSelector = $dkimSelectors[0] ?? '';
            $authDkimResult = $dkimResults[0] ?? '';

            $authSpfEntries = [];
            $spfEntryCount = max(count($spfDomains), count($spfResults));
            for ($i = 0; $i < $spfEntryCount; $i++) {
                $domain = $spfDomains[$i] ?? '';
                $result = $spfResults[$i] ?? '';
                if ($domain === '' && $result === '') {
                    continue;
                }
                $authSpfEntries[] = [
                    'domain' => $domain,
                    'result' => $result,
                ];
            }

            $authDkimEntries = [];
            $dkimEntryCount = max(count($dkimDomains), count($dkimSelectors), count($dkimResults));
            for ($i = 0; $i < $dkimEntryCount; $i++) {
                $domain = $dkimDomains[$i] ?? '';
                $selector = $dkimSelectors[$i] ?? '';
                $result = $dkimResults[$i] ?? '';
                if ($domain === '' && $selector === '' && $result === '') {
                    continue;
                }
                $authDkimEntries[] = [
                    'domain' => $domain,
                    'selector' => $selector,
                    'result' => $result,
                ];
            }

            $records[] = [
                'source_ip' => $sourceIp,
                'count' => $count,
                'disposition' => $disposition,
                'dkim' => $dkim,
                'spf' => $spf,
                'header_from' => $headerFrom,
                'envelope_to' => $envelopeTo,
                'auth_spf_domain' => $authSpfDomain,
                'auth_spf_result' => $authSpfResult,
                'auth_dkim_domain' => $authDkimDomain,
                'auth_dkim_selector' => $authDkimSelector,
                'auth_dkim_result' => $authDkimResult,
                'auth_spf_entries' => $authSpfEntries,
                'auth_dkim_entries' => $authDkimEntries,
                'auth_spf_count' => count($authSpfEntries),
                'auth_dkim_count' => count($authDkimEntries),
            ];

            // A field counts as present when any record carries it: reporters
            // fill them per record, so the first one decides nothing.
            $fieldPresence['source_ip'] = !empty($fieldPresence['source_ip']) || $sourceIp !== '';
            $fieldPresence['count'] = !empty($fieldPresence['count']) || $count !== '';
            $fieldPresence['disposition'] = !empty($fieldPresence['disposition']) || $disposition !== '';
            $fieldPresence['dkim'] = !empty($fieldPresence['dkim']) || $dkim !== '';
            $fieldPresence['spf'] = !empty($fieldPresence['spf']) || $spf !== '';
            $fieldPresence['header_from'] = !empty($fieldPresence['header_from']) || $headerFrom !== '';
            $fieldPresence['envelope_to'] = !empty($fieldPresence['envelope_to']) || $envelopeTo !== '';
            $fieldPresence['auth_spf'] = !empty($fieldPresence['auth_spf']) || $authSpfDomain !== '' || $authSpfResult !== '';
            $fieldPresence['auth_dkim'] = !empty($fieldPresence['auth_dkim']) || $authDkimDomain !== '' || $authDkimResult !== '' || $authDkimSelector !== '';
            $fieldPresence['auth_spf_multi'] = !empty($fieldPresence['auth_spf_multi']) || count($authSpfEntries) > 1;
            $fieldPresence['auth_dkim_multi'] = !empty($fieldPresence['auth_dkim_multi']) || count($authDkimEntries) > 1;
        }
    }
}

$recordHeaders = [
    'source_ip' => ['label' => 'Source IP', 'show' => !empty($fieldPresence['source_ip'])],
    'count' => ['label' => 'Count', 'show' => !empty($fieldPresence['count'])],
    'disposition' => ['label' => 'Disposition', 'show' => !empty($fieldPresence['disposition'])],
    'dkim' => ['label' => 'DKIM', 'show' => !empty($fieldPresence['dkim'])],
    'spf' => ['label' => 'SPF', 'show' => !empty($fieldPresence['spf'])],
    'header_from' => ['label' => 'Header From', 'show' => !empty($fieldPresence['header_from'])],
    'envelope_to' => ['label' => 'Envelope To', 'show' => !empty($fieldPresence['envelope_to'])],
    'auth_spf' => ['label' => 'Auth SPF', 'show' => !empty($fieldPresence['auth_spf'])],
    'auth_dkim' => ['label' => 'Auth DKIM', 'show' => !empty($fieldPresence['auth_dkim'])],
    'auth_spf_multi' => ['label' => 'Auth SPF (multi)', 'show' => !empty($fieldPresence['auth_spf_multi'])],
    'auth_dkim_multi' => ['label' => 'Auth DKIM (multi)', 'show' => !empty($fieldPresence['auth_dkim_multi'])],
];
// Render a pass/fail value as a colored badge.
function badge(string $value): string
{
    $clean = trim($value);
    $lower = strtolower($clean);
    if ($lower === 'pass' || $lower === 'fail') {
        return '<span class="badge ' . $lower . '">' . htmlspecialchars($clean, ENT_QUOTES) . '</span>';
    }

    return htmlspecialchars($clean, ENT_QUOTES);
}

?>
<?php
renderHead('Report Details');
renderHero();
renderSubHero('Report details', $summary['report_id'] !== '' ? $summary['report_id'] : basename($filePath), '/', 'Back to reports');
?>
  <main class="container">
    <section class="panel">
      <div class="panel-head">
        <h2>Overview</h2>
        <form id="delete-form" class="inline-form" method="post" action="/delete-report.php">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
          <button type="submit" class="btn-danger btn-small">Delete</button>
        </form>
      </div>
      <div class="grid">
        <div>
          <span class="label">Org</span>
          <p><?= htmlspecialchars($summary['org'], ENT_QUOTES) ?></p>
        </div>
        <div>
          <span class="label">Domain</span>
          <p><?= htmlspecialchars($summary['domain'], ENT_QUOTES) ?></p>
        </div>
        <div>
          <span class="label">Date range</span>
          <p><?= htmlspecialchars($summary['date_range'], ENT_QUOTES) ?></p>
        </div>
        <div>
          <span class="label">Records</span>
          <p><?= (int)$summary['records'] ?></p>
        </div>
        <div>
          <span class="label">File</span>
          <p><?= htmlspecialchars(basename($filePath), ENT_QUOTES) ?></p>
        </div>
      </div>
    </section>

    <?php if (!empty($records)): ?>
      <section class="panel">
        <div class="panel-head">
          <h2>Records</h2>
          <span class="panel-note">Visible columns are inferred from this report's schema</span>
        </div>
        <div class="table-scroll">
        <table class="reports" data-sortable>
          <thead>
            <tr>
              <?php foreach ($recordHeaders as $key => $config): ?>
                <?php if ($config['show']): ?>
                  <th<?= $key === 'count' ? ' data-sort-default="desc"' : '' ?>><?= htmlspecialchars($config['label'], ENT_QUOTES) ?></th>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $row): ?>
              <tr>
                <?php foreach ($recordHeaders as $key => $config): ?>
                  <?php if (!$config['show']): ?>
                    <?php continue; ?>
                  <?php endif; ?>
                  <?php
                    // Columns whose cell text is not sortable on its own get an explicit key.
                    $sortKey = null;
                    if ($key === 'auth_spf_multi') {
                        $sortKey = (string)(int)$row['auth_spf_count'];
                    } elseif ($key === 'auth_dkim_multi') {
                        $sortKey = (string)(int)$row['auth_dkim_count'];
                    }
                  ?>
                  <td<?= $sortKey !== null ? ' data-sort="' . htmlspecialchars($sortKey, ENT_QUOTES) . '"' : '' ?>>
                    <?php if ($key === 'source_ip'): ?>
                      <?= htmlspecialchars($row['source_ip'], ENT_QUOTES) ?>
                    <?php elseif ($key === 'count'): ?>
                      <?= htmlspecialchars($row['count'], ENT_QUOTES) ?>
                    <?php elseif ($key === 'disposition'): ?>
                      <?= htmlspecialchars($row['disposition'], ENT_QUOTES) ?>
                    <?php elseif ($key === 'dkim'): ?>
                      <?= badge($row['dkim']) ?>
                    <?php elseif ($key === 'spf'): ?>
                      <?= badge($row['spf']) ?>
                    <?php elseif ($key === 'header_from'): ?>
                      <?= htmlspecialchars($row['header_from'], ENT_QUOTES) ?>
                    <?php elseif ($key === 'envelope_to'): ?>
                      <?= htmlspecialchars($row['envelope_to'], ENT_QUOTES) ?>
                    <?php elseif ($key === 'auth_spf'): ?>
                      <?= htmlspecialchars($row['auth_spf_domain'], ENT_QUOTES) ?>
                      <?= badge($row['auth_spf_result']) ?>
                    <?php elseif ($key === 'auth_dkim'): ?>
                      <?= htmlspecialchars($row['auth_dkim_domain'], ENT_QUOTES) ?>
                      <?= htmlspecialchars($row['auth_dkim_selector'], ENT_QUOTES) ?>
                      <?= badge($row['auth_dkim_result']) ?>
                    <?php elseif ($key === 'auth_spf_multi'): ?>
                      <div class="multi-summary">
                        <span class="muted">SPF entries</span>
                        <strong><?= (int)$row['auth_spf_count'] ?></strong>
                      </div>
                      <details class="multi-details">
                        <summary>Details</summary>
                        <ul>
                          <?php foreach ($row['auth_spf_entries'] as $entry): ?>
                            <li>
                              <?= htmlspecialchars($entry['domain'], ENT_QUOTES) ?>
                              <?= badge($entry['result']) ?>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </details>
                    <?php elseif ($key === 'auth_dkim_multi'): ?>
                      <div class="multi-summary">
                        <span class="muted">DKIM entries</span>
                        <strong><?= (int)$row['auth_dkim_count'] ?></strong>
                      </div>
                      <details class="multi-details">
                        <summary>Details</summary>
                        <ul>
                          <?php foreach ($row['auth_dkim_entries'] as $entry): ?>
                            <li>
                              <?= htmlspecialchars($entry['domain'], ENT_QUOTES) ?>
                              <?php if ($entry['selector'] !== ''): ?>
                                <span class="muted">/</span><?= htmlspecialchars($entry['selector'], ENT_QUOTES) ?>
                              <?php endif; ?>
                              <?= badge($entry['result']) ?>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </details>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </section>
    <?php endif; ?>

    <section class="panel">
      <div class="panel-head"><h2>Raw XML</h2></div>
      <pre class="raw"><?= htmlspecialchars($content ?: '', ENT_QUOTES) ?></pre>
    </section>
  </main>

  <?php renderFooter(); ?>

  <script src="/js/sort-table.js" defer></script>
  <script src="/js/update-check.js" defer></script>
  <script src="/js/report.js" defer></script>
</body>
</html>
