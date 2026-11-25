<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Passthru\Operation;
use Dmstr\Firecrawl\Passthru\PassthruService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Console twin of one pass-through resource: builds the raw JSON body from
 * the url(s) argument and `--body`, and runs it through the same
 * {@see PassthruService::forward()} as the REST processor, so validation,
 * connection choice and auditing (user `cli`) are identical. Prints the
 * decoded Firecrawl answer as pretty JSON to stdout; on an HTTP error of the
 * service (400, 422, 502) prints the message to stderr and exits with 1.
 */
abstract class AbstractPassthruCommand extends Command
{
    private const int JSON_OPTIONS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly PassthruService $passthruService,
    ) {
        parent::__construct();
    }

    abstract protected function operation(): Operation;

    /**
     * Example `--body` value for the help text.
     */
    abstract protected function bodyExample(): string;

    protected function configure(): void
    {
        $operation = $this->operation();
        $batch = 'urls' === $operation->urlKey();

        if ($batch) {
            $this->addArgument('urls', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Absolute http(s) URLs to scrape');
        } else {
            $this->addArgument('url', InputArgument::REQUIRED, 'Absolute http(s) URL');
        }
        $this
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Active firecrawl connection (name, full or partial UUID, or IRI); default: the only active one')
            ->addOption('body', 'b', InputOption::VALUE_REQUIRED, \sprintf('JSON object of further Firecrawl options; the %s argument wins over "%s" in it', $operation->urlKey(), $operation->urlKey()));

        $target = $batch ? 'https://example.org/a https://example.org/b' : 'https://example.org';
        $this->setHelp(<<<HELP
            Console twin of <info>POST /api{$operation->uriTemplate()}</info>: forwards the request to
            <info>POST {$operation->remotePath()}</info> of a Firecrawl instance and prints its answer as
            pretty JSON. The body is validated against the same input schema as the REST
            operation; options it does not list are forwarded unchanged.

            Without <comment>--connection</comment> the single active firecrawl connection is used; the
            command fails when there is none or several. The request is synchronous and
            limited by the connection timeout (at most 55 s). Nothing is stored. The call
            is logged on the <comment>firecrawl</comment> channel with the user <comment>cli</comment>.

            Exit code 1 with the message on invalid input (400/422), an unusable
            connection (422) or a Firecrawl failure (502).

            Examples:

              <info>%command.full_name% {$target}</info>
              <info>%command.full_name% {$target} --connection=default</info>
              <info>%command.full_name% {$target} --body='{$this->bodyExample()}'</info>
              <info>%command.full_name% {$target} | jq .</info>
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errorIo = $io->getErrorStyle();
        $operation = $this->operation();

        $bodyOption = $input->getOption('body');
        $body = new \stdClass();
        if (\is_string($bodyOption) && '' !== trim($bodyOption)) {
            try {
                $body = json_decode($bodyOption, false, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $errorIo->error(\sprintf('--body is not valid JSON: %s', $e->getMessage()));

                return Command::FAILURE;
            }
            if (!$body instanceof \stdClass) {
                $errorIo->error('--body must be a JSON object.');

                return Command::FAILURE;
            }
        }

        $key = $operation->urlKey();
        $body->{$key} = $input->getArgument($key);

        $connection = $input->getOption('connection');
        $reference = \is_string($connection) && '' !== trim($connection) ? trim($connection) : null;

        try {
            $payload = $this->passthruService->forward(
                $operation->value,
                json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION),
                $reference,
            );
        } catch (HttpExceptionInterface $e) {
            $errorIo->error($e->getMessage());

            return Command::FAILURE;
        }

        $output->writeln(json_encode([] === $payload ? new \stdClass() : $payload, \JSON_THROW_ON_ERROR | self::JSON_OPTIONS), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
