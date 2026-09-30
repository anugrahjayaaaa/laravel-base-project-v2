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
--}}
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
</div>
