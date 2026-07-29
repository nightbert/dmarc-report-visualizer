// Keep the sticky sidebar offset in sync with the actual header height.
(function () {
  const header = document.querySelector('.hero');
  if (!header) {
    return;
  }
  const apply = () => {
    document.documentElement.style.setProperty('--header-height', header.offsetHeight + 'px');
  };
  apply();
  if (window.ResizeObserver) {
    new ResizeObserver(apply).observe(header);
  }
  window.addEventListener('resize', apply);
})();

// Dashboard: live fetch status, report listing, filters, pagination and uploads.
(function () {
  const appInitial = window.APP_INITIAL || {};
  let reportTokenIndex = appInitial.tokenIndex || {};
  const initialReportState = {
    total: appInitial.total || 0,
    page: appInitial.page || 1,
    perPage: appInitial.perPage || 20,
    sort: appInitial.sort || 'start',
    dir: appInitial.dir === 'asc' ? 'asc' : 'desc',
  };
  const statusList = document.getElementById('status-list');
  const statusOverall = document.getElementById('status-overall');
  const statusOverallBar = document.getElementById('status-overall-bar');
  const statusOverallCount = document.getElementById('status-overall-count');
  const statusFilter = document.getElementById('status-filter');
  let statusFilterMode = 'all';
  let latestStatusItems = [];
  const uploadForm = document.getElementById('upload-form');
  const fileInput = document.getElementById('file-input');
  const fetchMailboxBtn = document.getElementById('fetch-mailbox');
  const mailboxLastFetch = document.getElementById('mailbox-last-fetch');
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

  const terminalStages = new Set(['done', 'error', 'ignored', 'duplicate']);

  function updateOverallProgress(items) {
    if (!statusOverall) {
      return;
    }
    // Reflect the whole queue regardless of the active filter.
    const queue = (items || []).filter((item) => !dismissedStatus.has(statusKey(item)));
    if (queue.length === 0) {
      statusOverall.hidden = true;
      return;
    }
    statusOverall.hidden = false;
    const finished = queue.filter((item) => terminalStages.has(String(item && item.stage ? item.stage : ''))).length;
    const pct = Math.round((finished / queue.length) * 100);
    if (statusOverallBar) {
      statusOverallBar.style.width = `${pct}%`;
      statusOverall.classList.toggle('is-complete', finished === queue.length);
    }
    if (statusOverallCount) {
      statusOverallCount.textContent = `${finished} / ${queue.length} · ${pct}%`;
    }
  }

  function renderStatus(items) {
    updateOverallProgress(items);
    if (!statusList) {
      return;
    }
    const visibleItems = (items || []).filter((item) => {
      if (dismissedStatus.has(statusKey(item))) {
        return false;
      }
      if (statusFilterMode === 'errors') {
        const stage = String(item && item.stage ? item.stage : '');
        if (stage !== 'error' && stage !== 'ignored' && stage !== 'duplicate') {
          return false;
        }
      }
      return true;
    });
    if (!visibleItems || visibleItems.length === 0) {
      const emptyMsg = statusFilterMode === 'errors' ? 'No errors.' : 'No activity yet.';
      statusList.innerHTML = `<div class="muted">${emptyMsg}</div>`;
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
          <div class="status-header">
            <span class="status-name" title="${escapeHtml(name)}">${escapeHtml(name)}</span>
            <span class="status-stage">${escapeHtml(stage)} ${viewLink}</span>
            <button type="button" class="status-dismiss" aria-label="Dismiss item" title="Dismiss">×</button>
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

  // Coarse "how long ago", so the label stays short enough to sit beside the
  // fetch button in the sidebar. The exact time goes in the tooltip.
  function relativeTime(ts) {
    const seconds = Math.max(0, Math.floor(Date.now() / 1000 - ts));
    if (seconds < 60) {
      return 'just now';
    }
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
      return minutes + 'm ago';
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
      return hours + 'h ago';
    }
    return Math.floor(hours / 24) + 'd ago';
  }

  function absoluteTime(ts) {
    const date = new Date(ts * 1000);
    const pad = (value) => String(value).padStart(2, '0');
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) +
      ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
  }

  function updateM365Info(info) {
    if (!mailboxLastFetch || !info) {
      return;
    }
    const value = mailboxLastFetch.querySelector('.mailbox-last-value');
    if (!value) {
      return;
    }
    const ts = Number(info.last_fetch_at || 0);
    const known = Number.isFinite(ts) && ts > 0;

    // Relative, because only a short value fits beside the fetch button in the
    // sidebar's width — the exact time is a hover away.
    value.textContent = known ? relativeTime(ts) : 'never';
    // A failed fetch is marked by colour rather than an "(error)" suffix.
    mailboxLastFetch.classList.toggle('is-error', info.result === 'error');

    const tooltip = [];
    if (known) {
      tooltip.push(absoluteTime(ts));
    }
    if (info.message) {
      tooltip.push(String(info.message));
    }
    mailboxLastFetch.title = tooltip.join(' — ');
  }

  const filterDomain = document.getElementById('filter-domain');
  const filterOrg = document.getElementById('filter-org');
  const filtersReady = filterDomain && filterOrg;
  const activeFilters = {
    range: initialReportState.range || '30d',
    org: initialReportState.org || '',
    domain: initialReportState.domain || '',
  };
  const pageSize = initialReportState.perPage || 20;
  let currentPage = initialReportState.page || 1;
  let currentTotal = initialReportState.total || 0;
  let currentSort = initialReportState.sort;
  let currentDir = initialReportState.dir;
  const pagePrev = document.getElementById('page-prev');
  const pageNext = document.getElementById('page-next');
  const pageInfo = document.getElementById('page-info');

  // The listing is paginated server-side, so sorting has to happen there too:
  // reordering only the visible page would be misleading.
  const reportsTable = document.getElementById('reports-table');

  function markSortHeaders() {
    if (!reportsTable || !reportsTable.tHead) {
      return;
    }
    Array.from(reportsTable.tHead.rows[0].cells).forEach((th) => {
      const key = th.dataset.sortKey;
      if (!key) {
        return;
      }
      const active = key === currentSort;
      th.classList.toggle('is-sorted', active);
      th.classList.toggle('is-sorted-desc', active && currentDir === 'desc');
      th.setAttribute('aria-sort', active ? (currentDir === 'desc' ? 'descending' : 'ascending') : 'none');
    });
  }

  function sortBy(key, defaultDir) {
    if (!key) {
      return;
    }
    if (key === currentSort) {
      currentDir = currentDir === 'asc' ? 'desc' : 'asc';
    } else {
      currentSort = key;
      currentDir = defaultDir === 'desc' ? 'desc' : 'asc';
    }
    currentPage = 1;
    markSortHeaders();
    loadPage();
  }

  if (reportsTable && reportsTable.tHead) {
    const head = reportsTable.tHead;
    head.addEventListener('click', (event) => {
      const th = event.target.closest('th');
      if (th) {
        sortBy(th.dataset.sortKey, th.dataset.sortDefault);
      }
    });
    head.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }
      const th = event.target.closest('th');
      if (th && th.dataset.sortKey) {
        event.preventDefault();
        sortBy(th.dataset.sortKey, th.dataset.sortDefault);
      }
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

    currentTotal = Number(data.total || 0);
    currentPage = Number(data.page || currentPage) || 1;
    if (data.sort) {
      currentSort = String(data.sort);
      currentDir = data.dir === 'asc' ? 'asc' : 'desc';
      markSortHeaders();
    }

    // The masthead figure is the whole archive, not the filtered page — an
    // ingest can change it, a filter cannot.
    if (totalReports && data.total_all !== undefined) {
      totalReports.textContent = Number(data.total_all).toLocaleString('en-US');
    }

    if (filtersReady) {
      syncSelectOptions(filterDomain, data.domain_options || [], { sortAlpha: true });
      syncSelectOptions(filterOrg, data.org_options || [], { sortAlpha: true });
    }

    // Scoped to the listing: the health panel above it holds tables too.
    const tableBody = document.querySelector('#reports-table tbody');
    const summaries = Array.isArray(data.summaries) ? data.summaries : [];
    if (!tableBody) {
      // The page was rendered empty server-side; reload once reports exist.
      if (summaries.length > 0) {
        window.location.reload();
      }
      return;
    }

    tableBody.innerHTML = summaries.map((summary) => {
      const org = String(summary && summary.org ? summary.org : '');
      const token = String(summary && summary.token ? summary.token : '');
      const timestamp = Number(summary && summary.timestamp ? summary.timestamp : 0);
      const fmtDate = (ts) => (ts > 0 ? new Date(ts * 1000).toISOString().slice(0, 10) : '');
      const fallbackDate = fmtDate(timestamp);
      const beginTs = Number(summary && summary.begin_ts ? summary.begin_ts : 0);
      const endTs = Number(summary && summary.end_ts ? summary.end_ts : 0);
      const startDate = beginTs > 0 ? fmtDate(beginTs) : fallbackDate;
      const endDate = endTs > 0 ? fmtDate(endTs) : '';
      const domain = String(summary && summary.domain ? summary.domain : '');
      const reportId = String(summary && summary.report_id ? summary.report_id : '');
      const records = Number(summary && summary.records ? summary.records : 0);
      const safeRecords = Number.isFinite(records) ? records : 0;
      const action = token !== ''
        ? `<a href="/report.php?f=${encodeURIComponent(token)}">View</a>`
        : '<span class="muted">Unavailable</span>';

      return `
        <tr>
          <td>${escapeHtml(startDate)}</td>
          <td>${escapeHtml(endDate)}</td>
          <td>${escapeHtml(org)}</td>
          <td>${escapeHtml(domain)}</td>
          <td><span class="truncate" title="${escapeHtml(reportId)}">${escapeHtml(reportId)}</span></td>
          <td>${safeRecords}</td>
          <td>${action}</td>
        </tr>
      `;
    }).join('');

    updatePagination();
  }

  function buildDoneSignature(items) {
    return (items || [])
      .filter((item) => item && item.stage === 'done')
      .map((item) => `${item.name || 'unknown'}:${itemVersion(item)}`)
      .sort()
      .join('|');
  }

  function updatePagination() {
    const total = currentTotal;
    const maxPage = Math.max(1, Math.ceil(total / pageSize));
    const start = (currentPage - 1) * pageSize;
    const end = Math.min(start + pageSize, total);

    if (pageInfo) {
      const from = total === 0 ? 0 : start + 1;
      pageInfo.textContent = `Showing ${from}-${end} of ${total}`;
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

  function currentFilterParams() {
    const params = new URLSearchParams();
    params.set('page', String(currentPage));
    params.set('range', activeFilters.range);
    if (activeFilters.domain) {
      params.set('domain', activeFilters.domain);
    }
    if (activeFilters.org) {
      params.set('org', activeFilters.org);
    }
    params.set('sort', currentSort);
    params.set('dir', currentDir);
    params.set('t', String(Date.now()));
    return params;
  }

  async function loadPage() {
    if (reportsPollInFlight) {
      return;
    }
    reportsPollInFlight = true;
    try {
      const response = await fetch(`/reports.php?${currentFilterParams().toString()}`, { cache: 'no-store' });
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
    await loadPage();
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
      updateM365Info(data && data.mailbox ? data.mailbox : null);
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
      latestStatusItems = items;
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

  if (fetchMailboxBtn) {
    fetchMailboxBtn.addEventListener('click', async () => {
      fetchMailboxBtn.disabled = true;
      try {
        await fetch('/fetch-mailbox.php', { method: 'POST', cache: 'no-store' });
      } catch (err) {
        // ignore transient failures
      }
      setTimeout(refreshStatus, 800);
      setTimeout(() => {
        fetchMailboxBtn.disabled = false;
        refreshStatus();
      }, 2500);
    });
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

  // Changing a filter reloads the whole page rather than just the listing: the
  // health panels above it are rendered server-side, and refreshing only the
  // table would leave their figures describing a range the user left.
  function applyFilters() {
    const params = new URLSearchParams();
    params.set('range', activeFilters.range);
    if (activeFilters.domain) {
      params.set('domain', activeFilters.domain);
    }
    if (activeFilters.org) {
      params.set('org', activeFilters.org);
    }
    window.location.href = '/?' + params.toString();
  }

  if (filtersReady) {
    filterDomain.addEventListener('change', () => {
      activeFilters.domain = filterDomain.value;
      applyFilters();
    });
    filterOrg.addEventListener('change', () => {
      activeFilters.org = filterOrg.value;
      applyFilters();
    });
  }

  if (statusFilter) {
    statusFilter.addEventListener('change', () => {
      statusFilterMode = statusFilter.value === 'errors' ? 'errors' : 'all';
      renderStatus(latestStatusItems);
    });
  }

  if (pagePrev) {
    pagePrev.addEventListener('click', () => {
      if (currentPage <= 1) {
        return;
      }
      currentPage = Math.max(1, currentPage - 1);
      loadPage();
    });
  }

  if (pageNext) {
    pageNext.addEventListener('click', () => {
      const maxPage = Math.max(1, Math.ceil(currentTotal / pageSize));
      if (currentPage >= maxPage) {
        return;
      }
      currentPage += 1;
      loadPage();
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
  setInterval(refreshStatus, 3000);
  updatePagination();
})();
