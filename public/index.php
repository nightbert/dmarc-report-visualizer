<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$reportData = reportSummariesData();
$summaries = $reportData['summaries'] ?? [];
$total = (int)($reportData['total'] ?? 0);
$yearOptions = $reportData['year_options'] ?? [];
$monthOptions = $reportData['month_options'] ?? [];
$orgOptions = $reportData['org_options'] ?? [];
$tokenIndex = $reportData['token_index'] ?? [];
$repoUrl = appRepoUrl();
$version = appVersion();
$releaseUrl = appReleaseUrl($repoUrl, $version);

?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMARC Report Visualizer</title>
    <link rel="stylesheet" href="/style.css">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg%20xmlns=%27http://www.w3.org/2000/svg%27%20viewBox=%270%200%2016%2016%27%3E%3Crect%20width=%2716%27%20height=%2716%27%20rx=%274%27%20fill=%27%2322577a%27/%3E%3Cpath%20d=%27M4%208h8v2H4z%27%20fill=%27%23fff%27/%3E%3C/svg%3E">
  </head>
<body>
  <header class="hero">
    <div class="hero-content">
      <h1>DMARC Report Visualizer</h1>
      <div class="stat">Total reports: <strong id="total-reports"><?= $total ?></strong></div>
    </div>
  </header>

  <main class="container layout">
    <section class="main">
      <div class="filters card">
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
          <label>&nbsp;</label>
          <div class="filter-actions">
            <button type="button" id="clear-filters">Clear filters</button>
            <button type="button" id="status-reload">Reload</button>
          </div>
        </div>
      </div>

      <?php if ($total === 0): ?>
        <div class="empty">
          <h2>No reports found yet</h2>
          <p>Drop XML, XML.GZ, or ZIP files into <strong>/data/inbox</strong> to get started.</p>
        </div>
      <?php else: ?>
        <table class="reports">
          <thead>
            <tr>
              <th>Date range</th>
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
            <td><?= htmlspecialchars($summary['date_range'] ?: date('Y-m-d', $summary['timestamp']), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($summary['org'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($summary['domain'], ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($summary['report_id'], ENT_QUOTES) ?></td>
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
        <div class="pagination" id="pagination">
          <button type="button" class="page-btn" id="page-prev">Previous</button>
          <div class="page-info" id="page-info"></div>
          <button type="button" class="page-btn" id="page-next">Next</button>
        </div>
      <?php endif; ?>
    </section>

    <aside class="sidebar">
      <section class="card">
        <h2>Upload</h2>
        <form id="upload-form" class="upload-form">
          <div class="dropzone" id="dropzone">
            <input type="file" name="files[]" id="file-input" multiple accept=".xml,.zip,.gz" />
            <div class="dropzone-content">
              <span class="dropzone-title">Drop files here</span>
              <span class="dropzone-sub">or click to choose (XML, XML.GZ, ZIP)</span>
            </div>
          </div>
        </form>
        <div class="sidebar-divider"></div>

        <h2>Fetch Status</h2>
        <div id="status-list" class="status-list">
          <div class="muted">No activity yet.</div>
        </div>
      </section>
    </aside>
  </main>

  <div class="drag-overlay" id="drag-overlay">
    <div class="drag-overlay-card">
      <div class="drag-overlay-title">Drop to upload</div>
      <div class="drag-overlay-sub">XML, XML.GZ, or ZIP</div>
    </div>
  </div>

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
    let reportTokenIndex = <?= json_encode($tokenIndex, JSON_UNESCAPED_SLASHES) ?>;
    const statusList = document.getElementById('status-list');
    const uploadForm = document.getElementById('upload-form');
    const fileInput = document.getElementById('file-input');
    const statusReload = document.getElementById('status-reload');
    const dropzone = document.getElementById('dropzone');
    const dragOverlay = document.getElementById('drag-overlay');
    const totalReports = document.getElementById('total-reports');
    const dismissedKey = 'dismissedFetchStatus';
    const dismissedStatus = new Set();
    const seenStatus = new Set();
    let statusPollInFlight = false;
    let reportsPollInFlight = false;
    let latestStatusSequence = 0;
    let latestStatusUpdatedAt = 0;
    let doneSignature = '';

    function escapeHtml(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function loadDismissedStatus() {
      try {
        const raw = window.localStorage.getItem(dismissedKey);
        if (!raw) {
          return;
        }
        const items = JSON.parse(raw);
        if (Array.isArray(items)) {
          items.forEach((item) => dismissedStatus.add(String(item)));
        }
      } catch (err) {
        // ignore storage errors
      }
    }

    function persistDismissedStatus() {
      try {
        window.localStorage.setItem(dismissedKey, JSON.stringify(Array.from(dismissedStatus)));
      } catch (err) {
        // ignore storage errors
      }
    }

    function statusKey(item) {
      return String(item && item.name ? item.name : 'unknown');
    }

    function itemVersion(item) {
      const sequence = Number(item && item.sequence ? item.sequence : 0);
      if (Number.isFinite(sequence) && sequence > 0) {
        return sequence;
      }
      const updatedAt = Number(item && item.updated_at ? item.updated_at : 0);
      return Number.isFinite(updatedAt) ? updatedAt : 0;
    }

    function renderStatus(items) {
      if (!statusList) {
        return;
      }
      const visibleItems = (items || []).filter((item) => !dismissedStatus.has(statusKey(item)));
      if (!visibleItems || visibleItems.length === 0) {
        statusList.innerHTML = '<div class="muted">No activity yet.</div>';
        return;
      }

      const orderedItems = visibleItems
        .map((item, index) => ({
          item,
          index,
          seen: seenStatus.has(statusKey(item)),
        }))
        .sort((a, b) => {
          if (a.seen === b.seen) {
            return a.index - b.index;
          }
          return a.seen ? 1 : -1;
        })
        .map((entry) => entry.item);

      statusList.innerHTML = orderedItems.map((item, index) => {
        const name = String(item && item.name ? item.name : 'unknown');
        const stage = String(item && item.stage ? item.stage : '');
        const message = String(item && item.message ? item.message : '');
        const rawProgress = Number(item && item.progress ? item.progress : 0);
        const progress = Math.max(0, Math.min(100, Number.isFinite(rawProgress) ? rawProgress : 0));
        const key = statusKey(item);
        const viewToken = reportTokenIndex && Object.prototype.hasOwnProperty.call(reportTokenIndex, name)
          ? reportTokenIndex[name]
          : '';
        const viewLink = stage === 'done' && viewToken
          ? `<a class="status-link" href="/report.php?f=${encodeURIComponent(viewToken)}">View</a>`
          : '';
        const isError = stage === 'error' || stage === 'ignored' || stage === 'duplicate';
        const isDone = stage === 'done';
        const statusClass = isDone ? 'status-item success' : (isError ? 'status-item danger' : 'status-item info');
        const animationClass = seenStatus.has(key) ? '' : ' status-item--new';
        return `
          <div class="${statusClass}${animationClass}" style="animation-delay:${index * 40}ms" data-status-key="${escapeHtml(key)}">
            <button type="button" class="status-dismiss" aria-label="Dismiss item" title="Dismiss">×</button>
            <div class="status-header">
              <span class="status-name" title="${escapeHtml(name)}">${escapeHtml(name)}</span>
              <span class="status-stage">${escapeHtml(stage)} ${viewLink}</span>
            </div>
            <div class="progress">
              <div class="progress-bar" style="width:${progress}%"></div>
            </div>
            <div class="status-message">${escapeHtml(message)}</div>
          </div>
        `;
      }).join('');

      orderedItems.forEach((item) => {
        seenStatus.add(statusKey(item));
      });
    }

    const filterYear = document.getElementById('filter-year');
    const filterMonth = document.getElementById('filter-month');
    const filterOrg = document.getElementById('filter-org');
    const clearFilters = document.getElementById('clear-filters');
    const filtersReady = filterYear && filterMonth && filterOrg;
    const pageSize = 20;
    let currentPage = 1;
    const pagePrev = document.getElementById('page-prev');
    const pageNext = document.getElementById('page-next');
    const pageInfo = document.getElementById('page-info');

    async function deleteReport(token) {
      if (!token) {
        return false;
      }
      const response = await fetch('/delete-report.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token }),
      });
      if (!response.ok) {
        return false;
      }
      const data = await response.json();
      return !!data.ok;
    }

    function bindDeleteButtons() {
      const buttons = document.querySelectorAll('.delete-report');
      buttons.forEach((button) => {
        button.addEventListener('click', async () => {
          const token = button.dataset.token;
          const row = button.closest('tr');
          const confirmed = window.confirm('Delete this report?');
          if (!confirmed) {
            return;
          }
          button.disabled = true;
          const ok = await deleteReport(token);
          if (ok && row) {
            row.remove();
            applyFilters();
            return;
          }
          button.disabled = false;
          window.alert('Delete failed.');
        });
      });
    }

    function syncSelectOptions(select, values, options = {}) {
      if (!select) {
        return;
      }
      const sortAlpha = !!options.sortAlpha;
      const current = select.value;
      const normalizedValues = (values || [])
        .map((value) => String(value || ''))
        .filter((value) => value !== '');
      if (sortAlpha) {
        normalizedValues.sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
      }

      select.innerHTML = '';
      const allOption = document.createElement('option');
      allOption.value = '';
      allOption.textContent = 'All';
      select.appendChild(allOption);

      normalizedValues.forEach((stringValue) => {
        const option = document.createElement('option');
        option.value = stringValue;
        option.textContent = stringValue;
        select.appendChild(option);
      });

      const hasCurrent = Array.from(select.options).some((option) => option.value === current);
      if (hasCurrent) {
        select.value = current;
      } else {
        select.value = '';
      }
    }

    function renderReports(data) {
      if (!data || typeof data !== 'object') {
        return;
      }

      const nextTokenIndex = data.token_index && typeof data.token_index === 'object'
        ? data.token_index
        : {};
      reportTokenIndex = nextTokenIndex;

      if (totalReports) {
        totalReports.textContent = String(Number(data.total || 0));
      }

      if (filtersReady) {
        syncSelectOptions(filterYear, data.year_options || []);
        syncSelectOptions(filterMonth, data.month_options || []);
        syncSelectOptions(filterOrg, data.org_options || [], { sortAlpha: true });
      }

      const tableBody = document.querySelector('.reports tbody');
      const summaries = Array.isArray(data.summaries) ? data.summaries : [];
      if (!tableBody) {
        if (summaries.length > 0) {
          window.location.reload();
        }
        return;
      }

      tableBody.innerHTML = summaries.map((summary) => {
        const year = String(summary && summary.year ? summary.year : '');
        const month = String(summary && summary.month ? summary.month : '');
        const org = String(summary && summary.org ? summary.org : '');
        const token = String(summary && summary.token ? summary.token : '');
        const timestamp = Number(summary && summary.timestamp ? summary.timestamp : 0);
        const fallbackDate = timestamp > 0 ? new Date(timestamp * 1000).toISOString().slice(0, 10) : '';
        const dateRange = String(summary && summary.date_range ? summary.date_range : fallbackDate);
        const domain = String(summary && summary.domain ? summary.domain : '');
        const reportId = String(summary && summary.report_id ? summary.report_id : '');
        const records = Number(summary && summary.records ? summary.records : 0);
        const safeRecords = Number.isFinite(records) ? records : 0;
        const action = token !== ''
          ? `<a href="/report.php?f=${encodeURIComponent(token)}">View</a>`
          : '<span class="muted">Unavailable</span>';

        return `
          <tr data-year="${escapeHtml(year)}" data-month="${escapeHtml(month)}" data-org="${escapeHtml(org)}" data-token="${escapeHtml(token)}">
            <td>${escapeHtml(dateRange)}</td>
            <td>${escapeHtml(org)}</td>
            <td>${escapeHtml(domain)}</td>
            <td>${escapeHtml(reportId)}</td>
            <td>${safeRecords}</td>
            <td>${action}</td>
          </tr>
        `;
      }).join('');

      bindDeleteButtons();
      applyFilters();
    }

    function buildDoneSignature(items) {
      return (items || [])
        .filter((item) => item && item.stage === 'done')
        .map((item) => `${item.name || 'unknown'}:${itemVersion(item)}`)
        .sort()
        .join('|');
    }

    function applyFilters() {
      if (!filtersReady) {
        return;
      }
      const year = filterYear.value;
      const month = filterMonth.value;
      const org = filterOrg.value;
      const rows = Array.from(document.querySelectorAll('.reports tbody tr'));
      const filteredRows = rows.filter((row) => {
        const matchYear = !year || row.dataset.year === year;
        const matchMonth = !month || row.dataset.month === month;
        const matchOrg = !org || row.dataset.org === org;
        return matchYear && matchMonth && matchOrg;
      });

      const total = filteredRows.length;
      const maxPage = Math.max(1, Math.ceil(total / pageSize));
      currentPage = Math.min(currentPage, maxPage);
      const start = (currentPage - 1) * pageSize;
      const end = start + pageSize;

      rows.forEach((row) => {
        row.style.display = 'none';
      });
      filteredRows.slice(start, end).forEach((row) => {
        row.style.display = '';
      });

      if (pageInfo) {
        const from = total === 0 ? 0 : start + 1;
        const to = Math.min(end, total);
        pageInfo.textContent = `Showing ${from}-${to} of ${total}`;
      }
      if (pagePrev) {
        pagePrev.disabled = currentPage <= 1;
      }
      if (pageNext) {
        pageNext.disabled = currentPage >= maxPage;
      }
      const pagination = document.getElementById('pagination');
      if (pagination) {
        pagination.style.display = total > pageSize ? 'flex' : 'none';
      }
    }

    async function clearCompletedStatus() {
      try {
        await fetch('/clear-status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          cache: 'no-store',
          body: JSON.stringify({ mode: 'completed' }),
        });
      } catch (err) {
        // ignore transient failures
      }
    }

    async function refreshReports() {
      if (reportsPollInFlight) {
        return;
      }
      reportsPollInFlight = true;
      try {
        const response = await fetch(`/reports.php?t=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) {
          return;
        }
        const data = await response.json();
        renderReports(data);
      } catch (err) {
        // ignore transient failures
      } finally {
        reportsPollInFlight = false;
      }
    }

    async function refreshStatus() {
      if (statusPollInFlight) {
        return;
      }
      statusPollInFlight = true;
      try {
        const response = await fetch(`/status.php?t=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) {
          return;
        }
        const data = await response.json();
        const sequence = Number(data && data.sequence ? data.sequence : 0);
        const updatedAt = Number(data && data.updated_at ? data.updated_at : 0);

        if (Number.isFinite(sequence) && sequence > 0) {
          if (sequence < latestStatusSequence) {
            return;
          }
          latestStatusSequence = sequence;
        } else if (Number.isFinite(updatedAt) && updatedAt < latestStatusUpdatedAt) {
          return;
        }

        if (Number.isFinite(updatedAt)) {
          latestStatusUpdatedAt = Math.max(latestStatusUpdatedAt, updatedAt);
        }

        const items = Array.isArray(data.items) ? data.items : [];
        renderStatus(items);

        const nextDoneSignature = buildDoneSignature(items);
        if (nextDoneSignature !== doneSignature) {
          doneSignature = nextDoneSignature;
          if (doneSignature !== '') {
            refreshReports();
          }
        }
      } catch (err) {
        // ignore transient failures
      } finally {
        statusPollInFlight = false;
      }
    }

    if (statusList) {
      statusList.addEventListener('click', (event) => {
        const button = event.target.closest('.status-dismiss');
        if (!button) {
          return;
        }
        const item = button.closest('.status-item');
        if (item) {
          const key = item.dataset.statusKey;
          if (key) {
            dismissedStatus.add(key);
            persistDismissedStatus();
          }
          item.remove();
          if (!statusList.querySelector('.status-item')) {
            statusList.innerHTML = '<div class="muted">No activity yet.</div>';
          }
        }
      });
    }

    if (filtersReady) {
      filterYear.addEventListener('change', () => {
        currentPage = 1;
        applyFilters();
      });
      filterMonth.addEventListener('change', () => {
        currentPage = 1;
        applyFilters();
      });
      filterOrg.addEventListener('change', () => {
        currentPage = 1;
        applyFilters();
      });
    }

    if (clearFilters && filtersReady) {
      clearFilters.addEventListener('click', () => {
        filterYear.value = '';
        filterMonth.value = '';
        filterOrg.value = '';
        currentPage = 1;
        applyFilters();
      });
    }

    if (statusReload) {
      statusReload.addEventListener('click', async () => {
        await clearCompletedStatus();
        window.location.reload();
      });
    }

    if (pagePrev) {
      pagePrev.addEventListener('click', () => {
        currentPage = Math.max(1, currentPage - 1);
        applyFilters();
      });
    }

    if (pageNext) {
      pageNext.addEventListener('click', () => {
        currentPage += 1;
        applyFilters();
      });
    }

    const maxBatchSize = 5;
    const uploadQueue = [];
    let isUploading = false;

    async function uploadBatch(files) {
      const formData = new FormData();
      for (const file of files) {
        formData.append('files[]', file, file.name);
      }

      try {
        const response = await fetch('/upload.php', {
          method: 'POST',
          body: formData,
        });
        const data = await response.json();
        if (response.ok) {
          const results = Array.isArray(data && data.results) ? data.results : [];
          const hasSuccessfulUpload = results.some((item) => item && item.status === 'ok');
          refreshStatus();
          if (hasSuccessfulUpload) {
            refreshReports();
            window.setTimeout(refreshReports, 1500);
            window.setTimeout(refreshReports, 6000);
          }
          return;
        }
        console.warn('Upload failed.', data && data.error ? data.error : '');
      } catch (err) {
        console.warn('Upload failed.', err);
      }
    }

    async function processUploadQueue() {
      if (isUploading) {
        return;
      }
      if (!fileInput) {
        return;
      }
      isUploading = true;
      fileInput.disabled = true;
      while (uploadQueue.length > 0) {
        const batch = uploadQueue.splice(0, maxBatchSize);
        await uploadBatch(batch);
      }
      fileInput.value = '';
      fileInput.disabled = false;
      isUploading = false;
    }

    function enqueueUploads(files) {
      if (!files || files.length === 0) {
        return;
      }
      uploadQueue.push(...files);
      processUploadQueue();
    }

    if (uploadForm) {
      uploadForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!fileInput) {
          return;
        }
        enqueueUploads(Array.from(fileInput.files || []));
      });
    }

    function triggerUpload(files) {
      if (files && files.length) {
        enqueueUploads(Array.from(files));
        return;
      }
      if (!uploadForm) {
        return;
      }
      const submitEvent = new Event('submit', { cancelable: true });
      uploadForm.dispatchEvent(submitEvent);
    }

    if (fileInput) {
      fileInput.addEventListener('change', () => {
        if (!fileInput.files || fileInput.files.length === 0) {
          return;
        }
        triggerUpload();
      });
    }

    let dragDepth = 0;
    function showDragOverlay() {
      if (dragOverlay) {
        dragOverlay.classList.add('is-active');
      }
    }

    function hideDragOverlay() {
      if (dragOverlay) {
        dragOverlay.classList.remove('is-active');
      }
    }

    window.addEventListener('dragenter', (event) => {
      event.preventDefault();
      dragDepth += 1;
      showDragOverlay();
    });

    window.addEventListener('dragover', (event) => {
      event.preventDefault();
      showDragOverlay();
    });

    window.addEventListener('dragleave', (event) => {
      event.preventDefault();
      dragDepth = Math.max(0, dragDepth - 1);
      if (dragDepth === 0) {
        hideDragOverlay();
      }
    });

    window.addEventListener('drop', (event) => {
      event.preventDefault();
      dragDepth = 0;
      hideDragOverlay();
      const files = event.dataTransfer && event.dataTransfer.files;
      if (!files || files.length === 0) {
        return;
      }
      triggerUpload(files);
    });

    loadDismissedStatus();
    clearCompletedStatus().finally(() => {
      refreshStatus();
    });
    refreshReports();
    setInterval(refreshStatus, 3000);
    applyFilters();
    bindDeleteButtons();
  </script>
</body>
</html>
