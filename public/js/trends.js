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

// Trends view: three headline figures compared against the preceding window, a
// per-bucket alignment column chart (shared with the sender drilldown via
// chart.js), what receivers did with the mail, and a top-senders table. The
// range switch and the two selects all reload through trends-data.php.
(function () {
  const initial = window.TRENDS_INITIAL || {};
  const chart = window.DmarcChart;

  const statCards = document.getElementById('kpi-cards');
  const chartWrap = document.getElementById('chart-wrap');
  const sendersBody = document.getElementById('senders-body');
  const dispositionPanel = document.getElementById('disposition-panel');
  const rangeSwitch = document.querySelector('.range-switch');
  const rangeLabel = document.getElementById('filter-range');
  const rangeWindow = rangeLabel ? rangeLabel.querySelector('.range-window') : null;
  const rangeComparison = rangeLabel ? rangeLabel.querySelector('.range-comparison') : null;
  const domainSelect = document.getElementById('filter-domain');
  const orgSelect = document.getElementById('filter-org');

  if (!statCards || !chartWrap || !sendersBody) {
    return;
  }

  const filters = Object.assign({ range: '30d', org: '', domain: '' }, initial.filters || {});
  let currentWindow = initial.window || {};
  let fetchInFlight = false;

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

  function passRateClass(rate) {
    const n = Number(rate || 0);
    if (n >= 98) return 'is-good';
    if (n >= 90) return 'is-mid';
    return 'is-warn';
  }

  // Delta chips read as good or bad, not as up or down: fewer failing messages
  // is an improvement even though the number went down. Pass riseIsGood null
  // for figures where neither direction is better, such as raw volume.
  function deltaChip(change, riseIsGood, decimals, suffix) {
    const rounded = Number(change.toFixed(decimals || 0));
    const sign = rounded > 0 ? '+' : (rounded < 0 ? '−' : '');
    const text = sign + Math.abs(rounded).toLocaleString('en-US', {
      minimumFractionDigits: decimals || 0,
      maximumFractionDigits: decimals || 0,
    }) + (suffix || '');
    let cls = 'is-flat';
    if (rounded !== 0 && riseIsGood !== null) {
      cls = (rounded > 0) === riseIsGood ? 'is-good' : 'is-bad';
    }
    return '<span class="delta ' + cls + '">' + escapeHtml(text) + '</span>';
  }

  function renderStats(summary, previous) {
    if (!summary) {
      statCards.innerHTML = '';
      return;
    }

    // An empty range has no pass rate; a red 0% would read as a failure.
    if (Number(summary.total || 0) === 0) {
      statCards.innerHTML = '<div class="kpi"><span class="kpi-label">No data</span>' +
        '<span class="kpi-note muted">No records in this range. Try a longer one or clear a filter.</span></div>';
      return;
    }

    const rate = Number(summary.pass_rate || 0);
    const cards = [
      {
        label: 'Pass rate',
        value: rate + '%',
        accent: passRateClass(rate),
        lead: true,
        delta: previous ? deltaChip(rate - Number(previous.pass_rate || 0), true, 1, ' pp') : '',
        note: formatNumber(summary.pass) + ' of ' + formatNumber(summary.total) + ' messages aligned',
      },
      {
        label: 'Messages',
        value: formatNumber(summary.total),
        accent: '',
        delta: previous ? deltaChip(Number(summary.total || 0) - Number(previous.total || 0), null, 0, '') : '',
        note: formatNumber(summary.sources) + ' sources across ' + formatNumber(summary.domains) + ' domains',
      },
      {
        label: 'Failing',
        value: formatNumber(summary.fail),
        accent: Number(summary.fail || 0) > 0 ? 'is-warn' : '',
        delta: previous ? deltaChip(Number(summary.fail || 0) - Number(previous.fail || 0), false, 0, '') : '',
        note: 'Neither SPF nor DKIM aligned',
      },
    ];

    statCards.innerHTML = cards
      .map((c) =>
        '<div class="kpi' + (c.lead ? ' kpi--lead' : '') + '">' +
        '<span class="kpi-label">' + escapeHtml(c.label) + '</span>' +
        '<span class="kpi-figure"><strong class="' + c.accent + '">' + escapeHtml(c.value) + '</strong>' + c.delta + '</span>' +
        '<span class="kpi-note muted">' + escapeHtml(c.note) + '</span>' +
        '</div>'
      )
      .join('');
  }

  const dispositionLabels = {
    none: 'Delivered (none)',
    quarantine: 'Quarantined',
    reject: 'Rejected',
    other: 'Unspecified',
  };

  function renderDispositions(dispositions) {
    if (!dispositionPanel) {
      return;
    }
    const data = dispositions || {};
    const keys = ['none', 'quarantine', 'reject', 'other'];
    const total = keys.reduce((sum, key) => sum + Number(data[key] || 0), 0);
    if (total === 0) {
      dispositionPanel.innerHTML = '<p class="muted">No records in this range.</p>';
      return;
    }

    const present = keys.filter((key) => Number(data[key] || 0) > 0);
    const bar = present
      .map((key) => {
        const share = (Number(data[key]) / total) * 100;
        return '<span class="disposition-seg seg-' + key + '" style="width:' + share + '%" ' +
          'title="' + escapeHtml(dispositionLabels[key] + ': ' + formatNumber(data[key])) + '"></span>';
      })
      .join('');

    const legend = present
      .map((key) => {
        const share = (Number(data[key]) / total) * 100;
        return '<li><span class="disposition-swatch seg-' + key + '"></span>' +
          escapeHtml(dispositionLabels[key]) +
          ' <strong>' + formatNumber(data[key]) + '</strong>' +
          ' <span class="muted">' + share.toFixed(share < 1 ? 2 : 1) + '%</span></li>';
      })
      .join('');

    dispositionPanel.innerHTML =
      '<div class="disposition-bar">' + bar + '</div>' +
      '<ul class="disposition-legend">' + legend + '</ul>';
  }

  const drilldown = window.BucketReports ? window.BucketReports.mount(activeFilters) : null;

  function renderChart(timeseries) {
    if (!chart) {
      return;
    }
    chart.renderAlignment(chartWrap, timeseries, {
      onSelect: drilldown ? (bucket, alignment, value) => drilldown.open(bucket, alignment, value) : null,
    });
  }

  function senderLink(ip) {
    const params = new URLSearchParams(Object.assign({ ip: ip }, activeFilters()));
    return '/sender.php?' + params.toString();
  }

  // Which mechanisms ever aligned for this source across the range.
  function alignedBy(sender) {
    const parts = [];
    if (Number(sender.dkim || 0) > 0) parts.push('DKIM');
    if (Number(sender.spf || 0) > 0) parts.push('SPF');
    return parts.length > 0 ? parts.join(' + ') : '<span class="muted">none</span>';
  }

  function renderSenders(senders) {
    if (!Array.isArray(senders) || senders.length === 0) {
      sendersBody.innerHTML = '<tr><td colspan="7" class="muted">No senders in this range.</td></tr>';
      return;
    }

    // Anything first seen in the final week of the window is worth a flag.
    const newFrom = Number(currentWindow.end_ts || 0) - 7 * 86400;

    sendersBody.innerHTML = senders
      .map((s) => {
        const total = s.total || 0;
        const passPct = total > 0 ? Math.round((s.pass / total) * 100) : 0;
        const failPct = total > 0 ? 100 - passPct : 0;
        const firstSeen = Number(s.first_seen || 0);
        const isNew = firstSeen > 0 && firstSeen >= newFrom;
        return (
          '<tr>' +
          '<td class="mono"><a href="' + escapeHtml(senderLink(s.source_ip)) + '">' + escapeHtml(s.source_ip) + '</a></td>' +
          '<td class="num" data-sort="' + Number(total) + '">' + formatNumber(total) + '</td>' +
          '<td class="num" data-sort="' + Number(s.pass || 0) + '">' + formatNumber(s.pass) + '</td>' +
          '<td class="num" data-sort="' + Number(s.fail || 0) + '">' + formatNumber(s.fail) + '</td>' +
          '<td data-sort="' + passPct + '"><div class="rate-bar" title="' + passPct + '% pass">' +
          '<span class="rate-pass" style="width:' + passPct + '%"></span>' +
          '<span class="rate-fail" style="width:' + failPct + '%"></span>' +
          '</div><span class="rate-text">' + passPct + '%</span></td>' +
          '<td>' + alignedBy(s) + '</td>' +
          '<td data-sort="' + firstSeen + '">' + escapeHtml(formatDate(firstSeen)) +
          (isNew ? '<span class="badge-new">new</span>' : '') + '</td>' +
          '</tr>'
        );
      })
      .join('');

    // The rows were replaced; restore the column the user sorted by.
    if (window.SortableTables) {
      window.SortableTables.reapply(sendersBody.closest('table'));
    }
  }

  // Must produce the same line the server rendered, or it would change shape on
  // the first filter change.
  function renderRangeLabel(previous) {
    if (!rangeLabel || !rangeWindow || !rangeComparison) {
      return;
    }
    const start = Number(currentWindow.start_ts || 0);
    const end = Number(currentWindow.end_ts || 0);
    const days = Number(currentWindow.days || 0);
    const windowText = start > 0 && end > 0 ? formatDate(start) + ' – ' + formatDate(end) : '';
    const comparisonText = previous && days > 0
      ? 'Change measured against the preceding ' + days + ' days'
      : '';

    rangeWindow.textContent = windowText;
    rangeComparison.textContent = comparisonText;
    rangeLabel.hidden = windowText === '' && comparisonText === '';
  }

  function render(data) {
    currentWindow = data.window || currentWindow;
    renderStats(data.summary, data.previous);
    renderDispositions(data.dispositions);
    renderChart(data.timeseries);
    renderSenders(data.top_senders);
    renderRangeLabel(data.previous);
  }

  // Only the filters that are actually set, for links and drilldown requests.
  function activeFilters() {
    const out = {};
    Object.keys(filters).forEach((key) => {
      if (filters[key]) {
        out[key] = filters[key];
      }
    });
    return out;
  }

  function markActiveRange() {
    if (!rangeSwitch) {
      return;
    }
    rangeSwitch.querySelectorAll('.range-option').forEach((link) => {
      const url = new URL(link.href, window.location.origin);
      const active = (url.searchParams.get('range') || '30d') === filters.range;
      link.classList.toggle('is-active', active);
      if (active) {
        link.setAttribute('aria-current', 'true');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  }

  function syncUrl() {
    const query = new URLSearchParams(activeFilters()).toString();
    window.history.replaceState(null, '', query ? '/trends.php?' + query : '/trends.php');
  }

  function reload() {
    if (fetchInFlight) {
      return;
    }
    syncUrl();
    markActiveRange();
    if (drilldown) {
      // The chart is about to change; a drilldown from the old range is stale.
      drilldown.close();
    }
    fetchInFlight = true;
    const params = new URLSearchParams(filters);
    sendersBody.innerHTML = '<tr><td colspan="7" class="muted">Loading&hellip;</td></tr>';
    fetch('/trends-data.php?' + params.toString())
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (data && data.available) {
          render(data);
        }
      })
      .catch(() => {})
      .finally(() => {
        fetchInFlight = false;
      });
  }

  // The range options stay real links so the switch works without JS; with JS
  // they reload in place instead of navigating.
  if (rangeSwitch) {
    rangeSwitch.addEventListener('click', (event) => {
      const link = event.target.closest('.range-option');
      if (!link) {
        return;
      }
      event.preventDefault();
      const url = new URL(link.href, window.location.origin);
      filters.range = url.searchParams.get('range') || '30d';
      reload();
    });
  }

  if (domainSelect) {
    domainSelect.addEventListener('change', () => {
      filters.domain = domainSelect.value;
      reload();
    });
  }

  if (orgSelect) {
    orgSelect.addEventListener('change', () => {
      filters.org = orgSelect.value;
      reload();
    });
  }

  // First paint from the server-embedded payload, no extra request needed.
  render({
    summary: initial.summary,
    previous: initial.previous,
    dispositions: initial.dispositions,
    timeseries: initial.timeseries || [],
    top_senders: initial.top_senders || [],
    window: initial.window,
  });
})();
