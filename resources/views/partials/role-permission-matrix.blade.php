{{--
    Permission matrix shared by the role create and edit forms.

    $permissions        flat list of permissions: id, name (resource.action)
    $permissionGroups   resource prefix => list of the same permissions
    $selectedPermissions ids currently on the role (edit) or [] (create)

    Checkboxes post IDS, so the save action must cast them to int before
    syncPermissions — spatie resolves a string as a permission NAME and throws.
    Re-checked from old() so a validation failure keeps the admin's selection.
--}}
<div class="mt-3">
    <label class="form-label">Permissions</label>
    @if (count($permissions) === 0)
        <x-ui.empty-state icon="fas fa-key" message="No permissions are defined." />
    @else
        <div class="border rounded p-3" style="max-height: 480px; overflow-y: auto;">
            @foreach ($permissionGroups as $resource => $groupPermissions)
                <div class="mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="text-uppercase small fw-semibold text-muted">{{ $resource }}</div>
                        {{-- ponytail: inline onclick, no external JS. Toggles all
                             checkboxes with name="permissions[]" in this group. --}}
                        <a href="#" class="small text-decoration-none text-muted"
                           onclick="const c=this.closest('.mb-2').querySelectorAll('input[name=\"permissions[]\"]');const all=[...c].every(cb=>cb.checked);c.forEach(cb=>cb.checked=!all);this.textContent=all?'Select all':'Deselect all';return false">
                            Select all
                        </a>
                    </div>
                    @foreach ($groupPermissions as $permission)
                        @php
                            $checked = in_array($permission->id, old('permissions', $selectedPermissions ?? []));
                        @endphp
                        <div class="form-check mb-1">
                            <input class="form-check-input @error('permissions.' . $permission->id) is-invalid @enderror"
                                type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                id="permission_{{ $permission->id }}" {{ $checked ? 'checked' : '' }}>
                            <label class="form-check-label small"
                                for="permission_{{ $permission->id }}">
                                {{ $permission->name }}
                            </label>
                        </div>
                    @endforeach
                    @unless ($loop->last)
                        <div class="border-bottom border-secondary-subtle mb-2"></div>
                    @endunless
                </div>
            @endforeach
        </div>
    @endif
    @error('permissions')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
    @error('permissions.*')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
