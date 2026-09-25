/**
 * Password strength indicator — vanilla JS, no dependencies.
 *
 * Finds all [data-policy="password-strength"] containers on the page,
 * locates the nearest password input, and updates the strength bar
 * + rule checklist on every input event.
 */
(function() {
    'use strict';

    function initPasswordStrength() {
        document.querySelectorAll('[data-policy="password-strength"]').forEach(function(container) {
            var input = container.parentElement && container.parentElement.querySelector('input[type="password"]');
            if (!input) return;

            // Show on focus if input has value
            input.addEventListener('focus', function() {
                if (input.value.length > 0) {
                    container.classList.remove('d-none');
                    updateStrength(input.value, container);
                }
            });

            // Show/hide on input
            input.addEventListener('input', function() {
                updateStrength(input.value, container);
            });

            // Hide on blur if empty
            input.addEventListener('blur', function() {
                if (input.value.trim() === '') {
                    container.classList.add('d-none');
                }
            });
        });
    }

    function updateStrength(password, container) {
        // Hide indicator when input is empty, show when user types.
        if (password.length === 0) {
            container.classList.add('d-none');
            return;
        }
        container.classList.remove('d-none');

        var rules = {
            length: password.length >= 12,
            upper: /[A-Z]/.test(password),
            lower: /[a-z]/.test(password),
            digit: /[0-9]/.test(password),
            symbol: /[^A-Za-z0-9]/.test(password),
        };

        var passed = Object.values(rules).filter(Boolean).length;
        var total = Object.keys(rules).length;
        var percent = total > 0 ? Math.round((passed / total) * 100) : 0;

        var label = percent <= 40 ? 'Weak' : (percent <= 60 ? 'Medium' : 'Strong');

        var bar = container.querySelector('.strength-bar');
        var labelEl = container.querySelector('.strength-label');

        if (bar) {
            bar.style.width = percent + '%';
            bar.className = 'progress-bar strength-bar ' + (passed <= 2 ? 'bg-danger' : passed <= 3 ? 'bg-warning' : 'bg-success');
        }

        if (labelEl) {
            labelEl.textContent = label;
        }

        container.querySelectorAll('.rules li').forEach(function(li) {
            var rule = li.getAttribute('data-rule');
            var isPassed = rules[rule];
            var icon = li.querySelector('i');
            if (icon) {
                icon.className = isPassed ? 'bi bi-check-circle-fill text-success me-1' : 'bi bi-circle me-1';
            }
            li.classList.toggle('text-success', isPassed);
            li.classList.toggle('text-muted', !isPassed);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPasswordStrength);
    } else {
        initPasswordStrength();
    }
})();
