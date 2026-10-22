<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Actions\V1\Notification\NotificationInboxAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Notification\NotificationResource;
use App\Support\ApiError;
use App\Support\UnreadNotificationCount;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The notification INBOX over the API.
 *
 * ## Why this existed at all
 *
 * The gap audit found the API could read a user's notification *settings* and
 * could not read a single notification. That is backwards: the settings say
 * which channels are switched on, and the notifications are the thing the
 * switch is about. A mobile client could inspect the preference and had no way
 * to see what it produced.
 *
 * ## Marking read is the same operation here as in the web view
 *
 * `NotificationInboxAction` owns it on both channels. That matters more than it
 * sounds: marking read has to invalidate the cached count and the cached recent
 * list, or the bell goes stale, and the web path is the one already proven to
 * clear BOTH (see `UnreadNotificationCount::forgetAllFor()` — clearing only the
 * count was the worst version of that bug).
 *
 * ## No permission gate, deliberately
 *
 * `/notifications/inbox` on the web is flagged and auth-gated but carries no
 * permission: a notification belongs to the person it was sent to, and the
 * scoping is the user's own relation. "Whose inbox is this" is answered by the
 * token, not by a permission name, so there is nothing here to authorize. This
 * matches Phase 9's own decision and does not widen it — the scope is the same
 * relation, not a filter this class forgot.
 */
class NotificationInboxController extends Controller
{
    public function __construct(
        private readonly NotificationInboxAction $inboxAction,
    ) {
    }

    /**
     * The viewer's own notifications, newest first.
     *
     * The route carries no permission, but it does sit behind
     * `feature:notifications`, which refuses with 403 — a gate inference cannot
     * see.
     */
    #[Response(status: 403, type: ApiError::DOC_TYPE)]
    public function index(Request $request): JsonResponse
    {
        $inbox = $this->inboxAction->inbox($request->user());

        return $this->respond('', 200, [
            'notifications' => NotificationResource::collection($inbox['notifications']->items()),
            'pagination' => $this->paginationFor($inbox['notifications']),
            'unread_count' => UnreadNotificationCount::for($request->user()),
        ]);
    }

    /**
     * The unread badge, for a client polling.
     *
     * `latest_id` alongside the count is what lets a client skip the expensive
     * half: nothing new arrived means the count moved but no row did — the same
     * shape the web poll's endpoint returns, and for the same reason.
     */
    #[Response(status: 403, type: ApiError::DOC_TYPE)]
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->respond('', 200, [
            'unread_count' => UnreadNotificationCount::for($request->user()),
            'latest_id' => UnreadNotificationCount::latestIdFor($request->user()),
        ]);
    }

    /**
     * Mark one read.
     */
    #[Response(status: 403, type: ApiError::DOC_TYPE)]
    public function markAsRead(Request $request, string $id, NotificationInboxAction $action): JsonResponse
    {
        $marked = $action->markAsRead($request->user(), $id);

        // 404 rather than a silent false: the caller asked for an id, and "no
        // such notification" is a different answer from "already read". The web
        // path can afford a flash message for that; a client reading a status
        // code cannot afford to guess which one it got.
        if (! $marked && $request->user()->notifications()->whereKey($id)->doesntExist()) {
            abort(404, 'Notification not found.');
        }

        return $this->respond($marked ? 'Notification marked as read.' : 'That notification was already read.', 200);
    }

    /**
     * Mark every unread notification read.
     */
    #[Response(status: 403, type: ApiError::DOC_TYPE)]
    public function markAllAsRead(Request $request, NotificationInboxAction $action): JsonResponse
    {
        $count = $action->markAllAsRead($request->user());

        return $this->respond(sprintf('%d notification(s) marked as read.', $count), 200);
    }
}
