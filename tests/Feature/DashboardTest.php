<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }
    public function test_dashboard_route_returns_200(): void
    {
        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_dashboard_uses_app_layout(): void
    {
        $response = $this->get('/dashboard');
        $response->assertViewIs('pages.dashboard');
        $response->assertViewHas('title');
    }

    public function test_dashboard_layout_contains_app_shell_elements(): void
    {
        $response = $this->get('/dashboard');
        $response->assertSee('app-wrapper');
        $response->assertSee('app-sidebar');
        $response->assertSee('app-header');
        $response->assertSee('app-footer');
    }

    public function test_dashboard_includes_theme_toggle(): void
    {
        $response = $this->get('/dashboard');
        $response->assertSee('theme-toggle');
        $response->assertSee('theme-icon');
        $response->assertSee('fa-moon');
    }

    public function test_dashboard_includes_confirmation_modal(): void
    {
        $response = $this->get('/dashboard');
        $response->assertSee('confirmModal');
    }

    public function test_dashboard_includes_sidebar_toggle(): void
    {
        $response = $this->get('/dashboard');
        $response->assertSee('data-lte-toggle');
    }

    public function test_dashboard_header_has_chrome_controls_and_search(): void
    {
        $response = $this->get('/dashboard');
        $content = $response->getContent();
        $headerEnd = strpos($content, '</header>');
        $header = substr($content, strpos($content, '<header'), $headerEnd - strpos($content, '<header') + 9);
        $this->assertStringContainsString('data-lte-toggle', $header);
        $this->assertStringContainsString('feature-search', $header);
        $this->assertStringContainsString('Search features', $header);
        $this->assertStringContainsString('fa-search', $header);
    }

    public function test_dashboard_includes_notification_in_header(): void
    {
        $response = $this->get('/dashboard');
        $response->assertSee('fa-bell');
    }

    public function test_dashboard_includes_user_menu(): void
    {
        $response = $this->get('/dashboard');
        $response->assertSee('dropdown');
        $response->assertSee('Logout');
    }

    public function test_dashboard_includes_content_header_with_title(): void
    {
        $response = $this->get('/dashboard');
        $response->assertSee('content-header');
        $response->assertSee('Dashboard');
        $response->assertSee('page-title');
    }

    public function test_dashboard_includes_sidebar_brand(): void
    {
        $response = $this->get('/dashboard');
        $response->assertSee('sidebar-brand');
    }
}
