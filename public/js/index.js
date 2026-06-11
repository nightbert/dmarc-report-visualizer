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
  };
  const statusList = document.getElementById('status-list');
  const statusFilter = document.getElementById('status-filter');
  let statusFilterMode = 'all';
  let latestStatusItems = [];
  const uploadForm = document.getElementById('upload-form');
  const fileInput = document.getElementById('file-input');
  const statusReload = document.getElementById('status-reload');
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

  function renderStatus(items) {
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

  function updateM365Info(info) {
    if (!mailboxLastFetch || !info) {
      return;
    }
    const ts = Number(info.last_fetch_at || 0);
    let text = 'Last fetch: never';
    if (Number.isFinite(ts) && ts > 0) {
      text = 'Last fetch: ' + new Date(ts * 1000).toLocaleString();
      if (info.result === 'error') {
        text += ' (error)';
      }
    }
    mailboxLastFetch.textContent = text;
    mailboxLastFetch.title = String(info.message || '');
  }

  const filterYear = document.getElementById('filter-year');
  const filterMonth = document.getElementById('filter-month');
  const filterOrg = document.getElementById('filter-org');
  const clearFilters = document.getElementById('clear-filters');
  const filtersReady = filterYear && filterMonth && filterOrg;
  const pageSize = initialReportState.perPage || 20;
  let currentPage = initialReportState.page || 1;
  let currentTotal = initialReportState.total || 0;
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
          loadPage();
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

    currentTotal = Number(data.total || 0);
    currentPage = Number(data.page || currentPage) || 1;

    if (totalReports) {
      totalReports.textContent = String(currentTotal);
    }

    if (filtersReady) {
      syncSelectOptions(filterYear, data.year_options || []);
      syncSelectOptions(filterMonth, data.month_options || []);
      syncSelectOptions(filterOrg, data.org_options || [], { sortAlpha: true });
    }

    const tableBody = document.querySelector('.reports tbody');
    const summaries = Array.isArray(data.summaries) ? data.summaries : [];
    if (!tableBody) {
      // The page was rendered empty server-side; reload once reports exist.
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
        <tr data-year="${escapeHtml(year)}" data-month="${escapeHtml(month)}" data-org="${escapeHtml(org)}" data-token="${escapeHtml(token)}">
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

    bindDeleteButtons();
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
    if (filtersReady) {
      if (filterYear.value) {
        params.set('year', filterYear.value);
      }
      if (filterMonth.value) {
        params.set('month', filterMonth.value);
      }
      if (filterOrg.value) {
        params.set('org', filterOrg.value);
      }
    }
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

  if (filtersReady) {
    filterYear.addEventListener('change', () => {
      currentPage = 1;
      loadPage();
    });
    filterMonth.addEventListener('change', () => {
      currentPage = 1;
      loadPage();
    });
    filterOrg.addEventListener('change', () => {
      currentPage = 1;
      loadPage();
    });
  }

  if (clearFilters && filtersReady) {
    clearFilters.addEventListener('click', () => {
      filterYear.value = '';
      filterMonth.value = '';
      filterOrg.value = '';
      currentPage = 1;
      loadPage();
    });
  }

  if (statusFilter) {
    statusFilter.addEventListener('change', () => {
      statusFilterMode = statusFilter.value === 'errors' ? 'errors' : 'all';
      renderStatus(latestStatusItems);
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
  bindDeleteButtons();
})();
