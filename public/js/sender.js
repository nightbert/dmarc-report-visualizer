// Per-sender drilldown: render the alignment chart from the server-embedded
// timeseries. The tables and stat cards are rendered server-side.
(function () {
  const chart = window.DmarcChart;
  const wrap = document.getElementById('chart-wrap');
  if (chart && wrap) {
    chart.renderAlignment(wrap, window.SENDER_TIMESERIES || []);
  }
})();
