import { ACTION_CONFIG, escHtml } from './action-config.js';

/**
 * Bulk User Actions — dynamic dropdown based on selected users' states
 * Uses the existing confirmModal for confirmation.
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

    var stateActions = {
        active: ['deactivate', 'lock', 'delete'],
        inactive: ['activate', 'delete'],
        locked: ['unlock', 'delete'],
        trashed: ['restore', 'force_delete'],
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
            return stateActions[states[0]] || ['delete'];
        }
        // Mixed states — only delete is safe for all
        return ['delete'];
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

        var userIds = Array.from(selected).map(function (cb) {
            return cb.value;
        });

        var form = document.getElementById('confirmModalForm');
        if (!form) return;

        var existing = form.querySelectorAll('input[name="user_ids[]"], input[name="action"]');
        existing.forEach(function (el) { el.remove(); });

        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = action;
        form.appendChild(actionInput);

        userIds.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'user_ids[]';
            input.value = id;
            form.appendChild(input);
        });

        var cfg = ACTION_CONFIG[action] || ACTION_CONFIG.delete;
        var itemName = selected.length + ' selected user(s)';
        var titleEl = document.getElementById('confirmModalTitle');
        var msgEl = document.getElementById('confirmModalMessage');
        var iconEl = document.getElementById('confirmModalIcon');
        var headerEl = document.getElementById('confirmModalHeader');
        var submitBtn = document.getElementById('confirmModalSubmit');

        titleEl.textContent = cfg.title;
        msgEl.innerHTML = cfg.msg.replace(/__ITEM__/g, '<b>' + escHtml(itemName) + '</b>');
        iconEl.className = cfg.icon + ' fs-3';
        iconEl.className += ' ' + (cfg.variant === 'warning' ? 'text-warning' : cfg.variant === 'success' ? 'text-success' : cfg.variant === 'info' ? 'text-info' : 'text-danger');
        headerEl.style.background = 'color-mix(in srgb, var(--lbp-' + cfg.variant + ', #ef4444) 12%, transparent)';
        submitBtn.textContent = cfg.title;
        submitBtn.className = 'btn ' + (cfg.variant === 'warning' ? 'btn btn-warning' : cfg.variant === 'success' ? 'btn btn-success' : cfg.variant === 'info' ? 'btn btn-info' : 'btn btn-danger');

        var route = document.querySelector('[data-bulk-route]');
        if (route) {
            form.action = route.dataset.bulkRoute;
        }

        var modal = new bootstrap.Modal(document.getElementById('confirmModal'));
        modal.show();
    });
})();