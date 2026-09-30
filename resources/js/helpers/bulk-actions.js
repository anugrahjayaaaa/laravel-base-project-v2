import { escHtml, resolveAction } from './action-config.js';

/**
 * Bulk Actions — dynamic dropdown based on selected rows' states.
 * Uses the existing confirmModal for confirmation.
 *
 * Configured from the #bulkBar element's data attributes so one file serves
 * every list that has a bulk bar. The user index is the default (no config
 * attribute at all); the roles index passes its own field name, noun, and state
 * map. A second near-identical file would be a second place for the confirm
 * wiring to drift.
 *
 *   data-bulk-field   name of the id inputs the form submits   (user_ids[])
 *   data-bulk-noun    what the selection is called             (user)
 *   data-bulk-states  JSON: state -> allowed actions
 *   data-bulk-mixed   action allowed for a mixed selection      (delete)
 *   data-bulk-keys    JSON: action -> action-config key         (identity)
 */
(function () {
    'use strict';

    var bulkBar = document.getElementById('bulkBar');
    var bulkCount = document.getElementById('bulkCount');
    var bulkSelectAll = document.getElementById('bulkSelectAll');
    var bulkAction = document.getElementById('bulkAction');
    var bulkApplyBtn = document.getElementById('bulkApplyBtn');
    var bulkClearBtn = document.getElementById('bulkClearBtn');

    if (!bulkBar) return;

    // User defaults — the user index sets none of these.
    var cfg = {
        field: 'user_ids[]',
        noun: 'user',
        states: {
            active: ['deactivate', 'lock', 'delete'],
            inactive: ['activate', 'delete'],
            locked: ['unlock', 'delete'],
            trashed: ['restore', 'force_delete'],
        },
        mixed: 'delete',
        keys: {},
    };
    if (bulkBar.dataset.bulkField) cfg.field = bulkBar.dataset.bulkField;
    if (bulkBar.dataset.bulkNoun) cfg.noun = bulkBar.dataset.bulkNoun;
    if (bulkBar.dataset.bulkMixed) cfg.mixed = bulkBar.dataset.bulkMixed;
    if (bulkBar.dataset.bulkStates) cfg.states = JSON.parse(bulkBar.dataset.bulkStates);
    if (bulkBar.dataset.bulkKeys) cfg.keys = JSON.parse(bulkBar.dataset.bulkKeys);

    var checkboxes = document.querySelectorAll('.bulk-check');

    var actionOptions = {
        delete: {
            label: 'Move to Trash',
            variant: 'danger'
        },
        force_delete: {
            label: 'Permanent Delete',
            variant: 'danger'
        },
        restore: {
            label: 'Restore',
            variant: 'success'
        },
        lock: {
            label: 'Lock',
            variant: 'warning'
        },
        unlock: {
            label: 'Unlock',
            variant: 'success'
        },
        activate: {
            label: 'Activate',
            variant: 'success'
        },
        deactivate: {
            label: 'Deactivate',
            variant: 'warning'
        },
    };

    function getSelectedStates() {
        var selected = document.querySelectorAll('.bulk-check:checked');
        var states = {};
        selected.forEach(function (cb) {
            var status = cb.getAttribute('data-status') || 'active';
            states[status] = true;
        });
        return Object.keys(states);
    }

    function getAvailableActions(states) {
        if (states.length === 1) {
            return cfg.states[states[0]] || [cfg.mixed];
        }
        // Mixed states — only the action that is safe for all
        return [cfg.mixed];
    }

    function populateDropdown(actions) {
        bulkAction.innerHTML = '<option value="">-- Action --</option>';
        actions.forEach(function (action) {
            var opt = actionOptions[action];
            if (!opt) return;
            var option = document.createElement('option');
            option.value = action;
            option.textContent = opt.label;
            bulkAction.appendChild(option);
        });
    }

    function updateCount() {
        var selected = document.querySelectorAll('.bulk-check:checked');
        bulkCount.textContent = selected.length;
        bulkBar.classList.toggle('d-none', selected.length === 0);

        if (selected.length > 0) {
            var states = getSelectedStates();
            var actions = getAvailableActions(states);
            populateDropdown(actions);
        } else {
            populateDropdown([]);
        }
    }

    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', updateCount);
    });

    bulkSelectAll.addEventListener('change', function () {
        checkboxes.forEach(function (cb) {
            cb.checked = bulkSelectAll.checked;
        });
        updateCount();
    });

    bulkClearBtn.addEventListener('click', function () {
        checkboxes.forEach(function (cb) {
            cb.checked = false;
        });
        bulkSelectAll.checked = false;
        updateCount();
    });

    bulkApplyBtn.addEventListener('click', function () {
        var selected = document.querySelectorAll('.bulk-check:checked');
        if (selected.length === 0) return;

        var action = bulkAction.value;
        if (!action) return;

        var itemIds = Array.from(selected).map(function (cb) {
            return cb.value;
        });

        var form = document.getElementById('confirmModalForm');
        if (!form) return;

        var existing = form.querySelectorAll('input[name="' + cfg.field + '"], input[name="action"]');
        existing.forEach(function (el) { el.remove(); });

        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = action;
        form.appendChild(actionInput);

        itemIds.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = cfg.field;
            input.value = id;
            form.appendChild(input);
        });

        // The action-config copy is per entity: "They can be restored later" is
        // wrong for a role, so the roles bar maps delete -> delete_role.
        var cfg2 = resolveAction(cfg.keys[action] || action) || {
            title: 'Confirm',
            msg: 'Are you sure?',
            variant: 'danger',
            icon: 'bi bi-question-circle',
        };
        var itemName = selected.length + ' selected ' + cfg.noun + '(s)';
        var titleEl = document.getElementById('confirmModalTitle');
        var msgEl = document.getElementById('confirmModalMessage');
        var iconEl = document.getElementById('confirmModalIcon');
        var headerEl = document.getElementById('confirmModalHeader');
        var submitBtn = document.getElementById('confirmModalSubmit');

        titleEl.textContent = cfg2.title;
        msgEl.innerHTML = cfg2.msg.replace(/__ITEM__/g, '<b>' + escHtml(itemName) + '</b>');
        iconEl.className = cfg2.icon + ' fs-3';
        iconEl.className += ' ' + (cfg2.variant === 'warning' ? 'text-warning' : cfg2.variant === 'success' ? 'text-success' : cfg2.variant === 'info' ? 'text-info' : 'text-danger');
        headerEl.style.background = 'color-mix(in srgb, var(--lbp-' + cfg2.variant + ', #ef4444) 12%, transparent)';
        submitBtn.textContent = cfg2.title;
        submitBtn.className = 'btn ' + (cfg2.variant === 'warning' ? 'btn btn-warning' : cfg2.variant === 'success' ? 'btn btn-success' : cfg2.variant === 'info' ? 'btn btn-info' : 'btn btn-danger');

        var route = document.querySelector('[data-bulk-route]');
        if (route) {
            form.action = route.dataset.bulkRoute;
        }

        var modal = new bootstrap.Modal(document.getElementById('confirmModal'));
        modal.show();
    });
})();