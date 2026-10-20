{{--
    One feature flag's on/off control.

    The switch is a confirmation-modal trigger, not an auto-submitting input:
    design-system.md §Confirmation Modal Convention lists "feature flag
    changes" as an action that requires confirmation, and ui-architecture.md
    repeats it. The intended new state rides in the action URL's `enabled`
    query param — `toggle_url`, built by FeatureIndexAction — so the shared
    modal driver can submit it without the driver learning anything about
    feature flags.

    A flag with no manager on the page renders as a badge instead of a dead
    switch — the same read-only shape pages/settings uses, where the inputs are
    removed rather than merely disabled.

    The DISABLE copy has to say what the switch takes away, because it takes more
    than the page: the toggle revokes every permission the flag owns from every
    role and user (`FeatureRevokePermissionsAction`), and switching the flag back
    on does not grant them again. `permissions` therefore rides in with the row,
    resolved by `FeatureIndexAction` — the view reads variables and nothing else.
--}}
@props([
    // ['slug' => 'users', 'label' => 'Users', 'description' => '…', 'enabled' => true,
    //  'toggle_url' => '/features/users/toggle?enabled=0',
    //  'permissions' => ['users.view', 'users.create', …]]
    //
    // toggle_url is handed in rather than built here: route() in a view is
    // controller logic in a view (Group A forbids it), and the intended new
    // state belongs with the other view data, not in a query string the
    // component reassembles. FeatureIndexAction builds both.
    'feature',
    'manageable' => false,
])

@if ($manageable)
    @php
        $enabling = ! $feature['enabled'];

        // Two short sentences, no more: what the switch takes away, then the way
        // back. Nothing is taken away on the way IN, so the enable copy is a
        // single sentence. On the way OUT the flag revokes every permission it
        // owns and re-enabling does not hand them back, and an operator who is
        // not told that takes a permission set off the whole install. The
        // permission is named when the flag owns exactly one — the two
        // single-permission modules (`pulse`, `health`) are the common case —
        // and counted when it owns more, because a confirmation modal is not a
        // table.
        $revoked = $enabling ? [] : ($feature['permissions'] ?? []);
        $permissionCount = count($revoked);

        $message = $enabling
            ? 'Enabling <b>__ITEM__</b> makes it reachable for everyone with the right permission.'
            : 'Disabling <b>__ITEM__</b> hides it for every user, including super-admin.';

        if ($permissionCount === 1) {
            $message = 'Disabling <b>__ITEM__</b> hides it for everyone and revokes'
                .' <code>'.$revoked[0].'</code> from all roles and users. Re-enabling does not restore'
                .' it — assign it again.';
        } elseif ($permissionCount > 1) {
            $message = 'Disabling <b>__ITEM__</b> hides it for everyone and revokes its'
                .' '.$permissionCount.' permissions from all roles and users. Re-enabling does not'
                .' restore them — assign them again.';
        }
    @endphp

    {{-- `d-inline-flex` centres the switch inside its cell.

         `.form-switch` is a block-level wrapper whose input is absolutely
         positioned against it, so a centred <td> centres only the WRAPPER and
         leaves the switch sitting at its left edge. Inlining the wrapper is what
         actually puts the control in the middle. --}}
    <div class="form-check form-switch d-inline-flex mb-0">
        <x-ui.confirm-action
            tag="input"
            :action="$feature['toggle_url']"
            method="POST"
            :item-name="$feature['label']"
            :label="$enabling ? 'Enable' : 'Disable'"
            :title="($enabling ? 'Enable' : 'Disable').' Feature?'"
            :message="$message"
            :variant="$enabling ? 'success' : 'warning'"
            icon="bi bi-toggles"
            type="checkbox"
            role="switch"
            class="form-check-input"
            :checked="$feature['enabled']"
            aria-label="{{ $feature['label'] }} ({{ $enabling ? 'enable' : 'disable' }})" />
    </div>
@else
    <x-ui.badge
        :variant="$feature['enabled'] ? 'success' : 'neutral'"
        :text="$feature['enabled'] ? 'Active' : 'Inactive'" />
@endif
