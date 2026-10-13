<?php

namespace App\Http\Resources\Api\V1\Audit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit row, for API clients.
 *
 * ## Reuses the model's own resolvers, exactly as the export CSV does
 *
 * `causerLabel()` and `subjectLabel()` are the same methods the viewer and the
 * CSV call, which is the point: a deleted actor is named by id rather than
 * rendered "Anonymous", and a subject-less event is `null` rather than a zero —
 * in all three surfaces, because there is one resolver rather than three
 * translations of it.
 *
 * ## No badge colours
 *
 * `eventBadgeVariant()` and `sourceBadgeVariant()` exist for AdminLTE and are
 * deliberately absent. A colour is a presentation decision, and the thing this
 * project keeps getting wrong is a second renderer of presentation drifting
 * from the first. An API client picks its own colour; sending one invites it to
 * render an audit event by the same colour the admin UI happens to use.
 *
 * @property-read \App\Models\Activity $resource
 */
class ActivityResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->created_at?->toIso8601String(),
            'event' => $this->eventName(),
            'description' => $this->description,

            // Actor. The label is for a human; `causer_id` and `causer_type` are
            // for a client that wants to link back, and the polymorphic alias
            // (never an FQCN — see `App\Support\MorphMap`) is what makes that
            // link stable across a model rename.
            'actor' => $this->causerLabel(),
            'causer_id' => $this->causer_id,
            'causer_type' => $this->causer_type,

            // Target. `subject_id` is a string, not an int: a UUID-keyed model is
            // a legitimate subject and casting it to an int would answer 0.
            'target' => $this->subjectLabel(),
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,

            'source' => $this->source(),
            'ip' => $this->extra('ip'),
            'request_id' => $this->extra('request_id'),

            // Raw JSON, not a flattened map. The CSV writes it verbatim for the
            // same reason: `{"changedFields":{"email":…}}` survives a reader that
            // does not know this application's flattening convention, and a
            // client that does know it can walk the structure.
            'properties' => $this->properties?->all(),
        ];
    }
}
