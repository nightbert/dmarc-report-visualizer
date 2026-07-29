// Drilldown panel for the alignment chart: clicking a bar segment lists the
// reports behind that day/month and alignment outcome. Shared by the trends
// overview and the per-sender view, which differ only in the columns their
// panel declares (via data-col on each th) and the filters they pass in.
(function () {
  const chart = window.DmarcChart;

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Fixed locale so JS-rendered figures match PHP's number_format() elsewhere.
  function formatNumber(value) {
    return Number(value || 0).toLocaleString('en-US');
  }

  function formatDate(timestamp) {
    const ts = Number(timestamp || 0);
    if (!Number.isFinite(ts) || ts <= 0) {
      return '';
    }
    return new Date(ts * 1000).toISOString().slice(0, 10);
  }

  const columns = {
    start: (r) => '<td>' + escapeHtml(formatDate(r.begin_ts)) + '</td>',
    end: (r) => '<td>' + escapeHtml(formatDate(r.end_ts)) + '</td>',
    org: (r) => '<td>' + escapeHtml(r.org) + '</td>',
    domain: (r) => '<td>' + escapeHtml(r.domain) + '</td>',
    messages: (r) => '<td class="num" data-sort="' + Number(r.total || 0) + '">' + formatNumber(r.total) + '</td>',
    sources: (r) => '<td class="num" data-sort="' + Number(r.sources || 0) + '">' + formatNumber(r.sources) + '</td>',
    action: (r) => '<td>' + (r.token
      ? '<a href="/report.php?f=' + encodeURIComponent(r.token) + '">View</a>'
      : '<span class="muted">Unavailable</span>') + '</td>',
  };

  // getParams() supplies the filters in effect on the host page (year, month,
  // org and — on the sender view — ip).
  function mount(getParams) {
    const panel = document.getElementById('bucket-panel');
    const title = document.getElementById('bucket-title');
    const meta = document.getElementById('bucket-meta');
    const body = document.getElementById('bucket-body');
    const closeBtn = document.getElementById('bucket-close');
    if (!panel || !body) {
      return null;
    }

    const table = body.closest('table');
    const keys = Array.from(table.tHead.rows[0].cells).map((th) => th.dataset.col);
    const colspan = keys.length;
    let requestId = 0;

    function setRows(html) {
      body.innerHTML = html;
    }

    function message(text) {
      setRows('<tr><td colspan="' + colspan + '" class="muted">' + escapeHtml(text) + '</td></tr>');
    }

    function close() {
      panel.hidden = true;
      requestId += 1;
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', close);
    }

    function open(bucket, alignment, value) {
      const label = chart ? chart.segmentLabel(alignment) : alignment;
      if (title) {
        title.textContent = 'Reports · ' + bucket;
      }
      if (meta) {
        meta.textContent = label + ' · ' + formatNumber(value) + ' messages';
      }
      panel.hidden = false;
      message('Loading…');
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

      const params = new URLSearchParams(getParams ? getParams() : {});
      params.set('bucket', bucket);
      params.set('alignment', alignment);

      const current = ++requestId;
      fetch('/bucket-reports.php?' + params.toString(), { cache: 'no-store' })
        .then((response) => (response.ok ? response.json() : null))
        .then((data) => {
          if (current !== requestId) {
            return;
          }
          if (!data || !data.available) {
            message('Report details are unavailable.');
            return;
          }
          const reports = Array.isArray(data.reports) ? data.reports : [];
          if (reports.length === 0) {
            message('No reports in this bucket.');
            return;
          }
          setRows(reports
            .map((report) => '<tr>' + keys.map((key) => (columns[key] ? columns[key](report) : '<td></td>')).join('') + '</tr>')
            .join(''));
          if (window.SortableTables) {
            window.SortableTables.reapply(table);
          }
        })
        .catch(() => {
          if (current === requestId) {
            message('Could not load reports.');
          }
        });
    }

    return { open, close };
  }

  window.BucketReports = { mount };
})();
