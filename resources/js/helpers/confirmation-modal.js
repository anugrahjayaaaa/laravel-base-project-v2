import { ACTION_CONFIG, escHtml } from './action-config.js';

/**
 * Global Confirmation Modal — JS driver
 * Replaces inline script in confirmation.blade.php.
 * Event delegation: any [data-bs-toggle="modal"][data-bs-target="#confirmModal"]
 * trigger activates this modal.
 * Form submits normally (no AJAX) — Laravel routing handles the action.
 */
(function () {
    'use strict';

    var MODAL_ID = 'confirmModal';
    var modal, form, header, title, message, submitBtn, iconEl;

    var VARIANT_META = {
        success: {
            icon: 'bi bi-person-check',
            iconColor: 'text-success',
            btnClass: 'btn-success',
            headerMix: 'var(--lbp-success, #198754)'
        },
        warning: {
            icon: 'bi bi-person-slash',
            iconColor: 'text-warning',
            btnClass: 'btn-warning',
            headerMix: 'var(--lbp-warning, #f59e0b)'
        },
        danger: {
            icon: 'bi bi-trash',
            iconColor: 'text-danger',
            btnClass: 'btn-danger',
            headerMix: 'var(--lbp-danger, #ef4444)'
        }
    };

    function init() {
        var el = document.getElementById(MODAL_ID);
        if (!el) return;

        if (typeof bootstrap === 'undefined' || typeof bootstrap.Modal !== 'function') {
            console.warn('ConfirmationModal: Bootstrap Modal not available');
            return;
        }

        try {
            modal = new bootstrap.Modal(el);
        } catch (err) {
            console.warn('ConfirmationModal: Failed to init Bootstrap Modal', err);
            return;
        }

        form = el.querySelector('#confirmModalForm');
        header = el.querySelector('#confirmModalHeader');
        title = el.querySelector('#confirmModalTitle');
        message = el.querySelector('#confirmModalMessage');
        iconEl = el.querySelector('#confirmModalIcon');
        submitBtn = form.querySelector('#confirmModalSubmit') || form.querySelector('button[type="submit"]');

        document.addEventListener('click', onTriggerClick);
        el.addEventListener('hidden.bs.modal', onHidden);
    }

    function onTriggerClick(e) {
        var trigger = e.target.closest('[data-bs-toggle="modal"][data-bs-target^="#confirmModal"]');
        if (!trigger) return;

        e.preventDefault();

        var actionUrl = trigger.getAttribute('data-action') || '#';
        var method = trigger.getAttribute('data-method') || 'POST';
        var actionType = trigger.getAttribute('data-action-type');
        var itemName = trigger.getAttribute('data-item-name') || '';
        var label = trigger.getAttribute('data-label') || trigger.getAttribute('data-action-label') || 'Confirm';

        var cfg;
        if (actionType && ACTION_CONFIG[actionType]) {
            cfg = ACTION_CONFIG[actionType];
        } else {
            cfg = {
                title: trigger.getAttribute('data-title') || 'Confirm',
                msg: (trigger.getAttribute('data-message') || 'Are you sure?').replace(/__BOLD__/g, '<b>' + escHtml(itemName) + '</b>'),
                variant: trigger.getAttribute('data-variant') || 'danger',
                icon: trigger.getAttribute('data-icon') || 'bi bi-question-circle',
            };
        }

        modal._ctx = { action: actionUrl, method, variant: cfg.variant, trigger };

        title.textContent = cfg.title;
        message.innerHTML = cfg.msg.replace(/__ITEM__/g, '<b>' + escHtml(itemName) + '</b>');
        applyVariant(cfg.variant, cfg.icon);
        submitBtn.textContent = label;
        submitBtn.disabled = false;

        if (actionUrl && !actionUrl.startsWith('javascript:')) {
            form.action = actionUrl;
        } else {
            form.action = '#';
        }

        // Inject _method hidden input for PUT/PATCH/DELETE
        var methodInput = form.querySelector('input[name="_method"]');
        if (!methodInput) {
            methodInput = document.createElement('input');
            methodInput.type = 'hidden';
            methodInput.name = '_method';
            form.appendChild(methodInput);
        }
        methodInput.value = method;

        modal.show();
    }

    function applyVariant(variant, iconOverride) {
        var meta = VARIANT_META[variant] || VARIANT_META.danger;
        var icon = iconOverride || meta.icon;

        // Icon
        iconEl.className = icon + ' fs-3 ' + meta.iconColor;

        // Header background
        header.style.background = 'color-mix(in srgb, ' + meta.headerMix + ' 12%, transparent)';

        // Submit button
        submitBtn.className = 'btn ' + meta.btnClass + (variant === 'info' ? ' text-white' : '');
    }

    function onHidden() {
        submitBtn.disabled = false;
        submitBtn.textContent = submitBtn.dataset.originalLabel || 'Confirm';
        form.reset();
        var methodInput = form.querySelector('input[name="_method"]');
        if (methodInput) methodInput.remove();
        form.action = '#';
        modal._ctx = null;
        // Clean up stuck backdrop / modal-open if Bootstrap missed it
        document.body.classList.remove('modal-open');
        document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();