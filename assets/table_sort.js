(function () {
    'use strict';

    // Generic client-side column sorting for portal tables. Attach with
    // <table data-sortable> and th[data-sort-key][data-sort-type].
    // Row values come from data-<key> attributes; aria-sort tracks state.

    function cellValue(row, key) {
        var raw = row.getAttribute('data-' + key);
        return String(raw === null || raw === undefined ? '' : raw).trim();
    }

    function compareValues(a, b, type) {
        if (type === 'number') {
            var na = parseFloat(a.replace(/[^0-9,.-]/g, '').replace(/\s/g, '').replace(',', '.'));
            var nb = parseFloat(b.replace(/[^0-9,.-]/g, '').replace(/\s/g, '').replace(',', '.'));
            if (isNaN(na)) na = -Infinity;
            if (isNaN(nb)) nb = -Infinity;
            return na === nb ? 0 : (na < nb ? -1 : 1);
        }
        if (type === 'date') {
            // ISO dates (yyyy-mm-dd) compare correctly as strings; anything
            // else falls back to a locale-aware comparison.
            if (/^\d{4}-\d{2}-\d{2}$/.test(a) && /^\d{4}-\d{2}-\d{2}$/.test(b)) {
                return a === b ? 0 : (a < b ? -1 : 1);
            }
        }
        return a.localeCompare(b, 'sv-SE', { sensitivity: 'base', numeric: true });
    }

    function sortTable(table, header) {
        if (!table || !header) return;
        var key = header.getAttribute('data-sort-key');
        var type = header.getAttribute('data-sort-type') || 'text';
        if (!key) return;
        var tbody = table.tBodies && table.tBodies[0];
        if (!tbody) return;
        var direction = header.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
        if (header.getAttribute('aria-sort') === 'none' && (type === 'date' || type === 'number')) {
            direction = 'descending';
        }
        var headers = table.querySelectorAll('th[data-sort-key]');
        for (var i = 0; i < headers.length; i++) {
            headers[i].setAttribute('aria-sort', 'none');
        }
        header.setAttribute('aria-sort', direction);
        var rows = Array.prototype.slice.call(tbody.rows);
        rows = rows.map(function (row, index) {
            return { row: row, index: index, value: cellValue(row, key) };
        });
        rows.sort(function (left, right) {
            var result = compareValues(left.value, right.value, type);
            if (result === 0) return left.index - right.index;
            return direction === 'ascending' ? result : -result;
        });
        var fragment = document.createDocumentFragment();
        rows.forEach(function (entry) {
            fragment.appendChild(entry.row);
        });
        tbody.appendChild(fragment);
    }

    function attach(table) {
        if (!table || table.getAttribute('data-sortable-attached') === '1') return;
        table.setAttribute('data-sortable-attached', '1');
        var headers = table.querySelectorAll('th[data-sort-key]');
        Array.prototype.forEach.call(headers, function (header) {
            header.setAttribute('aria-sort', header.getAttribute('aria-sort') || 'none');
            var target = header.querySelector('.file-sort-button') || header;
            target.addEventListener('click', function () {
                sortTable(table, header);
            });
        });
    }

    function attachAll(root) {
        var scope = root || document;
        if (!scope.querySelectorAll) return;
        Array.prototype.forEach.call(scope.querySelectorAll('table[data-sortable]'), attach);
    }

    window.portalTableSort = { attach: attach, attachAll: attachAll, sortTable: sortTable };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            attachAll(document);
        });
    } else {
        attachAll(document);
    }
})();
