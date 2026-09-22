<?php

namespace App\Http\Controllers\Web\V1;

use App\Models\SystemSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    public function index()
    {
        $settings = [
            'allow_username_change' => SystemSetting::getBool('allow_username_change', true),
            'allow_email_change' => SystemSetting::getBool('allow_email_change', true),
            'username_change_cooldown_days' => SystemSetting::getInt('username_change_cooldown_days', 30),
            'email_change_cooldown_days' => SystemSetting::getInt('email_change_cooldown_days', 30),
        ];

        return view('pages.settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'allow_username_change' => ['boolean'],
            'allow_email_change' => ['boolean'],
            'username_change_cooldown_days' => ['integer', 'min:1', 'max:365'],
            'email_change_cooldown_days' => ['integer', 'min:1', 'max:365'],
        ]);

        SystemSetting::set('allow_username_change', $request->boolean('allow_username_change') ? 'true' : 'false');

        SystemSetting::set('allow_email_change', $request->boolean('allow_email_change') ? 'true' : 'false');

        SystemSetting::set('username_change_cooldown_days', (string) $request->integer('username_change_cooldown_days', 30));

        SystemSetting::set('email_change_cooldown_days', (string) $request->integer('email_change_cooldown_days', 30));

        return back()->with('status', 'Settings updated successfully.');
    }
}
