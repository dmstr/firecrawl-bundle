<?php

// file generated with AI assistance: Claude Code - 2026-10-31 19:10:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\Access;

use Dmstr\Firecrawl\Security\FirecrawlPermissions;
use Dmstr\Firecrawl\Tests\Kernel\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The permissions of the bundle: registered at the `za7_core.permission`
 * tag, mapped by the host application (here {@see TestKernel::PERMISSIONS})
 * and decided by its permission voter; without a voter every permission is
 * denied. Boots the kernel but touches no database, so it runs anywhere.
 * The HTTP side (route x role -> status) is {@see FirecrawlAccessTest}.
 */
final class FirecrawlPermissionsTest extends KernelTestCase
{
    public function testAllPermissionsAreRegistered(): void
    {
        self::bootKernel();
        $registered = static::getContainer()->getParameter(TestKernel::PERMISSIONS_REGISTERED_PARAMETER);
        self::assertIsArray($registered);

        self::assertSame([], array_values(array_diff(FirecrawlPermissions::ALL, $registered)));
    }

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function decisions(): iterable
    {
        $featureRoles = [
            FirecrawlPermissions::PASSTHRU_EXECUTE => TestKernel::ROLE_FIRECRAWL_ADMIN,
            FirecrawlPermissions::PROFILE_MANAGE => TestKernel::ROLE_FIRECRAWL_ADMIN,
            FirecrawlPermissions::JOB_WRITE => TestKernel::ROLE_CRAWLER,
            FirecrawlPermissions::ONBOARDING_USE => TestKernel::ROLE_CRAWLER,
        ];
        foreach ($featureRoles as $permission => $featureRole) {
            yield $permission.' feature role' => [['ROLE_USER', $featureRole], $permission, true];
            yield $permission.' admin' => [['ROLE_ADMIN'], $permission, true];
            yield $permission.' user' => [['ROLE_USER'], $permission, false];
            yield $permission.' unmapped role' => [['ROLE_OTHER'], $permission, false];
        }
        yield 'job read user' => [['ROLE_USER'], FirecrawlPermissions::JOB_READ, true];
        yield 'job read admin' => [['ROLE_ADMIN'], FirecrawlPermissions::JOB_READ, true];
        yield 'job read unmapped role' => [['ROLE_OTHER'], FirecrawlPermissions::JOB_READ, false];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('decisions')]
    public function testDecision(array $roles, string $permission, bool $expected): void
    {
        self::assertSame($expected, $this->isGranted($roles, $permission));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function permissions(): iterable
    {
        foreach (FirecrawlPermissions::ALL as $permission) {
            yield $permission => [$permission];
        }
    }

    #[DataProvider('permissions')]
    public function testWithoutVoterEveryPermissionIsDenied(string $permission): void
    {
        self::assertFalse($this->isGranted(['ROLE_ADMIN'], $permission, TestKernel::ENV_NO_VOTER));
    }

    /**
     * @param list<string> $roles
     */
    private function isGranted(array $roles, string $permission, string $environment = TestKernel::ENV_WITH_VOTER): bool
    {
        self::bootKernel(['environment' => $environment]);
        $container = static::getContainer();
        $container->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken(new InMemoryUser('alice', null, $roles), 'main', $roles),
        );

        return $container->get(AuthorizationCheckerInterface::class)->isGranted($permission);
    }
}
