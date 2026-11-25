<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit;

use Dmstr\Firecrawl\State\InputBodyValidator;
use Dmstr\OpenApiJsonSchema\Interface\InputSchemaResolverInterface;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free test of the bundle's raw-body validation against an input
 * schema from a resolver stub, plus the resolver roots the bundle registers.
 */
final class InputBodyValidatorTest extends TestCase
{
    private const string OPERATION = 'firecrawl_test_scrape';

    private const array SCHEMA = [
        'type' => 'object',
        'required' => ['url'],
        'properties' => [
            'url' => ['type' => 'string', 'minLength' => 1],
            'formats' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'additionalProperties' => false,
    ];

    public function testValidBodyIsDecoded(): void
    {
        self::assertSame(
            ['url' => 'https://example.org', 'formats' => ['markdown']],
            self::validator()->validate(self::OPERATION, '{"url": "https://example.org", "formats": ["markdown"]}'),
        );
    }

    public function testEmptyBodyCountsAsEmptyObject(): void
    {
        $validator = new InputBodyValidator(self::resolver(['type' => 'object']));

        self::assertSame([], $validator->validate(self::OPERATION, "  \n"));
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('The request body is not valid JSON');

        self::validator()->validate(self::OPERATION, '{"url": ');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function schemaViolations(): iterable
    {
        yield 'empty body misses url' => [''];
        yield 'empty url' => ['{"url": ""}'];
        yield 'url not a string' => ['{"url": 42}'];
        yield 'unknown property' => ['{"url": "https://example.org", "foo": 1}'];
        yield 'not an object' => ['["https://example.org"]'];
    }

    #[DataProvider('schemaViolations')]
    public function testSchemaViolationIsUnprocessable(string $body): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Input validation failed for operation "firecrawl_test_scrape"');

        self::validator()->validate(self::OPERATION, $body);
    }

    public function testOperationWithoutSchemaIsALogicError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No input schema is registered for operation "firecrawl_unknown"');

        (new InputBodyValidator(self::resolver(null)))->validate('firecrawl_unknown', '{}');
    }

    public function testBundleResolverRootsExist(): void
    {
        $bundle = \dirname(__DIR__, 2);

        self::assertDirectoryExists($bundle.'/config/schemas');
        self::assertDirectoryExists($bundle.'/src/Entity');
        // No operation of the bundle has a schema yet; the resolver answers null.
        $resolver = new OperationInputSchemaResolver([$bundle.'/config/schemas'], [[$bundle.'/src/Entity', 'Dmstr\\Firecrawl\\Entity']]);
        self::assertNull($resolver->resolve('firecrawl_nothing_here'));
    }

    private static function validator(): InputBodyValidator
    {
        return new InputBodyValidator(self::resolver(self::SCHEMA));
    }

    /**
     * @param array<string, mixed>|null $schema
     */
    private static function resolver(?array $schema): InputSchemaResolverInterface
    {
        return new class($schema) implements InputSchemaResolverInterface {
            /**
             * @param array<string, mixed>|null $schema
             */
            public function __construct(private readonly ?array $schema)
            {
            }

            public function supports(string $operationName, array $context = []): bool
            {
                return null !== $this->schema;
            }

            public function resolve(string $operationName, array $context = []): ?array
            {
                return $this->schema;
            }
        };
    }
}
