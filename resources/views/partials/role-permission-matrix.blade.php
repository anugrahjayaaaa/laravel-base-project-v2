{{--
    Permission matrix shared by the role create and edit forms.

    $permissions        flat list of permissions: id, name (resource.action)
    $permissionGroups   resource prefix => list of the same permissions
    $selectedPermissions ids currently on the role (edit) or [] (create)

    Checkboxes post IDS, so the save action must cast them to int before
    syncPermissions — spatie resolves a string as a permission NAME and throws.
    Re-checked from old() so a validation failure keeps the admin's selection.

    The whole matrix is inside @can('roles.assign_permissions') INCLUDING the
    name="permissions[]" inputs. Same contract as the user role picker: the
    request refuses a posted permission set without that permission, so a form
    that hid only the checkboxes would post nothing — fine. A form that hid only
    the SAVE button would post the whole set — escalation through a rename.
    Neither the @can nor the request is optional: the view keeps a rename-only
    admin from being offered a control the server refuses, and the request is
    what actually stops a hand-rolled POST.
--}}
@can('roles.assign_permissions')
<div class="mt-3">
    <label class="form-label">Permissions</label>
    @if (count($permissions) === 0)
        <x-ui.empty-state icon="fas fa-key" message="No permissions are defined." />
    @else
        <div class="border rounded p-3" style="max-height: 320px; overflow-y: auto;">
            @foreach ($permissionGroups as $resource => $groupPermissions)
                <div class="mb-3">
                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ $resource }}</div>
                    @foreach ($groupPermissions as $permission)
                        @php
                            $checked = in_array($permission->id, old('permissions', $selectedPermissions ?? []));
                        @endphp
                        <div class="form-check">
                            <input class="form-check-input @error('permissions.' . $permission->id) is-invalid @enderror"
                                type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                id="permission_{{ $permission->id }}" {{ $checked ? 'checked' : '' }}>
                            <label class="form-check-label small" for="permission_{{ $permission->id }}">
                                {{ $permission->name }}
                            </label>
                        </div>
                    @endforeach
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
@endcan
