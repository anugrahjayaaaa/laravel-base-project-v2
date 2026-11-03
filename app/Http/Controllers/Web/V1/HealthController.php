<?php

namespace App\Http\Controllers\Web\V1;

use App\Concerns\FormatsByteSize;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\HealthCheck\HealthCheckService;
use App\Services\Storage\StorageCapacityGuard;
use Illuminate\Contracts\View\View;

/**
 * System health dashboard — the read-only web view over HealthCheckService.
 *
 * Phase 11 C1. Thin by design: the service already runs the checks and logs
 * failures with context (`health_check.*.failed`), and this page only renders
 * them. No FormRequest — a GET with no input has nothing to validate
 * (conventions §4e). No audit call — a read writes nothing (Phase 11 Group D).
 *
 * The API twin (`GET /api/v1/health`) stays unauthenticated and ungated: its
 * caller is an uptime monitor that cannot log in. This web page shows the same
 * checks inside the admin, so it carries the full `health.view` + `feature:health`
 * treatment (Phase 11 F3).
 */
class HealthController extends Controller
{
    use FormatsByteSize;

    /**
     * Render the current status of every core service.
     *
     * `$checks` arrives as `name => 'ok'|'fail'` (see HealthCheckService),
     * so the view iterates it without asking the service — or the database —
     * anything itself. The storage card reads the capacity gauge's numbers for
     * the used/used-and-available line.
     */
    public function index(HealthCheckService $health, StorageCapacityGuard $capacity): View
    {
        $checks = $health->check();
        $isHealthy = $health->isHealthy($checks);
        $settings = SystemSetting::getLive();

        $storage = $capacity->cachedMetrics();
        foreach (['used', 'available', 'total'] as $key) {
            $storage[$key.'_label'] = $this->bytesLabel($storage[$key]);
        }

        return view('pages.health.index', [
            'checks' => $checks,
            'isHealthy' => $isHealthy,
            'storage' => $storage,
            'settings' => $settings,
        ]);
    }
}
