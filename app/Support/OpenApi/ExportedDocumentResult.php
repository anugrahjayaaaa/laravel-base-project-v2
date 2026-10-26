<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\GeneratorResult;
use Dedoc\Scramble\OpenApiVersion;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\ProNudge\ProNudgeCollector;

/**
 * A `GeneratorResult` whose spec is the exported array.
 *
 * The docs UI route asks the result for `spec()` before rendering, so returning
 * the file only from `__invoke()` would leave that page building the whole
 * document. Here the same answer costs an array read on both routes.
 */
final class ExportedDocumentResult extends GeneratorResult
{
    /** @param  array<mixed, mixed>  $document */
    public function __construct(private readonly array $document)
    {
        parent::__construct(new OpenApi('3.1.0'), collect(), new ProNudgeCollector());
    }

    /** @return array<mixed, mixed> */
    public function spec(OpenApiVersion $version = OpenApiVersion::V3_1): array
    {
        return $this->document;
    }
}
