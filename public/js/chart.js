// Shared stacked-column chart of DMARC message volume by alignment outcome.
// Used by the trends overview and the per-sender drilldown.
(function () {
  const SVG_NS = 'http://www.w3.org/2000/svg';

  // Fixed locale so JS-rendered figures match PHP's number_format() elsewhere.
  function formatNumber(value) {
    return Number(value || 0).toLocaleString('en-US');
  }

  function el(tag, attrs, text) {
    const node = document.createElementNS(SVG_NS, tag);
    Object.keys(attrs || {}).forEach((key) => node.setAttribute(key, attrs[key]));
    if (text != null) {
      node.textContent = text;
    }
    return node;
  }

  const SEGMENT_LABELS = {
    full: 'Full alignment',
    dkim_only: 'DKIM only',
    spf_only: 'SPF only',
    fail: 'Fail',
  };

  // options.onSelect(bucket, segmentKey, value) makes the segments clickable.
  function renderAlignment(container, timeseries, options) {
    if (!container) {
      return;
    }
    const onSelect = options && typeof options.onSelect === 'function' ? options.onSelect : null;
    container.innerHTML = '';

    const data = Array.isArray(timeseries) ? timeseries : [];
    const hasData = data.length > 0;

    const padTop = 12;
    const padBottom = 30;
    const padLeft = 52;
    const padRight = 12;
    const plotH = 210;
    const n = data.length;

    // Spread the columns to fill the container width (so the chart uses the
    // whole card). When the bars don't fill the width, widen the slots so the
    // plot still spans the card; when there are too many to fit, fall back to a
    // minimum slot and let the wrapper scroll.
    const slotMin = 30;
    const avail = Math.max(0, (container.clientWidth || 0) - padLeft - padRight);
    let slot = slotMin;
    if (n > 0) {
      slot = Math.max(slotMin, avail / n);
    }
    const barW = Math.min(slot * 0.66, 60);
    // Even with no data, span the available width so the empty frame fills the card.
    const plotW = hasData ? n * slot : Math.max(avail, slotMin * 4);
    const width = padLeft + plotW + padRight;
    const height = padTop + plotH + padBottom;

    const max = data.reduce((m, d) => Math.max(m, d.total || 0), 0) || 1;
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
      }, hasData ? formatNumber(value) : ''));
    }

    if (!hasData) {
      svg.appendChild(el('text', {
        x: String(padLeft + plotW / 2), y: String(padTop + plotH / 2),
        'text-anchor': 'middle', class: 'axis-label chart-empty-label',
      }, 'No records in this range.'));
      container.appendChild(svg);
      return;
    }

    const segments = [
      { key: 'full', cls: 'bar-full' },
      { key: 'dkim_only', cls: 'bar-dkim' },
      { key: 'spf_only', cls: 'bar-spf' },
      { key: 'fail', cls: 'bar-fail' },
    ];
    const labelEvery = Math.ceil(n / 16);

    data.forEach((d, idx) => {
      const x = padLeft + idx * slot + (slot - barW) / 2;
      let cursor = padTop + plotH;
      segments.forEach((seg) => {
        const v = d[seg.key] || 0;
        if (v <= 0) {
          return;
        }
        const h = (v / max) * plotH;
        cursor -= h;
        const label = SEGMENT_LABELS[seg.key] || seg.key;
        const rect = el('rect', {
          x: String(x), y: String(cursor), width: String(barW), height: String(Math.max(h, 0.5)),
          class: 'bar-seg ' + seg.cls,
        });
        rect.appendChild(el('title', {}, d.day + ' — ' + seg.key.replace('_', ' ') + ': ' + formatNumber(v)));

        if (onSelect) {
          rect.classList.add('is-clickable');
          rect.setAttribute('role', 'button');
          rect.setAttribute('tabindex', '0');
          rect.setAttribute('aria-label', d.day + ', ' + label + ', ' + formatNumber(v) + ' messages — show reports');
          const select = () => onSelect(d.day, seg.key, v);
          rect.addEventListener('click', select);
          rect.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              select();
            }
          });
        }

        svg.appendChild(rect);
      });

      if (idx % labelEvery === 0) {
        // Daily buckets ("YYYY-MM-DD") drop the year to "MM-DD"; monthly
        // buckets ("YYYY-MM") keep the full label so the period stays clear.
        const raw = d.day || '';
        const label = raw.length > 7 ? raw.slice(5) : raw;
        svg.appendChild(el('text', {
          x: String(x + barW / 2), y: String(padTop + plotH + 18), 'text-anchor': 'middle', class: 'axis-label',
        }, label));
      }
    });

    container.appendChild(svg);
  }

  window.DmarcChart = {
    renderAlignment: renderAlignment,
    formatNumber: formatNumber,
    segmentLabel: (key) => SEGMENT_LABELS[key] || String(key || ''),
  };
})();
