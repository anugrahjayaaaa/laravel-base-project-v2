{{--
    Permission matrix shared by the role create and edit forms.

    $permissions        flat list of permissions: id, name (resource.action)
    $permissionGroups   resource prefix => list of the same permissions
    $selectedPermissions ids currently on the role (edit) or [] (create)

    Checkboxes post IDS, so the save action must cast them to int before
    syncPermissions — spatie resolves a string as a permission NAME and throws.
    Re-checked from old() so a validation failure keeps the admin's selection.

    ## Layout: split pane

    Groups in a left rail, that group's permissions on the right. The rail is
    worth it for the count badge: "which resources have access at all" is the
    question an admin actually has, and answering it by scrolling a flat list is
    what made this painful.

    ## Every checkbox is in the DOM at all times

    The load-bearing constraint here, not a style choice. The panel toggles
    visibility with `hidden`; it never fetches, never templates, and never
    removes an input. A checkbox absent from the DOM submits nothing, and an
    UNchecked checkbox also submits nothing — the two are identical on the wire.
    So a list that hid its inputs would silently UNCHECK every permission the
    admin could not see, and Save would be a data-loss button wearing a
    permission button's clothes.

    Same reasoning for the two rules below, which is why they are stated rather
    than left to taste:

      - Search filters ROWS, never the selection.
      - Select-all HIDES while a filter is active, instead of relabelling itself
        "select visible". With 11 permissions in `users` and a filter matching 2,
        an admin who cannot see the other 9 has no way to predict what a save
        will do to them.

    helper: resources/js/helpers/permission-matrix.js (vanilla, no dependencies)

    The whole matrix stays inside @can('roles.assign_permissions') INCLUDING the
    name="permissions[]" inputs. Same contract as the user role picker: the
    request refuses a posted permission set without that permission, so a form
    that hid only the checkboxes would post nothing — fine. A form that hid only
    the SAVE button would post the whole set — escalation through a rename.
--}}
@can('roles.assign_permissions')
<div id="permissionMatrix" class="mt-3">

    <label class="form-label">Permissions</label>

    @if (count($permissions) === 0)
        <x-ui.empty-state icon="fas fa-key" message="No permissions are defined." />
    @else
        <div class="border rounded overflow-hidden">
            <div class="row g-0">

                {{-- Left rail: one entry per resource group, with its count --}}
                <div class="col-12 col-md-4 col-lg-3 border-end bg-body-tertiary">
                    {{--
                        Vertical rail, not nav-pills.

                        The design system does define a nav-pills convention, and
                        it was the wrong thing to reach for: that convention is
                        the horizontal status filter on an index page, where
                        `active` carries `border-bottom border-primary border-2` —
                        an underline that reads correctly along a row and absurd
                        down a column. This rail is a list of resources, so it
                        uses the same typography and the same badge shapes with
                        the left edge as the active indicator instead.

                        Badge is the DS's two-state pair (`bg-primary text-white`
                        / `bg-secondary-subtle text-secondary`), NOT a third
                        "partially selected" colour. The partial state is
                        carried by the count text, which is the thing an admin
                        actually reads; inventing a `warning` state here is what
                        made this look foreign next to the other pages.
                    --}}
                    <div class="d-flex flex-column p-2 gap-2" role="list">
                        @foreach ($permissionGroups as $resource => $groupPermissions)
                            @php
                                $selected = old('permissions', $selectedPermissions ?? []);
                                $checkedCount = collect($groupPermissions)
                                    ->filter(fn ($permission) => in_array($permission->id, $selected))->count();
                            @endphp
                            <button type="button" role="listitem"
                                class="btn d-flex align-items-center justify-content-between gap-2 w-100 text-start
                                    px-3 py-2 border-0 fw-medium
                                    {{ $loop->first
                                        ? 'bg-body-tertiary text-primary border-start border-2 border-primary fw-semibold'
                                        : 'bg-transparent text-secondary' }}"
                                data-matrix-group="{{ $resource }}"
                                aria-current="{{ $loop->first ? 'true' : 'false' }}">
                                <span class="text-truncate">{{ $resource }}</span>
                                <span class="badge rounded-pill flex-shrink-0
                                    {{ $loop->first ? 'bg-primary text-white' : 'bg-secondary-subtle text-secondary' }}"
                                    data-matrix-count="{{ $resource }}">{{ $checkedCount }}/{{ count($groupPermissions) }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Right panel: search, then one section per group --}}
                <div class="col-12 col-md-8 col-lg-9 d-flex flex-column">

                    {{--
                        Search follows the header's live search (header.blade.php):
                        input-group with the icon affordance on the RIGHT. Leading
                        with it reads as a button that does nothing when clicked;
                        the field is a filter you type into.

                        Font Awesome `fas`, not `bi` — 25 views use fas, 16 use bi,
                        and a missing icon in the most-looked-at control on the
                        page is a worse defect than a mixed palette.
                    --}}
                    <div class="p-3 border-bottom">
                        <div class="input-group input-group-sm">
                            <input type="search" id="matrixSearch" class="form-control"
                                placeholder="Search permissions..." aria-label="Search permissions"
                                autocomplete="off" data-matrix-search>
                            <span class="input-group-text bg-body-tertiary">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                        </div>
                    </div>

                    <div class="p-3" style="max-height: 340px; overflow-y: auto;" data-matrix-panel>
                        @foreach ($permissionGroups as $resource => $groupPermissions)
                            <section data-matrix-section="{{ $resource }}" {{ $loop->first ? '' : 'hidden' }}>
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                    <h6 class="text-uppercase small fw-semibold text-muted mb-0">{{ $resource }}</h6>
                                    <div class="form-check form-check-inline m-0" data-matrix-groupall>
                                        <input class="form-check-input" type="checkbox"
                                            id="matrixAll{{ $loop->index }}"
                                            data-matrix-selectall="{{ $resource }}">
                                        <label class="form-check-label small" for="matrixAll{{ $loop->index }}">Select all</label>
                                    </div>
                                </div>

                                @foreach ($groupPermissions as $permission)
                                    @php $checked = in_array($permission->id, $selected); @endphp
                                    <div class="form-check" data-matrix-row
                                        data-matrix-name="{{ $permission->name }}"
                                        data-matrix-action="{{ str($permission->name)->after('.')->value() }}"
                                        data-matrix-resource="{{ $resource }}">
                                        <input class="form-check-input @error('permissions.' . $permission->id) is-invalid @enderror"
                                            type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                            id="permission_{{ $permission->id }}" {{ $checked ? 'checked' : '' }}>
                                        <label class="form-check-label small" for="permission_{{ $permission->id }}">
                                            {{ $permission->name }}
                                        </label>
                                    </div>
                                @endforeach
                            </section>
                        @endforeach

                        <div data-matrix-empty hidden>
                            <x-ui.empty-state icon="fas fa-magnifying-glass" message="No permission matches that search." />
                        </div>
                    </div>

                    {{--
                        Change summary, not decoration. With select-all hidden during a
                        search, this is the only place an admin can see what Save will
                        do to permissions they cannot currently see. Counts are computed
                        against the state the server rendered, so a validation failure
                        (old()) keeps the baseline honest instead of reporting every
                        restored checkbox as an addition.
                    --}}
                    <div class="p-3 border-top bg-body-tertiary d-flex flex-wrap align-items-center gap-3"
                        data-matrix-summary
                        data-matrix-original="{{ collect($selectedPermissions ?? [])->map(fn ($id) => (string) $id)->sort()->implode(',') }}">
                        <span class="small text-muted"><span data-matrix-total>0</span> selected</span>
                        <span class="small text-success d-none" data-matrix-added></span>
                        <span class="small text-danger d-none" data-matrix-removed></span>
                        <button type="button" class="btn btn-link btn-sm p-0 ms-auto d-none" data-matrix-clearfilter>
                            Clear search
                        </button>
                    </div>
                </div>
            </div>
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
