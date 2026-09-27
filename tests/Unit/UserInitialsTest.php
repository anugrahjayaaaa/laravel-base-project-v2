<?php

namespace Tests\Unit;

use App\Enums\UserStatusEnum;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserInitialsTest extends TestCase
{
    /**
     * The avatar rules, as a table so a failure names the case.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function names(): array
    {
        return [
            'one name, two letters' => ['Budi', 'BU'],
            'one short name' => ['Al', 'AL'],
            'two names' => ['Jane Doe', 'JD'],
            'three names use first and last' => ['John Ronald Reuel Tolkien', 'JT'],
            'four names use first and last' => ['Ana Maria Sofia Lopez', 'AL'],
            'extra whitespace collapses' => ['  Jane   Doe  ', 'JD'],
        ];
    }

    #[Test]
    #[DataProvider('names')]
    public function test_initials_follow_the_documented_rule(string $name, string $expected): void
    {
        $user = new User(['name' => $name]);

        $this->assertSame($expected, $user->initials());
    }

    #[Test]
    public function test_a_trashed_user_is_badged_danger(): void
    {
        $this->assertSame('bg-danger text-white', UserStatusEnum::ACTIVE->badgeClass(trashed: true));
        $this->assertNotSame(
            UserStatusEnum::ACTIVE->badgeClass(),
            UserStatusEnum::ACTIVE->badgeClass(trashed: true),
        );
    }

    /**
     * Every case has to be handled. A new status with no arm here is an
     * UnhandledMatchError on the user list page, so the test walks them all.
     */
    #[Test]
    public function test_every_status_has_a_badge_class(): void
    {
        foreach (UserStatusEnum::cases() as $status) {
            $this->assertNotSame('', $status->badgeClass(), "{$status->value} has no badge class");
        }
    }
}
