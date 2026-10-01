<?php

namespace App\View\Composers;

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
     * Three ways an item survives: it names no permission (Dashboard, Sessions,
     * the decorative Labels group), it names one the caller holds, or its route
     * was never shipped — in which case it is dropped, because a link to a screen
     * that does not exist is worse than an absent link.
     */
    private function visible(array $item): bool
    {
        $route = $item['route'] ?? null;

        if ($route && $route !== '#' && ! Route::has($route)) {
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
                    ],
                    [
                        'label' => 'Roles',
                        'icon' => 'fas fa-shield-alt',
                        'route' => 'roles.index',
                        'active' => 'roles.*',
                        'permission' => 'roles.view',
                    ],
                    [
                        'label' => 'Permissions',
                        'icon' => 'fas fa-key',
                        'route' => 'permissions.index',
                        'active' => 'permissions.*',
                        'permission' => 'permissions.view',
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
                    ],
                    [
                        'label' => 'Settings',
                        'icon' => 'fas fa-gear',
                        'route' => 'settings.index',
                        'active' => 'settings.*',
                        'permission' => 'settings.view',
                    ],
                    // Reachable by every authenticated user, like Dashboard.
                    [
                        'label' => 'Sessions',
                        'icon' => 'fas fa-laptop',
                        'route' => 'sessions',
                        'active' => 'sessions',
                    ],
                    [
                        'label' => 'Translations',
                        'icon' => 'fas fa-language',
                        'route' => 'translations.index',
                        'active' => 'translations.*',
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
