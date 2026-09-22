<?php

namespace App\View\Composers;

use Illuminate\View\View;

class AppMenuComposer
{
    /**
     * Shared menu groups for the AdminLTE sidebar.
     *
     * ponytail: menu is static for Phase 1. Extend to config/database when features multiply.
     */
    public function compose(View $view): void
    {
        $view->with('menuGroups', [
            [
                'label' => 'Application',
                'items' => [
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
                    ],
                    [
                        'label' => 'Roles',
                        'icon' => 'fas fa-shield-alt',
                        'route' => 'roles.index',
                        'active' => 'roles.*',
                    ],
                    [
                        'label' => 'Permissions',
                        'icon' => 'fas fa-key',
                        'route' => 'permissions.index',
                        'active' => 'permissions.*',
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
                    ],
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
        ]);
    }
}