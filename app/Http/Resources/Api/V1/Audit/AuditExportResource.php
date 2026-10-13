<?php

namespace App\Http\Resources\Api\V1\Audit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One requested audit export.
 *
 * ## Never the file, only how to ask for it
 *
 * `path` is deliberately absent. It is a `storage/app/private` location, and a
 * disk path in an API response is a private-storage location handed to a second
 * consumer — and one that keeps working in that consumer's head long after the
 * nightly sweep unlinks the file. A client downloads through the route, exactly
 * as the web viewer's Download button does.
 *
 * `url` is therefore a PATH, not an absolute URL. `data` is a JSON column whose
 * writer is this application, and the value reaches an `href` downstream; a
 * client can resolve a path against its own origin, and an absolute URL here
 * would defeat a same-origin check it may want to make.
 *
 * @property-read \App\Models\AuditExport $resource
 */
class AuditExportResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => [
                // The enum's own value, plus its label, for the same reason
                // AuditExportStatus exists rather than a bare string column:
                // three states the UI reads differently, and a client choosing
                // between them by string comparison drifts into `== 'complete'`.
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            // What was asked for, not just when. Without this a row is
            // "Requested 2026-10-12 09:14" and the operator cannot tell which of
            // five files this is.
            'filters' => $this->filters,
            'row_count' => $this->row_count,

            'requested_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expired' => $this->isExpired(),

            // The model's single rule, so this resource cannot disagree with the
            // download route about what is available. Absent — not `false` —
            // when there is nothing: a null URL is a different answer from a URL
            // that would 410.
            'download_url' => $this->when(
                $this->isDownloadable(),
                route('api.v1.activity-logs.exports.download', $this->resource),
            ),
        ];
    }
}
