<?php

// file generated with AI assistance: Claude Code - 2026-11-05 12:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Connection\FileConverterExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Idempotent seed of a `file-converter` connection. Keyed by the name
 * within `type = 'file-converter'`: a missing connection is created (active), one
 * with another URL is updated, an equal one is left alone. Validated like the
 * API; the service is not contacted.
 */
#[AsCommand(name: 'firecrawl:file-converter:seed', description: 'Create or update a file converter connection idempotently (no server contact)')]
final class FileConverterSeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Connection name')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Base URL of the converter service, e.g. http://converter:8000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');
        $config = [
            FileConverterExtension::NAME => true,
            'type' => FileConverterExtension::NAME,
            'endpoint_type' => 'rest',
            'base_url' => (string) $input->getOption('url'),
        ];

        $connection = $this->entityManager->getRepository(ApiConfiguration::class)->findOneBy(['type' => FileConverterExtension::NAME, 'name' => $name]);
        if (null !== $connection && $connection->getConfigJson() == $config) {
            $io->writeln(\sprintf('File converter connection "%s" unchanged.', $name));

            return Command::SUCCESS;
        }

        $connection ??= (new ApiConfiguration())->setName($name)->setActive(true);
        $connection->setConfigJson($config);
        $violations = $this->validator->validate($connection);
        if (\count($violations) > 0) {
            $io->error(\sprintf('File converter connection "%s" is invalid: %s', $name, (string) $violations));

            return Command::FAILURE;
        }

        $this->entityManager->persist($connection);
        $this->entityManager->flush();
        $io->success(\sprintf('File converter connection "%s" seeded (%s).', $name, $connection->getId()));

        return Command::SUCCESS;
    }
}
