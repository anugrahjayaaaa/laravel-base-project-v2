/**
 * Form guards — replaces the inline `on*` handlers the CSP removed.
 *
 * Every listener here is DELEGATED off `document`, so it is live before any of
 * these forms has rendered and survives the notification poller swapping a
 * fragment into the DOM. The six submit buttons this covers carried
 * `onclick="this.disabled=true;this.form.submit()"` and one form carried
 * `onsubmit="return false;"` — seven inline handlers, not one of which a
 * `script-src` without `'unsafe-inline'` will run.
 *
 * The three sortable headers on the users table were the other inline handlers.
 * Those are navigation, so `pages/users/index` turned them into real links
 * instead of teaching this file to fake an anchor.
 *
 * Both behaviours are opt-in through a data attribute rather than applied to every
 * submit button in the application. That is deliberate: the bulk-action bar and the
 * confirmation modal submit programmatically and MUST keep working, and a blanket
 * "disable every submit button" would break both while looking like it worked.
 */
(function () {
    'use strict';

    // The submit event, not the click: pressing Enter in a text field fires
    // `submit` and no click at all, so a click listener would leave the keyboard
    // path unprotected.
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (form.matches('[data-prevent-submit]')) {
            event.preventDefault();
            return;
        }

        // Let the browser run constraint validation first. The inline handler
        // used `form.submit()`, which SKIPS validation — this form is stricter now
        // than it was, which is the direction to move.
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            return;
        }

        form.querySelectorAll('[data-disable-on-submit]').forEach(function (button) {
            button.disabled = true;
        });
    });
})();