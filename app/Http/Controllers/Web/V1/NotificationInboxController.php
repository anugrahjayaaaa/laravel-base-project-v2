<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Notification\NotificationInboxAction;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
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
