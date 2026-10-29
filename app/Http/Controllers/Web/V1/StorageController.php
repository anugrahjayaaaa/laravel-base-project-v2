<?php

namespace App\Http\Controllers\Web\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve one file from the private `secure_documents` disk.
 *
 * The route requires `can:storage.view` (who may read private media) and
 * `signed` (which one path, for how long). The signature is not a substitute
 * for the permission — it is a time-bound second factor on top of it, so a
 * leaked link expires without rotating who holds `storage.view`.
 */
class StorageController extends Controller
{
    /**
     * Stream a file, refusing anything that escapes the disk root.
     *
     * Laravel's local driver already normalises `..`, but checking here keeps
     * the refusal a 404 rather than an exception from the adapter, and it makes
     * the trust boundary explicit at the entry point.
     */
    public function show(string $path): StreamedResponse
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        abort_if($path === '' || str_contains($path, '..') || str_contains($path, "\0"), 404);

        $disk = Storage::disk('secure_documents');

        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }
}
