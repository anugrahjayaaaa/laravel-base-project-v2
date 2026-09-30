{{--
    Role picker shared by the create and edit user forms.

    $selectedRoles is the list of role names to check: the current assignment on
    edit, an empty array on create. Re-checked from old() so a validation failure
    or a cooldown rejection keeps what the admin picked.

    The hidden input is load-bearing, not a form idiom. An unchecked checkbox
    sends NOTHING, so unchecking every box posts no `roles` key at all — and
    UpdateUserAction reads `array_key_exists('roles', $data)` to tell "the admin
    removed them all" apart from "this caller never sent roles". Without the
    hidden input the two are indistinguishable and removing every role silently
    does nothing. It must come BEFORE the checkboxes: PHP takes the LAST value
    for a repeated name, so a hidden input after them would win every time.

    The whole picker sits inside @can('users.assign_roles'), INCLUDING the hidden
    input. That is what makes hiding it safe: a caller without the permission
    posts no `roles` key at all, which UpdateUserAction reads as "leave the roles
    alone". Rendering the hidden input outside the @can would post `roles: ['']`
    → normalised to `roles: []` → and silently strip every role from the account
    as a side effect of saving an unrelated field. The gate would look right and
    the damage would be exactly the bug the hidden input exists to prevent.
--}}
@can('users.assign_roles')
<div class="mt-3">
    <label class="form-label">Roles</label>
    <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
        <input type="hidden" name="roles[]" value="">
        @forelse ($roles as $role)
            @php $checked = in_array($role->name, old('roles', $selectedRoles ?? []), true); @endphp
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="roles[]"
                    value="{{ $role->name }}" id="role_{{ $loop->index }}"
                    {{ $checked ? 'checked' : '' }}>
                <label class="form-check-label small" for="role_{{ $loop->index }}">
                    {{ $role->name }}
                </label>
            </div>
        @empty
            <span class="text-muted small">No roles defined.</span>
        @endforelse
    </div>
    @error('roles')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror

    {{--
        P6-E5. AssignRolesAction refuses to add OR remove superadmin unless the
        payload carries an explicit confirmation, so the form has to be able to
        send one — otherwise the guard silently makes superadmin unassignable
        through the UI, which is a lockout wearing a safety feature.

        Rendered only when superadmin is actually among the assignable roles,
        and only for a caller who may assign roles at all: an admin who cannot
        assign roles never sees this block, so the field cannot become a
        side-channel.

        `old()` re-checks it so a refused save does not silently untick the box
        and make the retry impossible.
    --}}
    @if (collect($roles)->contains(fn ($role) => $role->name === \App\Support\SystemRole::SUPERADMIN))
        <div class="form-check mt-2 pt-2 border-top">
            <input class="form-check-input" type="checkbox" name="confirm_superadmin" value="1"
                id="confirm_superadmin" {{ old('confirm_superadmin') ? 'checked' : '' }}>
            <label class="form-check-label small text-danger" for="confirm_superadmin">
                <i class="bi bi-shield-exclamation me-1"></i>
                Confirm granting or removing the <strong>superadmin</strong> role
            </label>
            <div class="form-text">
                Required whenever this save adds or removes superadmin. Tick it deliberately.
            </div>
            @error('confirm_superadmin')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>
    @endif
</div>
@endcan
