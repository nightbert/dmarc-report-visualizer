// Shared stacked-column chart of DMARC message volume by alignment outcome.
// Used by the trends overview and the per-sender drilldown.
(function () {
  const SVG_NS = 'http://www.w3.org/2000/svg';

  function formatNumber(value) {
    return Number(value || 0).toLocaleString();
  }

  function el(tag, attrs, text) {
    const node = document.createElementNS(SVG_NS, tag);
    Object.keys(attrs || {}).forEach((key) => node.setAttribute(key, attrs[key]));
    if (text != null) {
      node.textContent = text;
    }
    return node;
  }

  function renderAlignment(container, timeseries) {
    if (!container) {
      return;
    }
    container.innerHTML = '';
    if (!Array.isArray(timeseries) || timeseries.length === 0) {
      container.innerHTML = '<p class="muted">No records in this range.</p>';
      return;
    }

    const padTop = 12;
    const padBottom = 30;
    const padLeft = 52;
    const padRight = 12;
    const plotH = 210;
    const n = timeseries.length;

    // Spread the columns to fill the container width (so the chart uses the
    // whole card) without distorting the SVG; fall back to a minimum slot and
    // let the wrapper scroll when there are too many days to fit.
    const slotMin = 30;
    const slotMax = 90;
    const avail = (container.clientWidth || 0) - padLeft - padRight;
    let slot = slotMin;
    if (n > 0 && avail > n * slotMin) {
      slot = Math.min(slotMax, avail / n);
    }
    const barW = Math.min(slot * 0.66, 60);
    const plotW = n * slot;
    const width = padLeft + plotW + padRight;
    const height = padTop + plotH + padBottom;

    const max = timeseries.reduce((m, d) => Math.max(m, d.total || 0), 0) || 1;
    const yFor = (v) => padTop + plotH - (v / max) * plotH;

    const svg = el('svg', {
      viewBox: '0 0 ' + width + ' ' + height,
      width: String(width),
      height: String(height),
      class: 'trend-chart',
      role: 'img',
    });

    const ticks = 4;
    for (let i = 0; i <= ticks; i++) {
      const value = Math.round((max / ticks) * i);
      const y = yFor(value);
      svg.appendChild(el('line', {
        x1: String(padLeft), y1: String(y), x2: String(padLeft + plotW), y2: String(y), class: 'grid-line',
      }));
      svg.appendChild(el('text', {
        x: String(padLeft - 8), y: String(y + 3), 'text-anchor': 'end', class: 'axis-label',
      }, formatNumber(value)));
    }

    const segments = [
      { key: 'full', cls: 'bar-full' },
      { key: 'dkim_only', cls: 'bar-dkim' },
      { key: 'spf_only', cls: 'bar-spf' },
      { key: 'fail', cls: 'bar-fail' },
    ];
    const labelEvery = Math.ceil(n / 16);

    timeseries.forEach((d, idx) => {
      const x = padLeft + idx * slot + (slot - barW) / 2;
      let cursor = padTop + plotH;
      segments.forEach((seg) => {
        const v = d[seg.key] || 0;
        if (v <= 0) {
          return;
        }
        const h = (v / max) * plotH;
        cursor -= h;
        const rect = el('rect', {
          x: String(x), y: String(cursor), width: String(barW), height: String(Math.max(h, 0.5)),
          class: 'bar-seg ' + seg.cls,
        });
        rect.appendChild(el('title', {}, d.day + ' — ' + seg.key.replace('_', ' ') + ': ' + formatNumber(v)));
        svg.appendChild(rect);
      });

      if (idx % labelEvery === 0) {
        svg.appendChild(el('text', {
          x: String(x + barW / 2), y: String(padTop + plotH + 18), 'text-anchor': 'middle', class: 'axis-label',
        }, (d.day || '').slice(5)));
      }
    });

    container.appendChild(svg);
  }

  window.DmarcChart = { renderAlignment: renderAlignment, formatNumber: formatNumber };
})();
