<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\CacheableGenerator;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\GeneratorResult;

/**
 * Serves the OpenAPI document exported at deploy instead of building it per
 * request.
 *
 * Measured before this existed: `/docs/api.json` took ~700 ms per request
 * whether or not `scramble:cache` had run. That command caches Scramble's
 * *analysis*; the cost is `spec()`, which turns the analysis into the spec
 * array and runs on every request either way. The deploy runs `scramble:export`
 * once and this reads the file.
 *
 * A missing or unreadable file falls back to the live generator, so a deploy
 * that skipped the export — or a rollback that removed it — serves correct docs
 * slowly rather than nothing.
 *
 * shortcut: one documented API per deployment, so one file. A second API needs
 * the artefact keyed by API name, not a second path on this class.
 */
final class ExportedDocument extends CacheableGenerator
{
    public function __construct(Generator $generator, private readonly string $path)
    {
        parent::__construct($generator);
    }

    /**
     * The path the deploy writes and both docs routes read. The environment is
     * part of the name so an export made in development can never be served, or
     * read by the test suite, in another environment.
     */
    public static function path(): string
    {
        return storage_path('app/openapi.'.app()->environment().'.json');
    }

    public function __invoke(?GeneratorConfig $config = null): array
    {
        return $this->exported() ?? parent::__invoke($config);
    }

    public function generate(GeneratorConfig $config): GeneratorResult
    {
        $document = $this->exported();

        return $document === null ? parent::generate($config) : new ExportedDocumentResult($document);
    }

    /** @return array<mixed, mixed>|null */
    private function exported(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $document = json_decode((string) file_get_contents($this->path), true);

        return is_array($document) ? $document : null;
    }
}
