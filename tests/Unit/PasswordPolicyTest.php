<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    /** Default policy: 12 chars, upper, lower, digit, symbol, reject-username. */

    public function test_strong_password_passes_all_rules(): void
    {
        $errors = PasswordPolicy::validate('Str0ng#Pass!2024', 'user');
        $this->assertEmpty($errors);
    }

    public function test_short_password_fails_length(): void
    {
        $errors = PasswordPolicy::validate('Ab1!', 'user');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('12', $errors[0]);
    }

    public function test_password_missing_uppercase_fails(): void
    {
        $errors = PasswordPolicy::validate('aaaaaaaaaaaa1!', 'user');
        $this->assertNotEmpty($errors);
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'uppercase')));
    }

    public function test_password_missing_lowercase_fails(): void
    {
        $errors = PasswordPolicy::validate('AAAAAAAAAAAAAAAA1!', 'user');
        $this->assertNotEmpty($errors);
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'lowercase')));
    }

    public function test_password_missing_digit_fails(): void
    {
        $errors = PasswordPolicy::validate('AAAAAAAAAAAAaa!!', 'user');
        $this->assertNotEmpty($errors);
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'number')));
    }

    public function test_password_missing_symbol_fails(): void
    {
        $errors = PasswordPolicy::validate('AAAAAAAAAAAAaa11', 'user');
        $this->assertNotEmpty($errors);
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'symbol')));
    }

    public function test_password_containing_username_fails_when_enabled(): void
    {
        $errors = PasswordPolicy::validate('MyUser@1234ABC', 'myuser');
        $this->assertNotEmpty($errors);
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'username')));
    }

    public function test_password_not_containing_username_passes(): void
    {
        $errors = PasswordPolicy::validate('Xtr3m#Secure!99', 'unrelated');
        $this->assertEmpty($errors);
    }

    public function test_username_check_skipped_when_null(): void
    {
        $errors = PasswordPolicy::validate('Xtr3m#Secure!99', null);
        $this->assertEmpty($errors);
    }

    /** Strength calculation */

    public function test_strength_returns_weak_for_empty(): void
    {
        $result = PasswordPolicy::strength('');
        $this->assertEquals(0, $result['percent']);
        $this->assertEquals('weak', $result['label']);
    }

    public function test_strength_returns_strong_for_full_match(): void
    {
        $result = PasswordPolicy::strength('Abcdefg1!xyz');
        $this->assertEquals(100, $result['percent']);
        $this->assertEquals('strong', $result['label']);
    }

    public function test_strength_returns_medium_for_partial(): void
    {
        $result = PasswordPolicy::strength('abcdefghij1!'); // 12 chars, missing upper
        $this->assertEquals(80, $result['percent']); // 4/5
        $this->assertContains($result['label'], ['medium', 'strong']);
    }

    public function test_strength_rules_array_has_all_keys(): void
    {
        $result = PasswordPolicy::strength('test');
        $this->assertArrayHasKey('length', $result['rules']);
        $this->assertArrayHasKey('upper', $result['rules']);
        $this->assertArrayHasKey('lower', $result['rules']);
        $this->assertArrayHasKey('digit', $result['rules']);
        $this->assertArrayHasKey('symbol', $result['rules']);
    }
}
