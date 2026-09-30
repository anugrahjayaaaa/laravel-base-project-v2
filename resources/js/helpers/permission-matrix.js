/**
 * Permission matrix — split pane, tri-state group toggles, client-side search.
 *
 * Vanilla JS, no dependencies, like the other three helpers. jQuery would mean
 * ±30 KB gzip and a second dialect of JS in a codebase whose other helpers are
 * all vanilla.
 *
 * The invariant this file exists to protect: every checkbox stays in the DOM.
 * The rail and the search only toggle `hidden`. An input that is not in the DOM
 * submits nothing, and an unchecked input also submits nothing — identical on
 * the wire — so removing or filtering inputs would make Save silently uncheck
 * every permission the admin could not see.
 *
 * No fetch, ever: the permissions arrive with the page (one query, see
 * RoleController::permissionData()). Fetching a group on click would make
 * exactly that inputs-missing bug, and would add a query per click.
 */
(function () {
    'use strict';

    var matrix = document.getElementById('permissionMatrix');

    if (!matrix) return;

    var search = matrix.querySelector('[data-matrix-search]');
    var panel = matrix.querySelector('[data-matrix-panel]');
    var emptyState = matrix.querySelector('[data-matrix-empty]');
    var summary = matrix.querySelector('[data-matrix-summary]');
    var clearButton = matrix.querySelector('[data-matrix-clearfilter]');

    var original = (summary.getAttribute('data-matrix-original') || '')
        .split(',')
        .filter(Boolean)
        .sort();

    /** Every permission input, in document order. Built once — they never move. */
    function inputs() {
        return matrix.querySelectorAll('input[name="permissions[]"]');
    }

    function rowsOf(resource) {
        return matrix.querySelectorAll('[data-matrix-row][data-matrix-resource="' + resource + '"]');
    }

    function checksOf(resource) {
        // Scoped to the row's inputs rather than matching the attribute on the
        // input itself: data-matrix-resource lives on the row, which is also
        // what `hidden` is toggled on.
        var list = [];

        rowsOf(resource).forEach(function (row) {
            var input = row.querySelector('input[name="permissions[]"]');

            if (input) list.push(input);
        });

        return list;
    }

    /**
     * Tri-state a checkbox.
     *
     * `indeterminate` is a DOM property with no HTML attribute, so it cannot be
     * rendered server-side and does not survive a form re-render — which is
     * exactly why it has to be recomputed here rather than templated.
     */
    function setTriState(el, checkedCount, total) {
        el.checked = total > 0 && checkedCount === total;
        el.indeterminate = checkedCount > 0 && checkedCount < total;
    }

    /**
     * The rail's selected styling.
     *
     * Mirrors the server-rendered first-item classes in role-permission-matrix.
     * The two must stay in step: the server paints the initial state (so the
     * page is correct before JS runs) and this swaps it on click. A `active`
     * class toggle would silently stop styling anything the moment the rail
     * stopped being a nav-pills list.
     */
    var SELECTED = ['bg-body-tertiary', 'text-primary', 'border-start', 'border-2', 'border-primary', 'fw-semibold'];
    var UNSELECTED = ['bg-transparent', 'text-secondary'];

    function markSelected(button, selected) {
        SELECTED.forEach(function (c) { button.classList.toggle(c, selected); });
        UNSELECTED.forEach(function (c) { button.classList.toggle(c, !selected); });

        if (selected) {
            button.setAttribute('aria-current', 'true');
        } else {
            button.removeAttribute('aria-current');
        }

        // The badge pair follows the DS two-state rule; the count text carries
        // how many are selected, which is what an admin actually reads.
        var badge = button.querySelector('[data-matrix-count]');

        if (badge) {
            badge.classList.toggle('bg-primary', selected);
            badge.classList.toggle('text-white', selected);
            badge.classList.toggle('bg-secondary-subtle', !selected);
            badge.classList.toggle('text-secondary', !selected);
        }
    }

    /**
     * Recount one group: its select-all toggle.
     *
     * The count TEXT lives on the rail and is server-rendered + updated here;
     * the rail's own colours are the selection indicator, so they are not
     * touched by how many of the group's permissions are ticked.
     */
    function refreshGroup(resource) {
        var checks = checksOf(resource);
        var checked = 0;

        for (var i = 0; i < checks.length; i++) {
            if (checks[i].checked) checked++;
        }

        var badge = matrix.querySelector('[data-matrix-count="' + resource + '"]');

        if (badge) badge.textContent = checked + '/' + checks.length;

        var toggle = matrix.querySelector('[data-matrix-selectall="' + resource + '"]');

        if (toggle) setTriState(toggle, checked, checks.length);
    }

    function refreshAllGroups() {
        var groups = matrix.querySelectorAll('[data-matrix-group]');

        for (var i = 0; i < groups.length; i++) {
            refreshGroup(groups[i].getAttribute('data-matrix-group'));
        }
    }

    /** Selected / added / removed against what the server rendered. */
    function refreshSummary() {
        var current = [];

        inputs().forEach(function (input) {
            if (input.checked) current.push(input.value);
        });

        current.sort();

        var added = current.filter(function (v) { return original.indexOf(v) === -1; });
        var removed = original.filter(function (v) { return current.indexOf(v) === -1; });

        matrix.querySelector('[data-matrix-total]').textContent = current.length;

        var addedEl = matrix.querySelector('[data-matrix-added]');
        var removedEl = matrix.querySelector('[data-matrix-removed]');

        // The diff is what makes a filtered search non-destructive: it is the
        // only place an admin can see what Save will do to rows they cannot see.
        addedEl.textContent = '+' + added.length + ' added';
        removedEl.textContent = '-' + removed.length + ' removed';
        addedEl.classList.toggle('d-none', added.length === 0);
        removedEl.classList.toggle('d-none', removed.length === 0);
    }

    function refresh() {
        refreshAllGroups();
        refreshSummary();
    }

    // --- rail ---------------------------------------------------------------

    matrix.querySelectorAll('[data-matrix-group]').forEach(function (button) {
        button.addEventListener('click', function () {
            var resource = button.getAttribute('data-matrix-group');

            matrix.querySelectorAll('[data-matrix-group]').forEach(function (other) {
                markSelected(other, other === button);
            });

            matrix.querySelectorAll('[data-matrix-section]').forEach(function (section) {
                section.hidden = section.getAttribute('data-matrix-section') !== resource;
            });

            // Choosing a group is an explicit "show me this resource", so it
            // clears the filter. Otherwise clicking a group the filter excluded
            // shows an empty panel with no visible reason why.
            if (search.value !== '') {
                search.value = '';
                applyFilter();
            }
        });
    });

    // --- group select-all ---------------------------------------------------

    matrix.querySelectorAll('[data-matrix-selectall]').forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            var target = toggle.checked;

            checksOf(toggle.getAttribute('data-matrix-selectall')).forEach(function (input) {
                input.checked = target;
            });

            refresh();
        });
    });

    // --- individual permission ---------------------------------------------

    matrix.addEventListener('change', function (event) {
        var input = event.target;

        if (input.name === 'permissions[]') {
            refreshGroup(input.closest('[data-matrix-row]').getAttribute('data-matrix-resource'));
            refreshSummary();
        }
    });

    // --- search -------------------------------------------------------------

    function applyFilter() {
        var term = search.value.trim().toLowerCase();
        var filtering = term !== '';

        // Select-all is REMOVED while filtering, not relabelled: with 11 rows in
        // `users` and 2 matching, "select all" is a lie about what will be
        // selected, and "select visible" invites the admin to believe the other
        // 9 were handled.
        matrix.querySelectorAll('[data-matrix-groupall]').forEach(function (group) {
            group.classList.toggle('d-none', filtering);
        });

        var visible = 0;

        matrix.querySelectorAll('[data-matrix-section]').forEach(function (section) {
            var resource = section.getAttribute('data-matrix-section');
            var hits = 0;

            rowsOf(resource).forEach(function (row) {
                var hit = !filtering || matches(row, term);

                row.hidden = !hit;
                if (hit) hits++;
            });

            // With no filter, only the selected group's section is shown. With a
            // filter, EVERY section that matched is shown — otherwise the panel
            // can sit on a group the filter excluded and read as "no results"
            // while matches are sitting one click away in the rail.
            var current = matrix.querySelector('[data-matrix-group][aria-current="true"]');
            var show = filtering
                ? hits > 0
                : current !== null && current.getAttribute('data-matrix-group') === resource;

            section.hidden = !show;
            visible += show ? hits : 0;
        });

        emptyState.hidden = visible > 0;
        clearButton.classList.toggle('d-none', !filtering);
    }

    function matches(row, term) {
        var haystack = [
            row.getAttribute('data-matrix-action'),
            row.getAttribute('data-matrix-resource'),
            row.getAttribute('data-matrix-name'),
        ].join(' ').toLowerCase();

        return haystack.indexOf(term) !== -1;
    }

    if (search) search.addEventListener('input', applyFilter);

    if (clearButton) {
        clearButton.addEventListener('click', function () {
            search.value = '';
            applyFilter();
            search.focus();
        });
    }

    // Server-rendered checkboxes start in a real state; the tri-state flags and
    // the diff do not, so compute them once on load.
    refresh();
})();
