<?php

namespace Tests\Unit\Support;

use App\Support\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

/**
 * `App\Support\SafeUrl` is the security check behind an `href` on both the web
 * inbox and the API resource, so its allowlist is the whole defence.
 *
 * The class exists because `str_starts_with($url, '/')` alone is not enough:
 * `//evil.example` has the syntax of a path and the destination of a link
 * somewhere else. Every refusal below is one of those near-misses, and the
 * label half is pinned too because it comes out of the same JSON column.
 */
class SafeUrlTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unsafePayloads(): array
    {
        return [
            'no url key' => [['action_label' => 'Open']],
            'null url' => [['url' => null]],
            'integer url' => [['url' => 123]],
            'array url' => [['url' => ['/dashboard']]],
            'relative path' => [['url' => 'dashboard']],
            'absolute http' => [['url' => 'http://evil.example/x']],
            'absolute https' => [['url' => 'https://evil.example/x']],
            'protocol relative' => [['url' => '//evil.example/x']],
            'javascript scheme' => [['url' => 'javascript:alert(1)']],
            'mailto scheme' => [['url' => 'mailto:a@evil.example']],
            'leading space' => [['url' => ' /dashboard']],
            'data is not an array' => ['url=/dashboard'],
        ];
    }

    /**
     * @param  mixed  $notification  a model-like object or the raw `data` value
     */
    #[DataProvider('unsafePayloads')]
    public function test_anything_that_is_not_a_same_origin_path_is_refused(mixed $notification): void
    {
        $this->assertNull(
            SafeUrl::actionFor($notification),
            'SafeUrl handed a client a URL it cannot vouch for'
        );
    }

    public function test_a_same_origin_path_is_returned_with_a_default_label(): void
    {
        $this->assertSame(
            ['url' => '/dashboard', 'label' => 'Open'],
            SafeUrl::actionFor(['url' => '/dashboard'])
        );
    }

    public function test_a_deep_path_survives_intact(): void
    {
        $url = '/activity-logs/exports/42/download';

        $this->assertSame(
            ['url' => $url, 'label' => 'Open'],
            SafeUrl::actionFor(['url' => $url])
        );
    }

    public function test_the_notifications_own_words_become_the_label(): void
    {
        $this->assertSame(
            'Download CSV',
            SafeUrl::actionFor(['url' => '/x', 'action_label' => '  Download CSV  '])['label']
        );
    }

    /**
     * The label is data from a JSON column like everything else: bounded, and
     * an empty/blank one falls back rather than rendering a blank button.
     */
    public function test_the_label_is_bounded_and_never_blank(): void
    {
        $long = str_repeat('a', 60);

        $label = SafeUrl::actionFor(['url' => '/x', 'action_label' => $long])['label'];

        $this->assertStringEndsWith('...', $label);
        $this->assertSame(str_repeat('a', 40), rtrim($label, '.'), 'the label was not cut at 40 characters');

        $this->assertSame('Open', SafeUrl::actionFor(['url' => '/x', 'action_label' => '   '])['label']);
    }

    public function test_a_notification_model_is_read_through_its_data_attribute(): void
    {
        $notification = new stdClass();
        $notification->data = ['url' => '/x', 'action_label' => 'View'];

        $this->assertSame(['url' => '/x', 'label' => 'View'], SafeUrl::actionFor($notification));

        $this->assertNull(SafeUrl::actionFor(new stdClass()), 'an object with no data must not be read as a URL');
    }
}
