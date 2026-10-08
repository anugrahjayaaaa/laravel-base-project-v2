<?php

namespace Tests\Feature\Notification;

use App\Models\User;
use App\Notifications\AccountStateChangedNotification;
use App\Notifications\ChangeEmailVerificationNotification;
use App\Notifications\ConfigurationChangedNotification;
use App\Notifications\RegisterNotification;
use App\Notifications\RolesChangedNotification;
use App\Notifications\UserCreatedNotification;
use App\Notifications\UserRegisteredNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The copy contract behind `subject` + `lines`.
 *
 * `pages/notifications/inbox` and the bell dropdown render these two keys and
 * nothing else, so every notification in the app is one call away from shipping
 * the same three defects this file exists to catch — and all three shipped once,
 * on all seven classes, before this test existed:
 *
 *   1. **The body repeated the title.** "Role was updated" as a subject, then
 *      "Role was updated by Ana Silva." as its only line. Two readings of one
 *      event, the second adding nothing the reader did not have, which is what
 *      makes a notification look like noise rather than information.
 *   2. **Broken sentences from string concatenation.** `sprintf('Your roles%s
 *      were changed.', $by)` produced "Your roles by Ana Silva were changed." —
 *      not a style objection, a sentence a reader has to stop and parse.
 *   3. **A subject that does not identify itself.** The bell shows the subject
 *      and truncates it. "Account was locked" names no account, so a
 *      notification with no body still needs a subject that stands alone.
 *
 * Assertions here are mechanical on purpose. Rewording is a judgement call and
 * does not belong in a test; "the body does not restate the title" is arithmetic,
 * and it is the one rule that every one of the seven classes broke.
 *
 * The rules come from the notification-copy guidance researched for this pass
 * (OneSignal, Braze, Appcues, Microsoft Fluent 2 content design): the title
 * carries what changed and the body carries what it means and what to do next;
 * lead with the most important thing because trays and cells truncate; write in
 * plain present-tense active voice; and say where the next step happens when the
 * notification itself cannot perform it.
 *
 * Sentence case is deliberately NOT asserted, and the reason is worth more than
 * the rule would have been. An earlier version of this file required every word
 * of a subject to be lowercase, which fails on "Ana Silva changed the roles of
 * jane" — a person's name is legitimately capitalised, and the check cannot tell
 * a proper noun from Title Case without a name list it does not have. Rather
 * than weaken it into something that passes by accident, it is gone: the classes
 * carry the convention in the template constants, and the three rules below are
 * the ones that still bite.
 */
class NotificationCopyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The rule the other five are decoration around.
     *
     * A line is a restatement when it says NOTHING the subject did not already
     * say — measured as "every meaningful word in the subject also appears in the
     * line". Set inclusion rather than word overlap, because sharing a noun is
     * normal English ("Review the flag" beside "Feature flag changed") and only
     * total coverage is duplication.
     *
     * Word matching also has to survive the passive. The defect arrived as a
     * subject reading "Your account has been locked" over a line reading "Your
     * account was locked", which shares three content words and no whole phrase —
     * a substring test passes it, and so would an overlap test that only exempted
     * "was". So the auxiliaries are dropped on both sides.
     *
     * Both of the shipped versions this rule was written against fail here:
     * "Role was updated" over "Role was updated by Ana Silva.", and "Your account
     * has been locked" over "Your account was locked by Ana Silva."
     */
    #[DataProvider('copyCases')]
    public function test_no_line_repeats_what_the_subject_already_says(string $label, callable $make): void
    {
        $payload = $make();

        $subjectWords = $this->significantWords($payload['subject']);

        foreach ($payload['lines'] as $line) {
            $lineWords = $this->significantWords($line);

            $repeatsEverything = array_diff($subjectWords, $lineWords) === [];

            $this->assertFalse(
                $repeatsEverything,
                "{$label}: the line repeats the subject instead of adding to it\n"
                ."  subject: {$payload['subject']}\n  line:    {$line}"
            );
        }
    }

    /**
     * No terminal punctuation — the rule this file originally got wrong, and got
     * wrong in the direction the codebase already agreed with.
     *
     * These strings are titles in a list: the bell dropdown and the inbox subject
     * column. Content-design guidance for both is explicit that a title does not
     * take a closing full stop, and the codebase's own rows never had one. A
     * trailing period on a truncated title is worse than useless: it reads as
     * the end of a sentence that the bell then cuts off mid-sentence anyway.
     *
     * The lines are the opposite case — they are sentences, and they keep their
     * full stops so a row reads as prose rather than as fragments.
     */
    #[DataProvider('copyCases')]
    public function test_subjects_carry_no_terminal_punctuation(string $label, callable $make): void
    {
        $payload = $make();

        $this->assertDoesNotMatchRegularExpression(
            '/[.!?:;]$/',
            $payload['subject'],
            "{$label}: the subject ends with punctuation\n  subject: {$payload['subject']}"
        );

        foreach ($payload['lines'] as $line) {
            $this->assertStringEndsWith(
                '.',
                $line,
                "{$label}: a body line is not a sentence\n  line: {$line}"
            );
        }
    }

    /** The bell shows subjects alone; a long one is truncated into a different claim. */
    #[DataProvider('copyCases')]
    public function test_subjects_fit_a_bell_that_truncates(string $label, callable $make): void
    {
        $payload = $make();

        $subject = $payload['subject'];

        // Sixty characters is where the dropdown stops being readable on the
        // narrowest sidebar width this layout supports.
        $this->assertLessThanOrEqual(
            60,
            mb_strlen($subject),
            "{$label}: the subject will be truncated in the bell dropdown\n  subject: {$subject}"
        );
    }

    /**
     * No empty lines, no orphaned punctuation, and a body short enough to read in
     * a table cell — the equivalent of the "under 40 words" guidance, applied to a
     * list rather than a push tray.
     */
    #[DataProvider('copyCases')]
    public function test_lines_are_present_and_bounded(string $label, callable $make): void
    {
        $payload = $make();

        $lines = $payload['lines'];

        $this->assertNotEmpty($lines, "{$label}: a notification with a subject and no body");

        foreach ($lines as $line) {
            $this->assertNotSame('', trim($line), "{$label}: an empty line");
            $this->assertStringStartsNotWith(',', trim($line), "{$label}: a line starts with a separator");
            $this->assertLessThanOrEqual(
                25,
                str_word_count($line),
                "{$label}: a line too long to scan in a cell\n  line: {$line}"
            );
        }

        $this->assertLessThanOrEqual(
            4,
            count($lines),
            "{$label}: too many lines for one row\n  ".implode("\n  ", $lines)
        );
    }

    /**
     * Every rendered payload must render as text. The inbox escapes, so this is
     * about the copy not carrying markup that will be shown literally.
     */
    #[DataProvider('copyCases')]
    public function test_copy_carries_no_markup(string $label, callable $make): void
    {
        $payload = $make();

        $this->assertStringNotContainsString('<', $payload['subject'], "{$label}: markup in a subject");

        foreach ($payload['lines'] as $line) {
            $this->assertStringNotContainsString('<', $line, "{$label}: markup in a line");
        }
    }

    /**
     * Words that carry the claim.
     *
     * Grammatical filler is dropped from both sides, including the auxiliaries,
     * so "has been locked" and "was locked" reduce to the same claim. An earlier
     * version of this exempted only a handful of verbs and flagged ordinary
     * English like "Review the flag" beside "Feature flag changed", which is how a
     * guard gets switched off for being noisy.
     *
     * @return array<int, string>
     */
    private function significantWords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}\']+/u', mb_strtolower($text)) ?: [];

        return array_values(array_diff($words, [
            // Articles, pronouns, prepositions — grammar, not claim.
            'the', 'a', 'an', 'your', 'you', 'is', 'was', 'were', 'are', 'be',
            'been', 'being', 'has', 'have', 'had', 'by', 'to', 'for', 'of',
            'in', 'on', 'it', 'this', 'that', 'and', 'we',
        ]));
    }

    /**
     * Every notification class, on both of its audiences.
     *
     * Each case is a CLOSURE, not a payload: a data provider runs before the
     * application is booted, and a factory call there fails on a facade that has
     * no root yet. The closure runs inside the test, where the container and the
     * models both exist.
     *
     * @return array<string, array{0: string, 1: callable(): array<string, mixed>}>
     */
    public static function copyCases(): array
    {
        $cases = [];

        foreach (self::buildSpecs() as $label => [$class, $audiences]) {
            foreach ($audiences as $recipient) {
                $name = $label.' → '.$recipient['as'];

                $cases[$name] = [$name, static fn (): array => self::payloadFor($class, $recipient)];
            }
        }

        return $cases;
    }

    /**
     * One rendered payload: the class, its constructor arguments, and the
     * recipient whose copy is being read.
     *
     * @param  class-string  $class
     * @param  array{as: string, args: array}  $recipient
     * @return array<string, mixed>
     */
    private static function payloadFor(string $class, array $recipient): array
    {
        // Keys are set explicitly. `isSelf()` compares `getKey()`, and two
        // unsaved factory models both have a null key — which reads as "same
        // user", so every operator row would render as the personal copy and the
        // administrative half of this file would have tested nothing. It is a
        // harness trap that looks exactly like a copy bug, which is why the ids
        // are pinned here rather than left to the factory.
        $models = [
            '@subject' => User::factory()->make([
                'id' => 1, 'username' => 'jane', 'name' => 'Jane Doe', 'email' => 'jane@example.test',
            ]),
            '#causer' => User::factory()->make([
                'id' => 2, 'username' => 'ana', 'name' => 'Ana Silva', 'email' => 'ana@example.test',
            ]),
            '#none' => null,
        ];

        $args = [];

        foreach ($recipient['args'] as $arg) {
            $args[] = is_string($arg) && array_key_exists($arg, $models) ? $models[$arg] : $arg;
        }

        $notifiable = $recipient['as'] === 'self' ? $models['@subject'] : $models['#causer'];

        return (new $class(...$args))->toArray($notifiable);
    }

    /**
     * A notification and the two audiences it reaches.
     *
     * Both audiences for every class, because the personal and administrative
     * copies are the ones that drifted apart — and the bell shows the subject
     * only, so the subject is the part most likely to be read in isolation.
     *
     * `@` in an argument stands for the subject account, `#causer` for the
     * operator and `#none` for an event with no actor — so the specs stay
     * declarative and no model is built before the app exists.
     *
     * @return array<string, array{0: class-string, 1: array<int, array{as: string, args: array}>}>
     */
    private static function buildSpecs(): array
    {
        return [
            'account locked' => [AccountStateChangedNotification::class, [
                ['as' => 'self', 'args' => ['@subject', '#causer', 'user.locked']],
                ['as' => 'operator', 'args' => ['@subject', '#causer', 'user.locked']],
            ]],
            'account unlocked' => [AccountStateChangedNotification::class, [
                ['as' => 'self', 'args' => ['@subject', '#causer', 'user.unlocked']],
                ['as' => 'operator', 'args' => ['@subject', '#causer', 'user.unlocked']],
            ]],
            'account deactivated' => [AccountStateChangedNotification::class, [
                ['as' => 'self', 'args' => ['@subject', '#causer', 'user.deactivated']],
                ['as' => 'operator', 'args' => ['@subject', '#causer', 'user.deactivated']],
            ]],
            'account activated' => [AccountStateChangedNotification::class, [
                ['as' => 'self', 'args' => ['@subject', '#causer', 'user.activated']],
                ['as' => 'operator', 'args' => ['@subject', '#causer', 'user.activated']],
            ]],
            'account locked, no actor' => [AccountStateChangedNotification::class, [
                ['as' => 'self', 'args' => ['@subject', '#none', 'user.locked']],
                ['as' => 'operator', 'args' => ['@subject', '#none', 'user.locked']],
            ]],
            'email change confirmation' => [ChangeEmailVerificationNotification::class, [
                ['as' => 'self', 'args' => ['moved@example.test', 'a-token']],
            ]],
            'feature flag changed' => [ConfigurationChangedNotification::class, [
                ['as' => 'operator', 'args' => ['feature.changed', 'billing_v2', '#causer']],
            ]],
            'settings changed' => [ConfigurationChangedNotification::class, [
                ['as' => 'operator', 'args' => ['setting.changed', '3 settings', '#causer']],
            ]],
            'mail transport changed' => [ConfigurationChangedNotification::class, [
                ['as' => 'operator', 'args' => ['mail_setting.changed', null, '#causer']],
            ]],
            'channels changed' => [ConfigurationChangedNotification::class, [
                ['as' => 'operator', 'args' => ['channel.changed', null, '#none']],
            ]],
            'role deleted' => [ConfigurationChangedNotification::class, [
                ['as' => 'operator', 'args' => ['role.deleted', 'Billing', '#causer']],
            ]],
            'user deleted' => [ConfigurationChangedNotification::class, [
                ['as' => 'operator', 'args' => ['user.deleted', 'jane', '#causer']],
            ]],
            'user profile updated' => [ConfigurationChangedNotification::class, [
                ['as' => 'operator', 'args' => ['user.updated', 'jane', '#causer']],
            ]],
            'registration verification' => [RegisterNotification::class, [
                ['as' => 'self', 'args' => ['jane', 'https://example.test/verify', 60]],
            ]],
            'roles granted and revoked' => [RolesChangedNotification::class, [
                ['as' => 'self', 'args' => ['@subject', '#causer', ['Support'], ['Billing']]],
                ['as' => 'operator', 'args' => ['@subject', '#causer', ['Support'], ['Billing']]],
            ]],
            'roles granted, no actor' => [RolesChangedNotification::class, [
                ['as' => 'self', 'args' => ['@subject', '#none', ['Support'], []]],
                ['as' => 'operator', 'args' => ['@subject', '#none', ['Support'], []]],
            ]],
            'account created with a password' => [UserCreatedNotification::class, [
                ['as' => 'self', 'args' => ['temp-pass', 'jane', 'https://example.test/verify', 60, '#causer']],
            ]],
            'new registration' => [UserRegisteredNotification::class, [
                ['as' => 'self', 'args' => ['@subject', '#none']],
                ['as' => 'operator', 'args' => ['@subject', '#causer']],
                ['as' => 'operator, no actor', 'args' => ['@subject', '#none']],
            ]],
        ];
    }
}
