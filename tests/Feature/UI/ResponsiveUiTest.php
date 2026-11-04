<?php

namespace Tests\Feature\UI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Phase 15 P15-A3: the responsive and theme attributes the design system pins.
 *
 * Source sweeps rather than rendered assertions wherever the property is a
 * property of the markup written: a table added tomorrow is scanned the day it
 * is written, without a page test that has to know about it.
 */
class ResponsiveUiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string> relative path => source with Blade comments stripped
     */
    private function applicationViews(): array
    {
        $files = Finder::create()
            ->files()
            ->in(resource_path('views'))
            ->name('*.blade.php')
            ->exclude('vendor');

        $views = [];

        foreach ($files as $file) {
            // Comments stripped first: this file's own explanation of the rule
            // names the markup the rule forbids and would trip itself.
            $views[$file->getRelativePathname()] = (string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                $file->getContents()
            );
        }

        return $views;
    }

    public function test_every_table_sits_in_a_scroll_wrapper(): void
    {
        $tables = 0;

        foreach ($this->applicationViews() as $path => $source) {
            $tableCount = substr_count($source, '<table');
            $wrapperCount = substr_count($source, 'table-responsive');

            $tables += $tableCount;

            $this->assertGreaterThanOrEqual(
                $tableCount,
                $wrapperCount,
                "{$path} renders {$tableCount} table(s) across {$wrapperCount} responsive wrapper(s)"
            );
        }

        $this->assertGreaterThan(8, $tables, 'the sweep found too few tables to be meaningful');
    }

    public function test_the_scrollable_filter_rows_stay_nowrap(): void
    {
        foreach (['pages/users/index.blade.php', 'pages/roles/index.blade.php'] as $page) {
            $source = (string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                (string) file_get_contents(resource_path('views/'.$page))
            );

            // `flex-nowrap` without `overflow-auto` clips the last filter; the
            // pair is what makes the row scroll instead of squeeze.
            $this->assertMatchesRegularExpression(
                '/d-flex flex-nowrap overflow-auto[^"]*"/',
                $source,
                "{$page} lost its nowrap scroll row"
            );
        }
    }

    public function test_every_inline_script_carries_a_csp_nonce(): void
    {
        $inline = 0;

        foreach ($this->applicationViews() as $path => $source) {
            foreach (preg_split('/\R/', $source) ?: [] as $line) {
                if (! str_contains($line, '<script') || str_contains($line, 'src=')) {
                    continue;
                }

                $inline++;

                $this->assertStringContainsString(
                    '@nonce',
                    $line,
                    "{$path} has an inline script without @nonce — the CSP blocks it, so the page silently loses the behaviour"
                );
            }
        }

        $this->assertGreaterThan(5, $inline, 'the sweep found too few inline scripts to be meaningful');
    }

    public function test_the_confirmation_trigger_renders_each_modal_variant(): void
    {
        foreach (['warning', 'danger', 'success'] as $variant) {
            $html = Blade::render('<x-ui.confirm-action action="/x" label="Go" variant="'.$variant.'" />');

            $this->assertStringContainsString('data-variant="'.$variant.'"', $html, "variant {$variant} is not emitted");
            $this->assertStringContainsString('data-bs-target="#confirmModal"', $html, 'the trigger points at no modal');
        }
    }
}
