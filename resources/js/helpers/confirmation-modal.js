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
    var modal, form, header, title, message, submitBtn;

    function init() {
        var el = document.getElementById(MODAL_ID);
        if (!el) return;

        modal = new bootstrap.Modal(el);
        form = el.querySelector('#confirmModalForm');
        header = el.querySelector('#confirmModalHeader');
        title = el.querySelector('#confirmModalTitle');
        message = el.querySelector('#confirmModalMessage');
        submitBtn = form.querySelector('button[type="submit"]');

        document.addEventListener('click', onTriggerClick);
        el.addEventListener('hidden.bs.modal', onHidden);
    }

    function onTriggerClick(e) {
        var trigger = e.target.closest('[data-bs-toggle="modal"][data-bs-target^="#confirmModal"]');
        if (!trigger) return;

        e.preventDefault();

        var action   = trigger.getAttribute('data-action') || '#';
        var method   = trigger.getAttribute('data-method') || 'POST';
        var variant  = trigger.getAttribute('data-variant') || 'danger';
        var titleTxt = trigger.getAttribute('data-title') || 'Confirm';
        var msgTxt   = trigger.getAttribute('data-message') || 'Are you sure?';
        var label    = trigger.getAttribute('data-label')
                    || trigger.getAttribute('data-action-label')
                    || 'Confirm';

        modal._ctx = { action, method, variant, trigger };

        title.textContent = titleTxt;
        message.textContent = msgTxt;
        applyVariant(variant);
        submitBtn.textContent = label;
        submitBtn.disabled = false;

        if (action && !action.startsWith('javascript:')) {
            form.action = action;
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

    function applyVariant(variant) {
        var color = variant === 'warning' ? 'var(--lbp-warning, #f59e0b)'
                  : variant === 'info'    ? 'var(--lbp-info, #0ea5e9)'
                  :                            'var(--lbp-danger, #ef4444)';
        header.style.background = 'color-mix(in srgb, ' + color + ' 12%, transparent)';

        var btnClass = variant === 'warning' ? 'btn-warning'
                     : variant === 'info'    ? 'btn-info'
                     :                           'btn-danger';
        submitBtn.className = 'btn ' + btnClass + (variant === 'info' ? ' text-white' : '');
    }

    function onHidden() {
        submitBtn.disabled = false;
        submitBtn.textContent = submitBtn.dataset.originalLabel || 'Confirm';
        form.reset();
        var methodInput = form.querySelector('input[name="_method"]');
        if (methodInput) methodInput.remove();
        form.action = '#';
        modal._ctx = null;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
