/**
 * Bulk User Actions — checkboxes + action bar
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

    function updateCount() {
        var selected = document.querySelectorAll('.bulk-check:checked');
        bulkCount.textContent = selected.length;
        bulkBar.classList.toggle('d-none', selected.length === 0);
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

        // Populate the confirm modal form for bulk action
        var form = document.getElementById('confirmModalForm');
        if (!form) return;

        // Remove any existing bulk inputs
        var existing = form.querySelectorAll('input[name="user_ids[]"], input[name="action"]');
        existing.forEach(function (el) { el.remove(); });

        // Add action input
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = action;
        form.appendChild(actionInput);

        // Add user_ids inputs
        userIds.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'user_ids[]';
            input.value = id;
            form.appendChild(input);
        });

        // Set modal title/message based on action
        var modal = bootstrap.Modal.getInstance(document.getElementById('confirmModal'));
        var titleEl = document.getElementById('confirmModalTitle');
        var msgEl = document.getElementById('confirmModalMessage');
        var iconEl = document.getElementById('confirmModalIcon');
        var headerEl = document.getElementById('confirmModalHeader');
        var submitBtn = document.getElementById('confirmModalSubmit');

        var actionLabels = {
            delete: { title: 'Move to Trash', msg: 'Move ' + selected.length + ' user(s) to trash?', variant: 'danger', icon: 'bi bi-trash' },
            force_delete: { title: 'Permanent Delete', msg: 'Permanently delete ' + selected.length + ' user(s)? This cannot be undone.', variant: 'danger', icon: 'bi bi-exclamation-triangle' },
            restore: { title: 'Restore Users', msg: 'Restore ' + selected.length + ' user(s)?', variant: 'info', icon: 'bi bi-rotate-left' },
            lock: { title: 'Lock Users', msg: 'Lock ' + selected.length + ' user(s)? All sessions will be revoked.', variant: 'warning', icon: 'bi bi-lock' },
            unlock: { title: 'Unlock Users', msg: 'Unlock ' + selected.length + ' user(s)?', variant: 'success', icon: 'bi bi-unlock' },
            activate: { title: 'Activate Users', msg: 'Activate ' + selected.length + ' user(s)?', variant: 'success', icon: 'bi bi-person-check' },
            deactivate: { title: 'Deactivate Users', msg: 'Deactivate ' + selected.length + ' user(s)? They will be logged out.', variant: 'warning', icon: 'bi bi-person-slash' },
        };

        var cfg = actionLabels[action] || actionLabels.delete;
        titleEl.textContent = cfg.title;
        msgEl.textContent = cfg.msg;
        iconEl.className = cfg.icon + ' fs-1';
        iconEl.className += ' ' + (cfg.variant === 'warning' ? 'text-warning' : cfg.variant === 'success' ? 'text-success' : cfg.variant === 'info' ? 'text-info' : 'text-danger');
        headerEl.style.background = 'color-mix(in srgb, var(--lbp-' + cfg.variant + ', #ef4444) 12%, transparent)';
        submitBtn.textContent = cfg.title;
        submitBtn.className = 'btn ' + (cfg.variant === 'warning' ? 'bg-warning-subtle text-warning' : cfg.variant === 'success' ? 'bg-success-subtle text-success' : cfg.variant === 'info' ? 'bg-info-subtle text-info' : 'bg-danger-subtle text-danger');

        // Set form action to bulk endpoint
        var route = document.querySelector('[data-bulk-route]');
        if (route) {
            form.action = route.dataset.bulkRoute;
        }

        // Show modal
        modal = new bootstrap.Modal(document.getElementById('confirmModal'));
        modal.show();
    });
})();