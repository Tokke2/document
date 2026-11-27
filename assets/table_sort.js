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

    var advancedTables = new WeakMap();
    var controlSequence = 0;

    function attachAdvanced(table) {
        var body = table.tBodies[0];
        if (!body) return;
        var en = table.getAttribute('data-sort-lang') === 'en';
        function msg(sv, english) { return en ? english : sv; }
        var headers = Array.prototype.slice.call(table.querySelectorAll('th[data-sort-key]'));
        var fields = headers.map(function (header) {
            return { key: header.dataset.sortKey, type: header.dataset.sortType || 'text', label: header.textContent.trim() };
        });
        if (!fields.length) return;
        var rows = Array.prototype.slice.call(body.rows);
        var collapsed = new Set();
        var panel = document.createElement('div'); panel.className = 'portal-list-controls';
        panel.setAttribute('role', 'group'); panel.setAttribute('aria-label', msg('Sortera och gruppera', 'Sort and group'));
        var id = 'portal-list-' + (++controlSequence);
        function selectControl(key, label, options) {
            var wrapper = document.createElement('div'), text = document.createElement('label'), select = document.createElement('select');
            text.htmlFor = id + '-' + key; text.textContent = label; select.id = text.htmlFor; select.dataset.listControl = key;
            options.forEach(function (item) { var option = document.createElement('option'); option.value = item.key; option.textContent = item.label; select.appendChild(option); });
            wrapper.append(text, select); panel.appendChild(wrapper); return select;
        }
        var primary = selectControl('primary', msg('Sortera först på', 'Sort first by'), fields);
        var directions = [{ key:'ascending', label:msg('Stigande (A–Ö / äldsta / lägsta)', 'Ascending (A–Z / oldest / lowest)') }, { key:'descending', label:msg('Fallande (Ö–A / nyaste / högsta)', 'Descending (Z–A / newest / highest)') }];
        var primaryDirection = selectControl('primary-direction', msg('Riktning', 'Direction'), directions);
        var secondary = selectControl('secondary', msg('Därefter på', 'Then by'), [{ key:'', label:msg('Ingen andrasortering', 'No secondary sort') }].concat(fields));
        var secondaryDirection = selectControl('secondary-direction', msg('Riktning för andrasortering', 'Secondary direction'), directions);
        var groupLabels = { customer:msg('Kund', 'Customer'), folder:msg('Mapp', 'Folder'), month:msg('Månad', 'Month') };
        var groupOptions = [{ key:'', label:msg('Ingen gruppering', 'No grouping') }];
        (table.getAttribute('data-group-keys') || '').split(',').forEach(function (key) { if (groupLabels[key]) groupOptions.push({ key:key, label:groupLabels[key] }); });
        var group = selectControl('group', msg('Gruppera efter', 'Group by'), groupOptions);
        var status = document.createElement('p'); status.className = 'portal-list-sort-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); panel.appendChild(status);
        var wrapper = table.closest('.file-table-wrap,.invoice-record-table-wrap') || table;
        wrapper.parentNode.insertBefore(panel, wrapper);
        var defaultField = fields.find(function (field) { return field.key === table.getAttribute('data-default-sort'); }) || fields[0];
        primary.value = defaultField.key;
        primaryDirection.value = defaultField.type === 'text' ? 'ascending' : 'descending';
        function getField(key) { return fields.find(function (field) { return field.key === key; }); }
        function rowGroup(row) {
            if (group.value === 'month') {
                var date = cellValue(row, 'date');
                return /^\d{4}-\d{2}-\d{2}$/.test(date) ? date.slice(0, 7) : '';
            }
            return cellValue(row, group.value);
        }
        function isFiltered(row) { return row.getAttribute('data-sort-filtered') === '1'; }
        function showRow(row, closed) {
            row.toggleAttribute('data-sort-collapsed', closed);
            row.hidden = isFiltered(row) || closed;
        }
        function render() {
            if (secondary.value === primary.value) secondary.value = '';
            secondaryDirection.disabled = !secondary.value;
            headers.forEach(function (header) { header.setAttribute('aria-sort', header.dataset.sortKey === primary.value ? primaryDirection.value : 'none'); });
            var rules = [{ field:getField(primary.value), direction:primaryDirection.value }];
            if (secondary.value) rules.push({ field:getField(secondary.value), direction:secondaryDirection.value });
            rows = rows.map(function (row, index) { return { row:row, index:index }; }).sort(function (a, b) {
                for (var i = 0; i < rules.length; i++) {
                    var rule = rules[i], result = compareValues(cellValue(a.row, rule.field.key), cellValue(b.row, rule.field.key), rule.field.type);
                    if (result) return rule.direction === 'ascending' ? result : -result;
                }
                return a.index - b.index;
            }).map(function (item) { return item.row; });
            Array.prototype.forEach.call(body.querySelectorAll('[data-sort-group]'), function (row) { row.remove(); });
            var fragment = document.createDocumentFragment();
            if (!group.value) rows.forEach(function (row) { showRow(row, false); fragment.appendChild(row); });
            else {
                var groups = new Map();
                rows.forEach(function (row) { var key = rowGroup(row); if (!groups.has(key)) groups.set(key, []); groups.get(key).push(row); });
                Array.from(groups.keys()).sort(function (a, b) {
                    // Unknown group comes last; month groups are newest first.
                    if (!a || !b) return a === b ? 0 : (!a ? 1 : -1);
                    var result = compareValues(a, b, 'text'); return group.value === 'month' ? -result : result;
                }).forEach(function (key) {
                    var members = groups.get(key), count = members.filter(function (row) { return !isFiltered(row); }).length;
                    var stateKey = group.value + ':' + key, closed = collapsed.has(stateKey);
                    var heading = document.createElement('tr'); heading.dataset.sortGroup = key; heading.className = 'portal-list-group'; heading.hidden = count === 0;
                    var cell = document.createElement('td'); cell.colSpan = table.tHead.rows[0].cells.length;
                    var button = document.createElement('button'); button.type = 'button'; button.className = 'portal-list-group-toggle';
                    button.textContent = (key || msg('Uppgift saknas', 'Not specified')) + ' (' + count + ')';
                    button.setAttribute('aria-expanded', String(!closed));
                    button.addEventListener('click', function () {
                        closed = !closed; if (closed) collapsed.add(stateKey); else collapsed.delete(stateKey);
                        button.setAttribute('aria-expanded', String(!closed)); members.forEach(function (row) { showRow(row, closed); });
                    });
                    cell.appendChild(button); heading.appendChild(cell); fragment.appendChild(heading);
                    members.forEach(function (row) { showRow(row, closed); fragment.appendChild(row); });
                });
            }
            body.appendChild(fragment);
            status.textContent = msg('Sortering: ', 'Sort: ') + rules.map(function (rule) { return rule.field.label + ' (' + (rule.direction === 'ascending' ? msg('stigande', 'ascending') : msg('fallande', 'descending')) + ')'; }).join(msg(', därefter ', ', then ')) + '. ' + msg('Gruppering: ', 'Grouping: ') + (groupLabels[group.value] || msg('ingen', 'none')) + '.';
        }
        panel.addEventListener('change', render);
        var controller = { refresh:render, headerSort:function (header) {
            var key = header.dataset.sortKey, field = getField(key);
            primaryDirection.value = primary.value === key ? (primaryDirection.value === 'ascending' ? 'descending' : 'ascending') : (field.type === 'text' ? 'ascending' : 'descending');
            primary.value = key; render();
        } };
        advancedTables.set(table, controller);
        headers.forEach(function (header) { var button = header.querySelector('button') || header; button.addEventListener('click', function () { controller.headerSort(header); }); });
        render();
    }

    function sortTable(table, header) {
        if (!table || !header) return;
        if (advancedTables.has(table)) { advancedTables.get(table).headerSort(header); return; }
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
        if (table.hasAttribute('data-sort-advanced')) { attachAdvanced(table); return; }
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

    window.portalTableSort = { attach: attach, attachAll: attachAll, sortTable: sortTable, refresh: function (table) { if (advancedTables.has(table)) advancedTables.get(table).refresh(); } };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            attachAll(document);
        });
    } else {
        attachAll(document);
    }
})();
