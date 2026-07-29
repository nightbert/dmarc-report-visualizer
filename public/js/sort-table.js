// Click-to-sort for static tables marked with data-sortable. Columns whose th
// carries data-nosort stay inert. Cells may provide a raw sort key via
// data-sort; otherwise the visible text is used. Empty cells always sort last.
// Server-paginated tables (the dashboard) sort server-side instead, because
// sorting only the current page would be misleading.
(function () {
  const numericPattern = /^-?\d+(?:\.\d+)?$/;

  function cellValue(row, index) {
    const cell = row.children[index];
    if (!cell) {
      return { text: '', number: null };
    }
    const raw = cell.getAttribute('data-sort');
    const text = (raw !== null ? raw : cell.textContent || '').trim();
    return {
      text,
      number: text !== '' && numericPattern.test(text) ? parseFloat(text) : null,
    };
  }

  function sortRows(table, index, direction) {
    const tbody = table.tBodies[0];
    if (!tbody) {
      return;
    }
    const entries = Array.from(tbody.rows).map((row, position) => ({
      row,
      position,
      value: cellValue(row, index),
    }));

    entries.sort((a, b) => {
      const left = a.value;
      const right = b.value;
      if (left.text === '' || right.text === '') {
        if (left.text === right.text) {
          return a.position - b.position;
        }
        return left.text === '' ? 1 : -1;
      }
      const result = left.number !== null && right.number !== null
        ? left.number - right.number
        : left.text.localeCompare(right.text, undefined, { numeric: true, sensitivity: 'base' });
      if (result === 0) {
        return a.position - b.position;
      }
      return direction === 'desc' ? -result : result;
    });

    const fragment = document.createDocumentFragment();
    entries.forEach((entry) => fragment.appendChild(entry.row));
    tbody.appendChild(fragment);
  }

  function markHeaders(table, index, direction) {
    const head = table.tHead;
    if (!head || !head.rows[0]) {
      return;
    }
    Array.from(head.rows[0].cells).forEach((th, position) => {
      if (!th.classList.contains('is-sortable')) {
        return;
      }
      const active = position === index;
      th.classList.toggle('is-sorted', active);
      th.classList.toggle('is-sorted-desc', active && direction === 'desc');
      th.setAttribute('aria-sort', active ? (direction === 'desc' ? 'descending' : 'ascending') : 'none');
    });
  }

  function applySort(table, index, direction) {
    sortRows(table, index, direction);
    markHeaders(table, index, direction);
    table.dataset.sortIndex = String(index);
    table.dataset.sortDir = direction;
  }

  // Re-apply the active sort after a tbody was re-rendered dynamically.
  function reapply(table) {
    if (!table || table.dataset.sortIndex === undefined) {
      return;
    }
    const index = Number(table.dataset.sortIndex);
    if (!Number.isFinite(index)) {
      return;
    }
    applySort(table, index, table.dataset.sortDir === 'desc' ? 'desc' : 'asc');
  }

  function init(root) {
    const scope = root || document;
    scope.querySelectorAll('table[data-sortable]').forEach((table) => {
      const head = table.tHead;
      if (!head || !head.rows[0] || table.dataset.sortableReady === '1') {
        return;
      }
      table.dataset.sortableReady = '1';

      Array.from(head.rows[0].cells).forEach((th) => {
        if (th.hasAttribute('data-nosort')) {
          return;
        }
        th.classList.add('is-sortable');
        th.setAttribute('aria-sort', 'none');
        th.tabIndex = 0;
      });

      const activate = (th) => {
        if (!th || !th.classList.contains('is-sortable')) {
          return;
        }
        const index = th.cellIndex;
        const sameColumn = table.dataset.sortIndex === String(index);
        const nextDirection = sameColumn && table.dataset.sortDir === 'asc' ? 'desc' : 'asc';
        applySort(table, index, sameColumn ? nextDirection : (th.dataset.sortDefault === 'desc' ? 'desc' : 'asc'));
      };

      head.addEventListener('click', (event) => {
        activate(event.target.closest('th'));
      });
      head.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }
        event.preventDefault();
        activate(event.target.closest('th'));
      });
    });
  }

  window.SortableTables = { init, reapply };
  init(document);
})();
