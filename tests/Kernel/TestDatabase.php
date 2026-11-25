<?php

// file generated with AI assistance: Claude Code - 2026-11-25 20:48:51 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Creates the schema of the test database by running all registered
 * migrations (`doctrine:migrations:migrate`): those of
 * dmstr/api-configuration-bundle, dmstr/symfony-job-queue-bundle and the
 * bundle's own `Dmstr\Firecrawl\Migrations\*`, once per PHPUnit process.
 * Already executed migrations are skipped, so a database that is reused
 * between runs stays valid.
 */
final class TestDatabase
{
    private static bool $migrated = false;

    public static function migrate(KernelInterface $kernel): void
    {
        if (self::$migrated) {
            return;
        }

        self::run($kernel);
        self::$migrated = true;
    }

    /**
     * Runs `doctrine:migrations:migrate` unconditionally and returns its
     * output; throws when the command fails.
     */
    public static function run(KernelInterface $kernel): string
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
        ]), $output);

        $display = $output->fetch();
        if (0 !== $exitCode) {
            throw new \RuntimeException('Running the migrations failed: '.$display);
        }

        return $display;
    }
}
