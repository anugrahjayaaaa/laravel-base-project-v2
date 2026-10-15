<?php

namespace App\Http\Resources\Api\V1\Notification;

use App\Support\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One notification, for API clients.
 *
 * ## `subject` and `lines`, because that is the contract
 *
 * Every notification class in this project builds `toArray()` as
 * `subject` + `lines[]` for the bell dropdown, and adding a parallel shape here
 * would be the second renderer of the same content. A client that cannot render
 * a two-line message is a client that needs a different product.
 *
 * ## `action.url` is a PATH, and it is filtered
 *
 * `AuditExportReadyNotification` already argues the path-not-URL point in its
 * own docblock. What it cannot argue from one channel is safety: `data` is a
 * JSON column whose writer is the application, and this value ends up in an
 * `href` on the client. `SafeUrl::actionFor()` applies the same same-origin rule
 * the web inbox applies — the SAME one, extracted so the two cannot drift.
 *
 * @property-read \Illuminate\Notifications\DatabaseNotification $resource
 */
class NotificationResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'subject' => $this->data['subject'] ?? null,
            'lines' => $this->data['lines'] ?? [],

            // The optional second half of the contract: a notification that
            // leaves something to DO says so. Without these the notice arrives
            // and nothing leads anywhere — which is exactly what Group E shipped
            // the first time.
            //
            // `url` is NOT `$data['url']` verbatim. That is a JSON column whose
            // writer is the application, and this value ends up in an `href` on
            // the client: `javascript:alert(1)` is script, and `//evil.example`
            // has the syntax of a path and the destination of a link somewhere
            // else. `SafeUrl` is the same filter the web inbox applies — the
            // same one, not a second copy, because a second copy of a security
            // check is the copy that gets forgotten when one is edited.
            'action' => $this->when(
                SafeUrl::actionFor($this->resource) !== null,
                fn (): array => SafeUrl::actionFor($this->resource),
            ),

            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
