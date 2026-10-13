<?php

namespace App\Support;

/**
 * Is a URL that arrived in a JSON column safe to hand to a client as a link?
 *
 * ## Why this is its own class and not a private method
 *
 * `data` on a notification is a JSON column whose writer is the application,
 * but nothing structural stops a payload carrying `javascript:alert(1)` or
 * `//evil.example`. The first is script, the second has the SYNTAX of a path and
 * the DESTINATION of a link somewhere else — which is why a `str_starts_with(
 * $url, '/')` check alone is not enough.
 *
 * It started as a private method on `NotificationInboxAction`, guarding the web
 * inbox view. Adding the API channel put the same question in a second place,
 * and a second copy of a security check is the copy that gets forgotten when one
 * of them is edited. So the rule lives here, and both consumers call it.
 *
 * ## The rule
 *
 * Same-origin absolute path, or nothing. Not absolute URLs — a client resolves a
 * path against whatever origin it already trusts, and an absolute URL here would
 * both defeat that and bake in the host that happened to answer.
 */
final class SafeUrl
{
    /**
     * The action for one notification, or null when it carries none.
     *
     * The label is the notification's own words — "Download CSV" rather than a
     * generic "Open" — length-bounded because it comes out of a JSON column like
     * everything else in `data`.
     *
     * @param  mixed  $notification  a model with a `data` attribute, or the array
     * @return array{url: string, label: string}|null
     */
    public static function actionFor(mixed $notification): ?array
    {
        $data = is_object($notification) ? ($notification->data ?? []) : $notification;
        $data = is_array($data) ? $data : [];

        $url = $data['url'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        return [
            'url' => $url,
            'label' => str(trim((string) ($data['action_label'] ?? '')))
                ->limit(40)
                ->value() ?: 'Open',
        ];
    }
}
