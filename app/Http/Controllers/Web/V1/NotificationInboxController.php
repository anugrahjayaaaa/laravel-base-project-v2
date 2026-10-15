<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Notification\NotificationInboxAction;
use App\Http\Controllers\Controller;
use App\Support\UnreadNotificationCount;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationInboxController extends Controller
{
    /** The viewer's notifications. */
    public function index(Request $request, NotificationInboxAction $action): View
    {
        $viewer = $request->user();

        return view('pages.notifications.inbox', [
            ...$action->inbox($viewer),
            'canConfigure' => $viewer->can('notifications.view'),
        ]);
    }

    /** One notification, opened — and marked read, because that is what opening is. */
    public function show(Request $request, NotificationInboxAction $action, string $notification): View
    {
        return view('pages.notifications.show', $action->show($request->user(), $notification));
    }

    /**
     * The bell's count, for the poll that keeps it current.
     *
     * A JSON object rather than a bare number because the caller also needs to know
     * WHETHER the list changed: `latest_id` is what lets the script touch the
     * dropdown's markup only when a notification actually arrived, rather than
     * replacing elements every 30 seconds.
     *
     * Answers from the same cache the page render used, so a warm poll costs no
     * query — the cache is invalidated on delivery (`NotificationSent`) and on every
     * read path.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $viewer = $request->user();

        return response()->json([
            'count' => UnreadNotificationCount::for($viewer),
            'latest_id' => UnreadNotificationCount::latestIdFor($viewer),
        ]);
    }

    /**
     * The bell's list, as an HTML fragment.
     *
     * Returned by the server so the dropdown has ONE renderer: the same Blade
     * partial the page includes. A second copy of that markup in JavaScript is a
     * second renderer, and the two drift silently.
     */
    public function preview(): View
    {
        return view('layouts.partials.notification-dropdown-list');
    }

    /** Mark one notification read. */
    public function markAsRead(Request $request, string $id, NotificationInboxAction $action): RedirectResponse
    {
        $marked = $action->markAsRead($request->user(), $id);

        return back()->with(
            $marked ? 'status' : 'error',
            $marked ? 'Notification marked as read.' : 'That notification was already read or no longer exists.'
        );
    }

    /** Mark everything read. */
    public function markAllAsRead(Request $request, NotificationInboxAction $action): RedirectResponse
    {
        $count = $action->markAllAsRead($request->user());

        return back()->with('status', sprintf(
            '%d notification(s) marked as read.',
            $count
        ));
    }
}
