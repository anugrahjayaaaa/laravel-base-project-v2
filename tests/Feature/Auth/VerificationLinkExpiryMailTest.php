<?php

namespace Tests\Feature\Auth;

use App\Actions\V1\User\UserCreateAction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\RegisterNotification;
use App\Notifications\UserCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Database\Seeders\SystemSettingSeeder;

/**
 * The verification link's lifetime was signed into the URL but never stated in
 * the mail, so the recipient had no way to know the deadline. Both mail paths
 * that carry a verification URL are covered — admin-created and
 * self-registered — because they are separate notification classes and either
 * one can silently drop the sentence again.
 */
class VerificationLinkExpiryMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemSettingSeeder::class);
    }

    /**
     * Render a notification's markdown mail so the assertion runs against the
     * text the recipient actually receives, not against the view file on disk.
     *
     * Tags are stripped first: the templates bold the number, so the rendered
     * HTML reads `expires in <strong>45</strong> minutes` and a plain substring
     * search for "expires in 45 minutes" would fail against a correct mail.
     */
    private function renderMail(object $notification, User $user): string
    {
        $html = $notification->toMail($user)->render();

        return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    /**
     * The lifetime the mail promises, in the configured value's own wording.
     */
    private function expectedSentence(): string
    {
        return 'expires in '
            . SystemSetting::getInt('email_verification_expire_minutes', 60)
            . ' minutes';
    }

    public function test_admin_created_user_email_states_the_link_lifetime(): void
    {
        Notification::fake();
        SystemSetting::set('email_verification_expire_minutes', '45');

        $user = app(UserCreateAction::class)->run([
            'name' => 'Temp User',
            'email' => 'temp@example.com',
            'username' => 'tempuser',
        ]);

        Notification::assertSentTo(
            $user,
            UserCreatedNotification::class,
            fn ($notification) => str_contains($this->renderMail($notification, $user), $this->expectedSentence())
        );
    }

    public function test_self_registered_user_email_states_the_link_lifetime(): void
    {
        Notification::fake();
        SystemSetting::set('email_verification_expire_minutes', '45');

        // A password present means "self-registered": the action picks
        // RegisterNotification instead of UserCreatedNotification.
        $user = app(UserCreateAction::class)->run(
            [
                'name' => 'Self User',
                'email' => 'self@example.com',
                'username' => 'selfuser',
            ],
            'ChosenPassword123!'
        );

        Notification::assertSentTo(
            $user,
            RegisterNotification::class,
            fn ($notification) => str_contains($this->renderMail($notification, $user), $this->expectedSentence())
        );
    }

    /**
     * The number in the mail must be the number the URL enforces. A second,
     * independent read of the same setting would let them drift apart, and the
     * mail would promise a lifetime the signature refuses to honour — so both
     * come from the one value the action passes down.
     */
    public function test_the_stated_lifetime_tracks_a_changed_setting(): void
    {
        Notification::fake();
        SystemSetting::set('email_verification_expire_minutes', '45');

        $user = app(UserCreateAction::class)->run([
            'name' => 'Temp User',
            'email' => 'temp@example.com',
            'username' => 'tempuser',
        ]);

        $html = '';

        Notification::assertSentTo(
            $user,
            UserCreatedNotification::class,
            function ($notification) use ($user, &$html) {
                $html = $this->renderMail($notification, $user);

                return true;
            }
        );

        // A non-default lifetime: a hardcoded 60 in the template would render
        // "60 minutes" here and the assertion above would still pass on the
        // first test if that first test ran with the default seeded value.
        $this->assertStringContainsString('45', $html);
        $this->assertStringNotContainsString('60 minutes', $html);
    }
}
