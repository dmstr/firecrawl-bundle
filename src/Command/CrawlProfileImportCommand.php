<?php

// file generated with AI assistance: Claude Code - 2026-11-05 15:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Dmstr\Firecrawl\Crawl\CrawlProfileSecrets;
use Dmstr\Firecrawl\Entity\CrawlProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports a crawl profile from a YAML file of the former format (`discover`,
 * `crawl`, `auth`) into the database, or updates the profile of that name.
 *
 * The old files named environment variables for the login
 * (`auth.username_env`, `auth.password_env`, default CRAWL_USER and
 * CRAWL_PASSWORD); their values are read once here and stored with the
 * profile, the password encrypted. The Firecrawl connection defaults to
 * the single active one.
 */
#[AsCommand(name: 'firecrawl:crawl-profile:import', description: 'Import a YAML crawl profile into the database (create or update by name)')]
final class CrawlProfileImportCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly FirecrawlConnectionResolver $firecrawlConnections,
        private readonly CrawlProfileSecrets $secrets,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'YAML profile, e.g. config/crawl/example.yaml')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Profile name (default: the file name without .yaml or .dist.yaml)')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Firecrawl connection (default: the only active one)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $name = (string) ($input->getOption('name') ?? preg_replace('/(\.dist)?\.ya?ml$/', '', basename($file)));

        try {
            $data = is_file($file) ? Yaml::parseFile($file) : null;
            if (!\is_array($data)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is no YAML crawl profile.', $file));
            }
            $firecrawl = $this->firecrawlConnections->resolve($input->getOption('connection'));
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $password = null;
        if (\is_array($data['auth'] ?? null)) {
            $data['auth']['username'] = self::env((string) ($data['auth']['username_env'] ?? 'CRAWL_USER'));
            $password = self::env((string) ($data['auth']['password_env'] ?? 'CRAWL_PASSWORD'));
        }

        $profile = $this->entityManager->getRepository(CrawlProfile::class)->findOneBy(['name' => $name]) ?? (new CrawlProfile())->setName($name);
        $profile
            ->applyProfileData($data)
            ->setFirecrawlConnection($firecrawl);
        if ('' !== (string) $password) {
            $profile->setPassword($password);
        }

        $violations = $this->validator->validate($profile);
        if (\count($violations) > 0) {
            $io->error(\sprintf("Crawl profile \"%s\" is invalid:\n%s", $name, (string) $violations));

            return Command::FAILURE;
        }

        $this->secrets->storeNewPassword($profile);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();
        $io->success(\sprintf('Crawl profile "%s" imported (%s)%s.', $name, $profile->getId(), $profile->isPasswordSet() ? ', with login' : ''));

        return Command::SUCCESS;
    }

    private static function env(string $name): string
    {
        return '' === $name ? '' : (string) ($_SERVER[$name] ?? $_ENV[$name] ?? getenv($name) ?: '');
    }
}
