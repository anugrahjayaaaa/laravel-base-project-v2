{{--
    Action errors on an index page.

    A button-driven action has no field to attach a message to. DeleteRoleAction
    puts its refusal on 'name' and DeleteUserAction on 'email', the redirect goes
    back to the index, and the index renders neither — so the browser lands on a
    page that looks exactly as it did before, and "the modal opened, clicked
    confirm, nothing happened" is the whole of what the user can observe. The
    message was in the session the entire time.

    This is why it lives in a partial included by the index views rather than in
    the layout: a form page already renders @error next to the field, and a
    layout-level banner would print that same message twice there. A dismissible
    alert mirrors the success flash directly above it.
--}}
@if ($errors->any())
    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
        <i class="fas fa-exclamation-triangle me-1"></i>
        {{-- getBag('default')->all(), NOT $errors->all(): $errors is a
             ViewErrorBag and its all() returns array<string, string[]> keyed by
             field, which implode() cannot flatten. MessageBag::all() is the one
             that returns a flat array<string> of message strings. There is no
             allMessages() on either class in this framework version. --}}
        {{ implode(' ', $errors->getBag('default')->all()) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
