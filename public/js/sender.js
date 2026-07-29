// Per-sender drilldown: render the alignment chart from the server-embedded
// timeseries. The tables and stat cards are rendered server-side. Clicking a
// bar segment lists this sender's reports for that bucket.
(function () {
  const chart = window.DmarcChart;
  const wrap = document.getElementById('chart-wrap');
  if (!chart || !wrap) {
    return;
  }

  const filters = window.SENDER_FILTERS || {};
  const drilldown = window.BucketReports ? window.BucketReports.mount(() => filters) : null;

  chart.renderAlignment(wrap, window.SENDER_TIMESERIES || [], {
    onSelect: drilldown ? (bucket, alignment, value) => drilldown.open(bucket, alignment, value) : null,
  });
})();
