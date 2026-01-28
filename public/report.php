<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

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

            $fieldPresence['source_ip'] = $fieldPresence['source_ip'] ?? ($sourceIp !== '');
            $fieldPresence['count'] = $fieldPresence['count'] ?? ($count !== '');
            $fieldPresence['disposition'] = $fieldPresence['disposition'] ?? ($disposition !== '');
            $fieldPresence['dkim'] = $fieldPresence['dkim'] ?? ($dkim !== '');
            $fieldPresence['spf'] = $fieldPresence['spf'] ?? ($spf !== '');
            $fieldPresence['header_from'] = $fieldPresence['header_from'] ?? ($headerFrom !== '');
            $fieldPresence['auth_spf'] = $fieldPresence['auth_spf'] ?? ($authSpfDomain !== '' || $authSpfResult !== '');
            $fieldPresence['auth_dkim'] = $fieldPresence['auth_dkim'] ?? ($authDkimDomain !== '' || $authDkimResult !== '' || $authDkimSelector !== '');
            $fieldPresence['auth_spf_multi'] = $fieldPresence['auth_spf_multi'] ?? (count($authSpfEntries) > 1);
            $fieldPresence['auth_dkim_multi'] = $fieldPresence['auth_dkim_multi'] ?? (count($authDkimEntries) > 1);
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
    'auth_spf' => ['label' => 'Auth SPF', 'show' => !empty($fieldPresence['auth_spf'])],
    'auth_dkim' => ['label' => 'Auth DKIM', 'show' => !empty($fieldPresence['auth_dkim'])],
    'auth_spf_multi' => ['label' => 'Auth SPF (multi)', 'show' => !empty($fieldPresence['auth_spf_multi'])],
    'auth_dkim_multi' => ['label' => 'Auth DKIM (multi)', 'show' => !empty($fieldPresence['auth_dkim_multi'])],
];
$repoUrl = appRepoUrl();
$version = appVersion();
$releaseUrl = appReleaseUrl($repoUrl, $version);

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
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Report Details</title>
  <link rel="stylesheet" href="/style.css">
</head>
<body>
  <main class="container">
    <div class="breadcrumb">
      <a href="/">&larr; Back to reports</a>
    </div>

    <section class="card">
      <div class="card-header">
        <h1>Report Details</h1>
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
          <span class="label">Report ID</span>
          <p><?= htmlspecialchars($summary['report_id'], ENT_QUOTES) ?></p>
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
      <section class="card">
        <h2>Records</h2>
        <p class="muted small">Visible columns are inferred from this report's schema.</p>
        <table class="reports">
          <thead>
            <tr>
              <?php foreach ($recordHeaders as $key => $config): ?>
                <?php if ($config['show']): ?>
                  <th><?= htmlspecialchars($config['label'], ENT_QUOTES) ?></th>
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
                  <td>
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
      </section>
    <?php endif; ?>

    <section class="card">
      <h2>Raw XML</h2>
      <pre class="raw"><?= htmlspecialchars($content ?: '', ENT_QUOTES) ?></pre>
    </section>
  </main>

  <footer class="site-footer">
    <div class="footer-content">
      <span>Author Marc Reinke</span>
      <?php if ($repoUrl !== ''): ?>
        <span class="footer-sep">•</span>
        <a href="<?= htmlspecialchars($repoUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">GitHub project</a>
      <?php endif; ?>
      <?php if ($releaseUrl !== '' && $version !== ''): ?>
        <span class="footer-sep">•</span>
        <a href="<?= htmlspecialchars($releaseUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">Version <?= htmlspecialchars($version, ENT_QUOTES) ?></a>
      <?php elseif ($version !== ''): ?>
        <span class="footer-sep">•</span>
        <span>Version <?= htmlspecialchars($version, ENT_QUOTES) ?></span>
      <?php endif; ?>
    </div>
  </footer>

  <script>
    const deleteForm = document.getElementById('delete-form');
    if (deleteForm) {
      deleteForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const confirmed = window.confirm('Are you sure?');
        if (!confirmed) {
          return;
        }
        const formData = new FormData(deleteForm);
        const response = await fetch('/delete-report.php', {
          method: 'POST',
          body: formData,
        });
        if (response.ok) {
          const data = await response.json();
          if (data && data.ok) {
            window.location.href = '/';
            return;
          }
        }
        window.alert('Deletion failed.');
      });
    }
  </script>
</body>
</html>
