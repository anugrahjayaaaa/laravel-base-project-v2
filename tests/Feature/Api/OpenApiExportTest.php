<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\OpenApi\ExportedDocument;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Dedoc\Scramble\CacheableGenerator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The docs routes serve the OpenAPI document exported at deploy.
 *
 * Why this exists rather than trusting `scramble:cache`: measured at ~700 ms
 * per request with the cache warm and without it. That command caches the
 * analysis; `spec()` builds the array on every visit. So the deploy runs
 * `scramble:export` once and `App\Support\OpenApi\ExportedDocument` serves the
 * file — and the two cases that matter are that the file wins over generation,
 * and that a broken or absent file degrades to generation instead of a 500.
 */
class OpenApiExportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->path = ExportedDocument::path();
        $this->removeExport();
    }

    protected function tearDown(): void
    {
        $this->removeExport();

        parent::tearDown();
    }

    public function test_the_exported_file_is_served_instead_of_a_generated_document(): void
    {
        $this->writeExport([
            'openapi' => '3.1.0',
            'info' => ['title' => 'exported at deploy', 'version' => 'v1'],
            'paths' => ['/v1/exported-probe' => ['get' => ['responses' => []]]],
        ]);

        $this->actingAs($this->admin())
            ->getJson('/docs/api.json')
            ->assertOk()
            ->assertJsonPath('info.title', 'exported at deploy')
            ->assertJsonStructure(['paths' => ['/v1/exported-probe']]);
    }

    public function test_the_ui_reads_the_exported_result_not_a_rebuilt_one(): void
    {
        $this->writeExport([
            'openapi' => '3.1.0',
            'info' => ['title' => 'exported at deploy', 'version' => 'v1'],
            'paths' => [],
        ]);

        $spec = $this->app->make(CacheableGenerator::class)
            ->generate(Scramble::getGeneratorConfig(Scramble::DEFAULT_API))
            ->spec();

        $this->assertSame('exported at deploy', $spec['info']['title']);
    }

    public function test_a_broken_export_falls_back_to_the_generated_document(): void
    {
        $this->writeExport('this is not json');

        $this->actingAs($this->admin())
            ->getJson('/docs/api.json')
            ->assertOk()
            ->assertJsonPath('info.version', 'v1')
            ->assertJsonStructure(['paths' => ['/v1/health' => ['get']]]);
    }

    /**
     * Integrity against the exported artifact, not only the served route.
     *
     * `ApiDocumentationTest` reads `/docs/api.json`, which under testing
     * degrades to live generation — so the drift gate never looked at a real
     * export until now. This runs the same command the deploy runs and
     * compares its output to the live route table: an export that shipped
     * stale (a route added after the last deploy) fails here.
     */
    public function test_a_real_export_documents_every_api_v1_route(): void
    {
        $this->artisan('scramble:export', ['--path' => $this->path])->assertSuccessful();

        $spec = json_decode(file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);

        $missing = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.v1.')) {
                continue;
            }

            // Scramble strips the documented `api` prefix: api/v1/users → /v1/users.
            $documented = '/'.preg_replace('#^api/#', '', $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if (! isset($spec['paths'][$documented][strtolower($method)])) {
                    $missing[] = $method.' '.$documented;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Routes missing from the exported document: '.implode(', ', $missing)
        );
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(SystemRole::ADMIN);

        return $admin;
    }

    /** @param  array<mixed, mixed>|string  $document */
    private function writeExport(array|string $document): void
    {
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }

        file_put_contents($this->path, is_string($document) ? $document : json_encode($document));
    }

    private function removeExport(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
