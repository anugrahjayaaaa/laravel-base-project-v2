<?php

namespace Tests\Unit\Rules;

use App\Rules\PasswordStrengthRule;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The rule is thin on purpose: it forwards to PasswordPolicy and reports every
 * message that comes back. What is worth pinning is that it *is* a rule Laravel
 * accepts, that it reports all failures rather than the first, and that the
 * username-rejection branch only exists when a username is passed in.
 */
class PasswordStrengthRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    public function test_a_policy_compliant_password_passes(): void
    {
        $validator = Validator::make(
            ['password' => 'Str0ng#Pass!2024'],
            ['password' => [new PasswordStrengthRule('someone')]],
        );

        $this->assertTrue($validator->passes());
    }

    public function test_a_short_password_fails_with_the_policy_message(): void
    {
        $validator = Validator::make(
            ['password' => 'Ab1!'],
            ['password' => [new PasswordStrengthRule()]],
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString(
            '12',
            $validator->errors()->first('password'),
        );
    }

    /**
     * Each failure is its own `$fail()` call. A rule that stopped after the
     * first would leave a user fixing one complaint per submit.
     */
    public function test_every_failed_requirement_is_reported(): void
    {
        $validator = Validator::make(
            ['password' => 'abc'],
            ['password' => [new PasswordStrengthRule()]],
        );

        $messages = $validator->errors()->get('password');

        $this->assertGreaterThanOrEqual(3, count($messages));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, '12 characters')));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'uppercase')));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'number')));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'symbol')));
    }

    public function test_a_password_containing_the_username_is_rejected(): void
    {
        $validator = Validator::make(
            ['password' => 'MyUser@1234ABC'],
            ['password' => [new PasswordStrengthRule('myuser')]],
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('username', $validator->errors()->first('password'));
    }

    /**
     * The same password without the username in hand: the branch is opt-in, so
     * a request that forgets to pass `$username` loses the check rather than
     * failing every submission.
     */
    public function test_the_username_check_does_not_fire_without_a_username(): void
    {
        $validator = Validator::make(
            ['password' => 'MyUser@1234ABC'],
            ['password' => [new PasswordStrengthRule()]],
        );

        $this->assertTrue($validator->passes());
    }
}
