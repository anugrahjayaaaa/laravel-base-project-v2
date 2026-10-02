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
--}}
@props([
    // ['slug' => 'users', 'label' => 'Users', 'description' => '…', 'enabled' => true,
    //  'toggle_url' => '/features/users/toggle?enabled=0']
    //
    // toggle_url is handed in rather than built here: route() in a view is
    // controller logic in a view (Group A forbids it), and the intended new
    // state belongs with the other view data, not in a query string the
    // component reassembles. FeatureIndexAction builds both.
    'feature',
    'manageable' => false,
])

@if ($manageable)
    @php($enabling = ! $feature['enabled'])

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
            :message="$enabling
                ? 'Enabling <b>__ITEM__</b> makes it reachable for everyone with the right permission.'
                : 'Disabling <b>__ITEM__</b> hides it for every user, including super-admin.'"
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
