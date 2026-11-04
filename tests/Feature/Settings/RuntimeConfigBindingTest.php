<?php

namespace Tests\Feature\Settings;

use App\Models\SystemSetting;
use App\Providers\AppServiceProvider;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The settings that mirror a `config()` key have to reach config in a process
 * that did not save them. `boot()` is that process — every request and every
 * queue worker — and `mail_*` was missing from it, so a saved transport only
 * applied to the request that saved it and every later send used `.env`.
 */
class RuntimeConfigBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    public function test_a_process_that_did_not_save_the_settings_still_enforces_them(): void
    {
        SystemSetting::set('mail_host', 'smtp.stored.test');
        SystemSetting::set('mail_from_address', 'stored@example.test');

        // What a fresh process boots with: the .env-derived values.
        config([
            'mail.mailers.smtp.host' => 'smtp.env.test',
            'mail.from.address' => 'env@example.test',
        ]);

        // `set()` defers its persistent-cache bust to after commit, which never
        // fires inside the test transaction — bust here to read what a real
        // save leaves behind.
        SystemSetting::bustCache();

        AppServiceProvider::applyRuntimeSettings();

        $this->assertSame('smtp.stored.test', config('mail.mailers.smtp.host'));
        $this->assertSame('stored@example.test', config('mail.from.address'));
    }
}
