{{--
    Feature Flags — every module's kill switch, grouped by module.

    Contract with FeatureIndexAction (no queries, no route() here):
      $featureGroups  ['Users' => [['slug','label','description','enabled',
                                   'toggle_url'], …], …]
      $totalFeatures  int
      $enabledCount   int
      $disabledCount  int

    `toggle_url` is the toggle URL carrying the INTENDED new state
    (`?enabled=0` when the flag is currently on). Building it here would be
    route logic in a view, and the component cannot know which way the flag is
    about to move without re-deriving it.

    The grouping key comes from the flag catalogue, not from a query in this
    file, so the page renders whatever the engine (Pennant) resolves — see
    docs/base/features/feature-flags.md.
--}}
@extends('layouts.app', ['title' => 'Feature Flags'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Feature Flags</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    Manage global module and feature availability.
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Feature Flags</li>
            </ol>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-check me-1"></i>
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-exclamation me-1"></i>
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Read-only banner, matching the settings page: the switches below are
         replaced by badges, so there is nothing on screen that looks editable
         and silently discards input. --}}
    @cannot('features.manage')
        <div class="alert alert-info d-flex align-items-center gap-2 mb-3" role="alert">
            <i class="fas fa-circle-info"></i>
            <span class="mb-0">You can view these flags but not change them.</span>
        </div>
    @endcannot

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Features</div>
                    <div class="fs-3 fw-bold lh-1">{{ $totalFeatures }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Enabled</div>
                    <div class="fs-3 fw-bold lh-1 text-success-emphasis">{{ $enabledCount }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Disabled</div>
                    <div class="fs-3 fw-bold lh-1 {{ $disabledCount > 0 ? 'text-warning-emphasis' : '' }}">
                        {{ $disabledCount }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Modules</div>
                    <div class="fs-3 fw-bold lh-1">{{ count($featureGroups) }}</div>
                </div>
            </div>
        </div>
    </div>

    @if (empty($featureGroups))
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <x-ui.empty-state
                    icon="fas fa-toggle-off"
                    message="No feature flags are declared." />
            </div>
        </div>
    @endif

    {{-- Bulk bar (P7-F5). Outside the loop, once, because bulk-actions.js reads
         it by id and one bar drives every card's checkboxes.

         `data-bulk-mixed="disable_feature"` is the rule that matters here.
         With 3 active and 2 inactive flags selected, the ONLY action safe for
         all five is disable — enabling would flip flags the operator did not
         mean to touch, and for a kill switch an accidental enable is the more
         expensive direction. The driver reduces any mixed selection to this one
         action, so the dropdown cannot offer the wrong one.

         `data-bulk-keys` points the confirm modal at the flag copy in
         action-config.js; the words "They can be restored later" are about a
         user, not a module. --}}
    @if ($manageable && ! empty($featureGroups))
        <form method="POST" class="d-flex align-items-center" onsubmit="return false;">
            @csrf
            {{-- The bar sits ABOVE the module cards here rather than inside a
                 card-header, so it needs its own bottom margin or it butts
                 against the first card. On the div, not the form: the driver
                 toggles `d-none` on `#bulkBar`, so a margin on the form would
                 leave a gap on every page load with nothing selected. --}}
            <div id="bulkBar" class="d-none align-items-center gap-2 flex-wrap ms-auto mb-3"
                data-bulk-route="{{ route('features.bulk-action') }}" data-bulk-field="features[]"
                data-bulk-noun="feature flag"
                data-bulk-mixed="disable_feature"
                data-bulk-states='@json(['active' => ['disable_feature'], 'inactive' => ['enable_feature']])'
                data-bulk-keys='@json(['enable_feature' => 'enable_feature', 'disable_feature' => 'disable_feature'])'>
                <span class="text-muted fs-7">Selected: <strong id="bulkCount">0</strong></span>
                <select id="bulkAction" class="form-select form-select-sm d-inline-block" style="width:auto">
                    <option value="">-- Action --</option>
                    <option value="disable_feature">Disable</option>
                    <option value="enable_feature">Enable</option>
                </select>
                <button type="button" class="btn btn-sm btn-primary" id="bulkApplyBtn">Apply</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkClearBtn">Clear</button>
            </div>
        </form>
    @endif

    {{-- One card per module, and every card's table pins its columns the same
         way.

         Two independent tables default to auto layout, so each sizes its columns
         from its OWN content: the Key column lands in a different place under
         Users than under Monitoring, and the five columns never line up down the
         page. The fix is not fewer tables — it is the same <colgroup> on every
         one of them, with `table-fixed` so those widths are authoritative
         instead of being a suggestion the content can overrule.

         Keep the colgroup identical across the loop. If one card's copy drifts,
         the columns go out of alignment again and nothing fails. --}}
    @foreach ($featureGroups as $module => $features)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3">
                <h5 class="card-title mb-0 fw-semibold">{{ $module }}</h5>
            </div>
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-fixed table-hover align-middle mb-0">
                        <colgroup>
                            <col style="width: 36px">
                            <col style="width: 22%">
                            <col style="width: 18%">
                            <col>
                            <col style="width: 12%">
                            <col style="width: 12%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col" class="align-middle">
                                    <input type="checkbox" class="bulk-check-all"
                                        aria-label="Select every feature flag">
                                </th>
                                <th scope="col" class="align-middle">Feature</th>
                                <th scope="col" class="align-middle">Key</th>
                                <th scope="col" class="align-middle">Description</th>
                                <th scope="col" class="align-middle">Status</th>
                                <th scope="col" class="align-middle text-center">Toggle</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($features as $feature)
                                <tr>
                                    <td>
                                        {{-- data-status is what bulk-actions.js groups by, so
                                             the dropdown offers the actions valid for the
                                             selection: an active row offers disable, an
                                             inactive one offers enable. --}}
                                        <input type="checkbox" class="bulk-check"
                                            value="{{ $feature['slug'] }}"
                                            data-status="{{ $feature['enabled'] ? 'active' : 'inactive' }}"
                                            aria-label="Select {{ $feature['label'] }}">
                                    </td>
                                    <td class="fw-medium">{{ $feature['label'] }}</td>
                                    <td><code>{{ $feature['slug'] }}</code></td>
                                    <td class="text-muted">
                                        {{ $feature['description'] ?: '—' }}
                                    </td>
                                    <td>
                                        <x-ui.badge
                                            :variant="$feature['enabled'] ? 'success' : 'neutral'"
                                            :text="$feature['enabled'] ? 'Active' : 'Inactive'" />
                                    </td>
                                    {{-- Centred, not right-aligned: `text-end` pinned the
                                         switch to the far edge, which is where the eye
                                         does not look for a control, and it left the
                                         column reading as empty space. --}}
                                    <td class="text-center">
                                        <x-ui.feature-toggle
                                            :feature="$feature"
                                            :manageable="$manageable" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
@endsection
