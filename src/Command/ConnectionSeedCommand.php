<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\Firecrawl\Connection\FirecrawlExtension;
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
 * Idempotent seed of `firecrawl` connections, e.g. from a setup script.
 *
 * Input: one connection from the arguments (`name --url=… --api-key=…`,
 * the options default to the bundle parameters `dmstr_firecrawl.seed.*`)
 * and/or a seed file (`--file=…`), a JSON list of
 * `{"name": …, "config": {"base_url": …, "api_key": …, "timeout": …,
 * "verify_ssl": …}}`. The single connection is seeded first, then the file
 * in order. Without a file, an empty URL is a no-op with a note (exit 0, no
 * database access); with a file, the single connection is skipped silently.
 *
 * Per entry, keyed by the name within `type = 'firecrawl'`, the command
 * builds the full configJson (discriminators included): a missing
 * connection is created (active), an existing one whose configJson differs
 * is updated in place (its active flag is kept), and an existing one with an
 * equal configJson is left alone (no flush, `updatedAt` unchanged). Every
 * entity is validated with the validator before anything is written, so the
 * unified connection schema and the name uniqueness of `firecrawl`
 * connections apply exactly as through the API. A failing entry does not
 * stop the others; the exit code is 1 when any entry failed. The command
 * never contacts the server.
 *
 * The API key is written in clear; the `onFlush` listener of
 * dmstr/api-configuration-bundle encrypts it. The stored configJson is
 * decrypted before the comparison, so an unchanged definition stays
 * "unchanged" although the database holds a ciphertext.
 */
#[AsCommand(name: 'firecrawl:connection:seed', description: 'Create or update firecrawl connections idempotently (no server contact)')]
final class ConnectionSeedCommand extends Command
{
    public const string RESULT_CREATED = 'created';
    public const string RESULT_UPDATED = 'updated';
    public const string RESULT_UNCHANGED = 'unchanged';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ConfigSecrets $secrets,
        private readonly string $defaultBaseUrl = '',
        private readonly string $defaultApiKey = '',
    ) {
        parent::__construct();
    }

    /**
     * Builds the configJson of a `firecrawl` connection. Pure; schema rules
     * (URL format, timeout range) are left to the validator so the seed and
     * the API report the same violations. Optional fields at their default
     * are omitted: `api_key` when null or empty, `timeout` when null,
     * `verify_ssl` when true.
     *
     * @return array<string, mixed>
     */
    public static function buildConfig(string $baseUrl, ?string $apiKey = null, ?int $timeout = null, bool $verifySsl = true): array
    {
        $config = [
            FirecrawlExtension::NAME => true,
            'type' => FirecrawlExtension::NAME,
            'endpoint_type' => 'rest',
            'base_url' => $baseUrl,
        ];
        if (null !== $apiKey && '' !== $apiKey) {
            $config['api_key'] = $apiKey;
        }
        if (null !== $timeout) {
            $config['timeout'] = $timeout;
        }
        if (!$verifySsl) {
            $config['verify_ssl'] = false;
        }

        return $config;
    }

    /**
     * Whether a stored configJson equals the desired one. Key order does not
     * count (the database may return keys in another order), values are
     * compared strictly. Pass the stored configJson decrypted
     * ({@see ConfigSecrets::decrypt()}): a ciphertext never equals the clear
     * value.
     *
     * @param array<mixed> $stored
     * @param array<mixed> $desired
     */
    public static function isSameConfig(array $stored, array $desired): bool
    {
        return self::normalize($stored) === self::normalize($desired);
    }

    /**
     * Parses the --timeout option: null when omitted, an integer otherwise
     * (range checks are the schema's job).
     *
     * @throws \InvalidArgumentException for a non-integer value
     */
    public static function parseTimeout(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (\is_int($value)) {
            return $value;
        }
        if (!\is_string($value) || 1 !== preg_match('/^-?\d+$/', $value)) {
            throw new \InvalidArgumentException('--timeout must be an integer number of seconds.');
        }

        return (int) $value;
    }

    /**
     * Parses the entries of a seed file into names and built configJsons.
     * Messages name the entry index, the connection and the problem, never
     * a value.
     *
     * @return list<array{name: string, config: array<string, mixed>}>
     *
     * @throws \InvalidArgumentException for malformed JSON or entries
     */
    public static function parseEntries(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Seed file is not valid JSON: '.$e->getMessage().'.');
        }
        if (!\is_array($data) || !array_is_list($data)) {
            throw new \InvalidArgumentException('Seed file must contain a JSON list of {"name", "config"} objects.');
        }

        $entries = [];
        foreach ($data as $index => $entry) {
            $entries[] = self::entry($entry, \sprintf('Entry %d', $index));
        }

        return $entries;
    }

    protected function configure(): void
    {
        // --url and --api-key default to null in the definition and fall back
        // to the bundle parameters at runtime, so --help never prints the key.
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Connection name (unique among firecrawl connections)', 'default')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Base URL of the Firecrawl instance (default: dmstr_firecrawl.seed.base_url)')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key (default: dmstr_firecrawl.seed.api_key); empty sends no Authorization header')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout per request in seconds (1 to 55, default 30)')
            ->addOption('no-verify-ssl', null, InputOption::VALUE_NONE, 'Do not verify the TLS certificate of the instance')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Seed file: JSON list of {"name", "config"} objects')
            ->setHelp(<<<'HELP'
                Creates the firecrawl connection <info>name</info> (default: <info>default</info>) and/or the
                connections of a seed file, or brings them in line with the given
                definitions. Running it again with the same input changes nothing, so it
                is safe in setup scripts. Without --url the configured seed URL is used;
                when that is empty too, the single connection is skipped (with a note when
                there is no --file). The server is not contacted; check it with
                <info>app:api-configuration:health</info>.

                  <info>%command.full_name% default --url=https://firecrawl.example.org --api-key=secret</info>
                  <info>%command.full_name% staging --url=https://firecrawl.staging.example.org --timeout=50 --no-verify-ssl</info>
                  <info>%command.full_name% --file=firecrawl-connections.json</info>

                Keep API keys in the seed file (gitignored) rather than on the command line.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $entries = $this->entriesFrom($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $entries) {
            $io->note(\sprintf('No Firecrawl URL configured (--url or dmstr_firecrawl.seed.base_url); connection "%s" not seeded.', (string) $input->getArgument('name')));

            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($entries as $entry) {
            if (!$this->seed($entry['name'], $entry['config'], $io)) {
                ++$failed;
            }
        }

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<array{name: string, config: array<string, mixed>}>
     *
     * @throws \InvalidArgumentException
     */
    private function entriesFrom(InputInterface $input): array
    {
        $entries = [];

        $url = trim(self::stringOption($input, 'url') ?? $this->defaultBaseUrl);
        if ('' !== $url) {
            $entries[] = [
                'name' => (string) $input->getArgument('name'),
                'config' => self::buildConfig(
                    $url,
                    self::stringOption($input, 'api-key') ?? $this->defaultApiKey,
                    self::parseTimeout($input->getOption('timeout')),
                    !$input->getOption('no-verify-ssl'),
                ),
            ];
        }

        $file = self::stringOption($input, 'file');
        if (null !== $file && '' !== $file) {
            if (!is_file($file) || !is_readable($file)) {
                throw new \InvalidArgumentException(\sprintf('Seed file "%s" does not exist or is not readable.', $file));
            }
            $entries = [...$entries, ...self::parseEntries((string) file_get_contents($file))];
        }

        return $entries;
    }

    /**
     * @return array{name: string, config: array<string, mixed>}
     *
     * @throws \InvalidArgumentException
     */
    private static function entry(mixed $entry, string $label): array
    {
        if (!\is_array($entry)) {
            throw new \InvalidArgumentException(\sprintf('%s must be an object with "name" and "config".', $label));
        }
        $name = $entry['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            throw new \InvalidArgumentException(\sprintf('%s: "name" must be a non-empty string.', $label));
        }
        $label = \sprintf('%s ("%s")', $label, trim($name));
        $unknown = array_diff(array_keys($entry), ['name', 'config']);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('%s: unknown keys %s (expected "name", "config").', $label, implode(', ', $unknown)));
        }
        $config = $entry['config'] ?? null;
        if (!\is_array($config) || ([] !== $config && array_is_list($config))) {
            throw new \InvalidArgumentException(\sprintf('%s: "config" must be an object.', $label));
        }
        $unknown = array_diff(array_keys($config), ['base_url', 'api_key', 'timeout', 'verify_ssl']);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('%s: unknown config keys %s (expected "base_url", "api_key", "timeout", "verify_ssl").', $label, implode(', ', $unknown)));
        }

        $baseUrl = $config['base_url'] ?? null;
        if (!\is_string($baseUrl) || '' === trim($baseUrl)) {
            throw new \InvalidArgumentException(\sprintf('%s: "base_url" must be a non-empty string.', $label));
        }
        $apiKey = $config['api_key'] ?? null;
        if (null !== $apiKey && !\is_string($apiKey)) {
            throw new \InvalidArgumentException(\sprintf('%s: "api_key" must be a string.', $label));
        }
        $timeout = $config['timeout'] ?? null;
        if (null !== $timeout && !\is_int($timeout)) {
            throw new \InvalidArgumentException(\sprintf('%s: "timeout" must be an integer number of seconds.', $label));
        }
        $verifySsl = $config['verify_ssl'] ?? true;
        if (!\is_bool($verifySsl)) {
            throw new \InvalidArgumentException(\sprintf('%s: "verify_ssl" must be a boolean.', $label));
        }

        return ['name' => trim($name), 'config' => self::buildConfig(trim($baseUrl), $apiKey, $timeout, $verifySsl)];
    }

    /**
     * Seeds one connection; reports the result and returns false on failure.
     *
     * @param array<string, mixed> $config
     */
    private function seed(string $name, array $config, SymfonyStyle $io): bool
    {
        $existing = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => FirecrawlExtension::NAME, 'name' => $name]);

        if (\count($existing) > 1) {
            $io->error(\sprintf('%d firecrawl connections are named "%s"; resolve the duplicates first.', \count($existing), $name));

            return false;
        }

        $connection = $existing[0] ?? null;

        if (null !== $connection && self::isSameConfig($this->secrets->decrypt($connection->getConfigJson()), $config)) {
            $io->writeln(\sprintf('Firecrawl connection "%s" unchanged (%s).', $name, $connection->getId()));

            return true;
        }

        if (null === $connection) {
            $result = self::RESULT_CREATED;
            $connection = (new ApiConfiguration())
                ->setName($name)
                ->setConfigJson($config)
                ->setActive(true);
        } else {
            $result = self::RESULT_UPDATED;
            $connection->setConfigJson($config);
        }

        $violations = $this->validator->validate($connection);
        if (\count($violations) > 0) {
            if (self::RESULT_UPDATED === $result) {
                // Drop the rejected change from the managed entity, so a later
                // flush (next entry) cannot write it.
                $this->entityManager->refresh($connection);
            }

            $messages = [];
            foreach ($violations as $violation) {
                $path = $violation->getPropertyPath();
                $messages[] = ('' !== $path ? $path.': ' : '').$violation->getMessage();
            }
            $io->error(array_merge([\sprintf('Firecrawl connection "%s" is invalid:', $name)], $messages));

            return false;
        }

        if (self::RESULT_CREATED === $result) {
            $this->entityManager->persist($connection);
        }
        $this->entityManager->flush();

        $io->success(\sprintf('Firecrawl connection "%s" %s (%s).', $name, $result, $connection->getId()));

        return true;
    }

    private static function stringOption(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return \is_string($value) ? $value : null;
    }

    private static function normalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }
}
