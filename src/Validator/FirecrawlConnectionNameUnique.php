<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint for {@see \Dmstr\ApiConfiguration\Entity\ApiConfiguration}:
 * the name of a `firecrawl` connection must be unique among `firecrawl`
 * connections.
 *
 * The vendor table has no unique index on the name and other connection
 * types may reuse names, so the rule applies to `type = 'firecrawl'` only.
 * The bundle attaches it to the vendor entity through the validation mapping
 * file config/validator/ApiConfiguration.xml.
 */
final class FirecrawlConnectionNameUnique extends Constraint
{
    public const string NOT_UNIQUE_ERROR = 'b5d1e0a2-8c3f-4e6b-9f27-1a4c8d2e6b93';

    protected const ERROR_NAMES = [
        self::NOT_UNIQUE_ERROR => 'NOT_UNIQUE_ERROR',
    ];

    public string $message = 'A Firecrawl connection named {{ name }} already exists.';

    /**
     * @param string[]|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
