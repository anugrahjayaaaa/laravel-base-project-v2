<?php

namespace App\View\Composers;

use App\Support\FeatureCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Builds the sidebar menu, already filtered to what the viewer may reach.
 *
 * Filtering happens HERE and nowhere else. The sidebar partial used to carry its
 * own @can checks, which meant the same Gate lookup ran twice per item for the
 * same answer — and, worse, two places that could disagree about what a caller
 * can see. A menu that disagrees with the routes behind it shows links that 403,
 * or hides links that work.
 *
 * An item names the permission that guards it. An item with no 'permission' key
 * is unconditional: Dashboard and Profile stay reachable for every
 * authenticated user, and a decorative entry like the Labels group is not a
 * navigation target at all.
 *
 * An item may also name the feature flag its module is gated on (P7-D9). That
 * check runs before the permission check and applies either way: a module that
 * is switched off must vanish from the sidebar for everyone, including a
 * superadmin who passes every `can()`. Filtering here rather than in the partial
 * keeps one answer per item — the sidebar and the routes behind it cannot
 * disagree, which is the failure this class exists to prevent.
 *
 * An item whose route does not exist is dropped rather than rendered as a dead
 * '#' link, so the menu cannot advertise a screen that was never shipped.
 */
class AppMenuComposer
{
    /**
     * Shared menu groups for the AdminLTE sidebar.
     */
    public function compose(View $view): void
    {
        $view->with('menuGroups', $this->visibleGroups());

        // The header dropdown has its own Sessions link, outside $menuGroups.
        // It reads this rather than calling FeatureCatalog itself, so the sidebar
        // and the dropdown cannot disagree — a link the routes refuse with 403 is
        // worse than an absent link, and this is where that starts.
        $view->with('sessionsVisible', FeatureCatalog::isActive('sessions'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function visibleGroups(): array
    {
        $groups = array_map(
            fn (array $group) => [
                ...$group,
                'items' => array_values(array_filter(
                    $group['items'],
                    fn (array $item) => $this->visible($item)
                )),
            ],
            $this->groups()
        );

        return array_values(array_filter(
            $groups,
            // A group whose every item was filtered out is a heading with nothing
            // under it, which reads as a broken sidebar rather than a narrow one.
            fn (array $group) => $group['items'] !== []
        ));
    }

    /**
     * Can the viewer see this menu entry?
     *
     * Four ways an item survives: its feature flag is active, it names no
     * permission (Dashboard, Sessions, the decorative Labels group), it names one
     * the caller holds, or its route was never shipped — in which case it is
     * dropped, because a link to a screen that does not exist is worse than an
     * absent link.
     *
     * The flag check comes FIRST and applies even to an item with no
     * permission. Sessions is the case that matters: it carries no permission
     * (every authenticated user reaches it) but is flagged, so without this the
     * item would stay on a sidebar whose route now refuses with 403.
     */
    private function visible(array $item): bool
    {
        $route = $item['route'] ?? null;

        if ($route && $route !== '#' && ! Route::has($route)) {
            return false;
        }

        if (isset($item['feature']) && ! FeatureCatalog::isActive($item['feature'])) {
            return false;
        }

        if (! isset($item['permission'])) {
            return true;
        }

        return Auth::user()?->can($item['permission']) ?? false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groups(): array
    {
        return [
            [
                'label' => 'Application',
                'items' => [
                    // No 'permission': every authenticated user reaches Dashboard.
                    [
                        'label' => 'Dashboard',
                        'icon' => 'fas fa-gauge-high',
                        'route' => 'dashboard',
                        'active' => 'dashboard',
                    ],
                ],
            ],
            [
                'label' => 'Management',
                'items' => [
                    [
                        'label' => 'Users',
                        'icon' => 'fas fa-users',
                        'route' => 'users.index',
                        'active' => 'users.*',
                        'permission' => 'users.view',
                        'feature' => 'users',
                    ],
                    [
                        'label' => 'Roles',
                        'icon' => 'fas fa-shield-alt',
                        'route' => 'roles.index',
                        'active' => 'roles.*',
                        'permission' => 'roles.view',
                        'feature' => 'roles',
                    ],
                    [
                        'label' => 'Permissions',
                        'icon' => 'fas fa-key',
                        'route' => 'permissions.index',
                        'active' => 'permissions.*',
                        'permission' => 'permissions.view',
                        'feature' => 'permissions',
                    ],
                ],
            ],
            [
                'label' => 'System',
                'items' => [
                    [
                        'label' => 'Activity Logs',
                        'icon' => 'fas fa-binoculars',
                        'route' => 'activity-logs.index',
                        'active' => 'activity-logs.*',
                        // No route ships yet (P8), so this item is dropped by the
                        // Route::has check above regardless. The flag key is here
                        // so the entry keeps working — and keeps hiding — when
                        // that module lands.
                        'feature' => 'activity_logs',
                    ],
                    [
                        'label' => 'Settings',
                        'icon' => 'fas fa-gear',
                        'route' => 'settings.index',
                        'active' => 'settings.*',
                        'permission' => 'settings.view',
                        'feature' => 'settings',
                    ],
                    // Reachable by every authenticated user, like Dashboard.
                    [
                        'label' => 'Sessions',
                        'icon' => 'fas fa-laptop',
                        'route' => 'sessions',
                        'active' => 'sessions',
                        // No permission — every authenticated user reaches it —
                        // so the flag is the only thing that can hide it.
                        'feature' => 'sessions',
                    ],
                    [
                        'label' => 'Translations',
                        'icon' => 'fas fa-language',
                        'route' => 'translations.index',
                        'active' => 'translations.*',
                        // No route ships yet (P8) — dropped by Route::has.
                        'feature' => 'translations',
                    ],
                    [
                        'label' => 'Feature Flags',
                        'icon' => 'fas fa-toggle-on',
                        'route' => 'features.index',
                        'active' => 'features.*',
                        'permission' => 'features.view',
                    ],
                ],
            ],
            [
                'label' => 'Labels',
                'items' => [
                    [
                        'label' => 'Important',
                        'icon' => 'fas fa-circle text-danger',
                        'route' => '#',
                        'active' => false,
                    ],
                    [
                        'label' => 'Warning',
                        'icon' => 'fas fa-circle text-warning',
                        'route' => '#',
                        'active' => false,
                    ],
                    [
                        'label' => 'Informational',
                        'icon' => 'fas fa-circle text-info',
                        'route' => '#',
                        'active' => false,
                    ],
                ],
            ],
        ];
    }
}
