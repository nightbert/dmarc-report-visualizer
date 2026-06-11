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

// Trends view: headline stats, a per-day alignment column chart (shared with
// the sender drilldown via chart.js), and a top-senders table. Each source IP
// links to its per-sender drilldown.
(function () {
  const initial = window.TRENDS_INITIAL || {};
  const chart = window.DmarcChart;

  const statCards = document.getElementById('stat-cards');
  const chartWrap = document.getElementById('chart-wrap');
  const sendersBody = document.getElementById('senders-body');
  const yearSelect = document.getElementById('filter-year');
  const monthSelect = document.getElementById('filter-month');
  const orgSelect = document.getElementById('filter-org');
  const clearBtn = document.getElementById('clear-filters');

  if (!statCards || !chartWrap || !sendersBody) {
    return;
  }

  let fetchInFlight = false;

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function formatNumber(value) {
    return Number(value || 0).toLocaleString();
  }

  function renderStats(summary) {
    if (!summary) {
      statCards.innerHTML = '';
      return;
    }
    const cards = [
      { label: 'Messages', value: formatNumber(summary.total) },
      { label: 'Pass rate', value: (summary.pass_rate != null ? summary.pass_rate : 0) + '%', accent: passRateClass(summary.pass_rate) },
      { label: 'Passing', value: formatNumber(summary.pass) },
      { label: 'Failing', value: formatNumber(summary.fail), accent: summary.fail > 0 ? 'is-warn' : '' },
      { label: 'Sources', value: formatNumber(summary.sources) },
      { label: 'Domains', value: formatNumber(summary.domains) },
    ];
    statCards.innerHTML = cards
      .map((c) =>
        '<div class="stat-card ' + (c.accent || '') + '">' +
        '<strong>' + escapeHtml(c.value) + '</strong>' +
        '<span>' + escapeHtml(c.label) + '</span>' +
        '</div>'
      )
      .join('');
  }

  function passRateClass(rate) {
    const n = Number(rate || 0);
    if (n >= 98) return 'is-good';
    if (n >= 90) return 'is-mid';
    return 'is-warn';
  }

  function renderChart(timeseries) {
    if (chart) {
      chart.renderAlignment(chartWrap, timeseries);
    }
  }

  function senderLink(ip) {
    const params = new URLSearchParams(Object.assign({ ip: ip }, currentFilters()));
    return '/sender.php?' + params.toString();
  }

  function renderSenders(senders) {
    if (!Array.isArray(senders) || senders.length === 0) {
      sendersBody.innerHTML = '<tr><td colspan="5" class="muted">No senders in this range.</td></tr>';
      return;
    }

    sendersBody.innerHTML = senders
      .map((s) => {
        const total = s.total || 0;
        const passPct = total > 0 ? Math.round((s.pass / total) * 100) : 0;
        const failPct = total > 0 ? 100 - passPct : 0;
        return (
          '<tr>' +
          '<td class="mono"><a href="' + escapeHtml(senderLink(s.source_ip)) + '">' + escapeHtml(s.source_ip) + '</a></td>' +
          '<td class="num">' + formatNumber(total) + '</td>' +
          '<td class="num">' + formatNumber(s.pass) + '</td>' +
          '<td class="num">' + formatNumber(s.fail) + '</td>' +
          '<td><div class="rate-bar" title="' + passPct + '% pass">' +
          '<span class="rate-pass" style="width:' + passPct + '%"></span>' +
          '<span class="rate-fail" style="width:' + failPct + '%"></span>' +
          '</div><span class="rate-text">' + passPct + '%</span></td>' +
          '</tr>'
        );
      })
      .join('');
  }

  function render(data) {
    renderStats(data.summary);
    renderChart(data.timeseries);
    renderSenders(data.top_senders);
  }

  function currentFilters() {
    return {
      year: yearSelect ? yearSelect.value : '',
      month: monthSelect ? monthSelect.value : '',
      org: orgSelect ? orgSelect.value : '',
    };
  }

  function activeFilters() {
    const f = currentFilters();
    const out = {};
    Object.keys(f).forEach((k) => {
      if (f[k]) {
        out[k] = f[k];
      }
    });
    return out;
  }

  function syncUrl() {
    const params = new URLSearchParams(activeFilters());
    const query = params.toString();
    window.history.replaceState(null, '', query ? '/trends.php?' + query : '/trends.php');
  }

  function reload() {
    if (fetchInFlight) {
      return;
    }
    syncUrl();
    fetchInFlight = true;
    const params = new URLSearchParams(currentFilters());
    sendersBody.innerHTML = '<tr><td colspan="5" class="muted">Loading&hellip;</td></tr>';
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

  [yearSelect, monthSelect, orgSelect].forEach((sel) => {
    if (sel) {
      sel.addEventListener('change', reload);
    }
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (yearSelect) yearSelect.value = '';
      if (monthSelect) monthSelect.value = '';
      if (orgSelect) orgSelect.value = '';
      reload();
    });
  }

  // First paint from the server-embedded payload, no extra request needed.
  render({
    summary: initial.summary,
    timeseries: initial.timeseries || [],
    top_senders: initial.top_senders || [],
  });
})();
