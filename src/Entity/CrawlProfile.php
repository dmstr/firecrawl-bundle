<?php

// file generated with AI assistance: Claude Code - 2026-11-05 12:30:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Connection\FirecrawlExtension;
use Dmstr\Firecrawl\Crawl\CrawlProfile as RuntimeProfile;
use Dmstr\Firecrawl\OpenApi\OpenApiTag;
use Dmstr\Firecrawl\Security\FirecrawlPermissions;
use Dmstr\Firecrawl\State\CrawlProfileJobProcessor;
use Dmstr\Firecrawl\State\CrawlProfileProcessor;
use Dmstr\OpenApiJsonSchema\Attribute\JsonSchema;
use Dmstr\OpenApiJsonSchema\Interface\JsonSchemaProviderInterface;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A crawl profile: one website the crawl walks (`app:crawl:discover <name>`,
 * `app:crawl:run <name>`) and the Firecrawl connection it runs on. Document
 * files go through the active `file-converter` connection
 * ({@see \Dmstr\Firecrawl\Connection\FileConverterConnectionResolver}); a
 * profile names none.
 *
 * Three flat sections, the ones of the former YAML profile, each with a
 * schema in `Entity/CrawlProfile/`: `discover`, `crawl` and `auth`. Short lists are comma
 * separated text, file extensions a fixed set; {@see toProfileData()} turns
 * the sections into the structure the crawl reads (and the former YAML files
 * had), {@see applyProfileData()} the other way round. A fourth section,
 * `import`, is the target of the Import action
 * ({@see \Dmstr\Firecrawl\Crawl\CrawlImporter}); the crawl does not read it. The login password
 * is `auth.password`, stored encrypted in its own column
 * ({@see CrawlProfileProcessor}) and read as the mask; `auth.removePassword` deletes it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'firecrawl_crawl_profile')]
#[ORM\UniqueConstraint(name: 'uniq_firecrawl_crawl_profile_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['name'], message: 'Another crawl profile already uses this name.')]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(status: 201, processor: CrawlProfileProcessor::class),
        new Patch(processor: CrawlProfileProcessor::class),
        new Delete(),
        new Post(
            uriTemplate: '/crawl_profiles/{id}/discover',
            status: 201,
            description: 'Step 1: finds the pages and document files of the site and replaces the URL list (**CrawlUrlList**). Runs in the background; progress and output are shown in **Job**.',
            openapi: new OpenApiOperation(summary: 'Queues the discover of the profile (app:crawl:discover).'),
            input: false,
            output: Job::class,
            normalizationContext: ['groups' => ['job:read']],
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_DISCOVER,
            processor: CrawlProfileJobProcessor::class,
        ),
        new Post(
            uriTemplate: '/crawl_profiles/{id}/crawl',
            status: 201,
            description: 'Step 2: turns the pages and files of the URL list into Markdown files, ready for the import. Runs in the background; progress and output are shown in **Job**.',
            openapi: new OpenApiOperation(summary: 'Queues the crawl of the profile (app:crawl:run).'),
            input: false,
            output: Job::class,
            normalizationContext: ['groups' => ['job:read']],
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_CRAWL,
            processor: CrawlProfileJobProcessor::class,
        ),
        new Post(
            uriTemplate: '/crawl_profiles/{id}/import',
            status: 201,
            description: 'Step 3: puts the Markdown files of the latest crawl into the collection of the Import section, with its chunk sizes. New pages are added, changed ones replaced, unchanged ones skipped, and pages the crawl no longer has are removed. Runs in the background; progress and output are shown in **Job**.',
            openapi: new OpenApiOperation(summary: 'Queues the import of the profile (app:crawl:import).'),
            input: false,
            output: Job::class,
            normalizationContext: ['groups' => ['job:read']],
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_IMPORT,
            processor: CrawlProfileJobProcessor::class,
        ),
    ],
    routePrefix: '/admin',
    description: <<<'MD'
        A crawl profile describes one website for the crawl: where to start, which links to skip, which page elements to cut out, how to log in and whether document files are converted.

        The crawl runs on the profile's Firecrawl connection; document files go through the active file converter connection. Run it in three steps from the profile's actions: **Discover**, **Crawl**, then **Import** into the collection of the Import section (or `app:crawl:discover`, `app:crawl:run` and `app:crawl:import <name>`).

        Related: the connections are listed under **ApiConfiguration** (types `firecrawl` and `file-converter`).
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    denormalizationContext: ['groups' => [self::GROUP_WRITE]],
    security: "is_granted('".FirecrawlPermissions::PROFILE_MANAGE."')",
    order: ['name' => 'ASC'],
    paginationItemsPerPage: 30,
    openapi: new OpenApiOperation(tags: [OpenApiTag::FIRECRAWL]),
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'firecrawlConnection' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['active'])]
class CrawlProfile implements JsonSchemaProviderInterface
{
    public const string GROUP_READ = 'crawl_profile:read';
    public const string GROUP_WRITE = 'crawl_profile:write';
    public const string OPERATION_DISCOVER = 'crawl_profile_discover';
    public const string OPERATION_CRAWL = 'crawl_profile_crawl';
    public const string OPERATION_IMPORT = 'crawl_profile_import';
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';
    /** Schema file of each section field. */
    public const array SCHEMA_FILES = [
        'discover' => __DIR__.'/CrawlProfile/discover.json',
        'crawl' => __DIR__.'/CrawlProfile/crawl.json',
        'auth' => __DIR__.'/CrawlProfile/auth.json',
        'import' => __DIR__.'/CrawlProfile/import.json',
    ];
    /** What a form sends back for a password it cannot show: keeps the stored one. */
    public const string MASK = '********';

    private const array CONNECTION_PICKER = ['format' => 'iri-reference', 'x-label-property' => 'name', 'x-search-property' => 'name'];

    /** @var array<string, array<string, mixed>> decoded schemas by field, read once */
    private static array $schemas = [];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ApiProperty(identifier: true, writable: false)]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: self::NAME_PATTERN, message: 'The name may only contain lowercase letters, digits and dashes.')]
    #[ApiProperty(description: 'Name of the profile, used by the crawl commands: lowercase letters, digits and dashes; unique.')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private string $name = '';

    #[ORM\Column(type: Types::BOOLEAN)]
    #[ApiProperty(description: 'An inactive profile is not crawled.', openapiContext: ['x-format' => 'radios-inline', 'x-enumTitles' => ['No', 'Yes']])]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: ApiConfiguration::class)]
    #[ORM\JoinColumn(name: 'firecrawl_connection_id', nullable: false)]
    #[Assert\NotNull]
    #[ApiProperty(
        description: 'The `firecrawl` connection the crawl runs on (IRI).',
        openapiContext: [...self::CONNECTION_PICKER, 'x-collection' => '/api/admin/api_configurations?type='.FirecrawlExtension::NAME],
    )]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?ApiConfiguration $firecrawlConnection = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    #[JsonSchema]
    #[ApiProperty(description: 'Seeds, domains, URL patterns, depth and limit of the link walk, and which links are files.')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private array $discover = ['seeds' => []];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    #[JsonSchema]
    #[ApiProperty(description: 'Page elements removed from every page, and whether the files are converted to Markdown.')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private array $crawl = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[JsonSchema]
    #[ApiProperty(description: 'Form login of the site; null crawls anonymously and removes the password.')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?array $auth = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'import', type: Types::JSON, nullable: true)]
    #[JsonSchema]
    #[ApiProperty(description: 'Collection and chunk sizes of the Import action; null until it is set up.')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?array $import = null;

    /** The login password, encrypted (`CREDENTIALS_ENCRYPTION_KEY`). */
    #[ORM\Column(name: 'password_encrypted', type: Types::TEXT, nullable: true)]
    private ?string $passwordEncrypted = null;

    /** A new password from `auth.password` or the import, encrypted by the processor before the write; not mapped. */
    private ?string $password = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getJsonSchema(string $fieldName): ?array
    {
        $file = self::SCHEMA_FILES[$fieldName] ?? null;
        if (null === $file) {
            return null;
        }

        /** @var array<string, mixed> $schema */
        $schema = self::$schemas[$fieldName] ??= json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

        return $schema;
    }

    /**
     * Each section against its schema, the rules of the crawl itself (a login needs allowed domains) and the
     * types of the two connections.
     */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $valid = true;
        foreach (['discover' => $this->discover, 'crawl' => $this->crawl, 'auth' => $this->auth, 'import' => $this->import] as $field => $value) {
            if (null === $value) {
                continue;
            }
            $schema = json_decode((string) file_get_contents(self::SCHEMA_FILES[$field]), false, 512, \JSON_THROW_ON_ERROR);
            $result = (new Validator())->validate(self::toJsonValue($value, true), $schema);
            if ($result->isValid()) {
                continue;
            }
            $valid = false;
            foreach ((new ErrorFormatter())->format($result->error(), false) as $pointer => $message) {
                $context->buildViolation((string) $message)->atPath($field.str_replace('/', '.', '/' === $pointer ? '' : (string) $pointer))->addViolation();
            }
        }
        if (!$valid) {
            return;
        }

        try {
            RuntimeProfile::fromArray($this->name, $this->toProfileData());
        } catch (\InvalidArgumentException $e) {
            $context->buildViolation($e->getMessage())->atPath('discover.allowedDomains')->addViolation();
        }

        if (null !== $this->firecrawlConnection && FirecrawlExtension::NAME !== $this->firecrawlConnection->getType()) {
            $context->buildViolation('This is not a firecrawl connection.')->atPath('firecrawlConnection')->addViolation();
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getFirecrawlConnection(): ?ApiConfiguration
    {
        return $this->firecrawlConnection;
    }

    public function setFirecrawlConnection(?ApiConfiguration $connection): self
    {
        $this->firecrawlConnection = $connection;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getDiscover(): array
    {
        return $this->discover;
    }

    /** @param array<string, mixed> $discover */
    public function setDiscover(array $discover): self
    {
        $this->discover = $discover;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getCrawl(): array
    {
        return $this->crawl;
    }

    /** @param array<string, mixed> $crawl */
    public function setCrawl(array $crawl): self
    {
        $this->crawl = $crawl;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getImport(): ?array
    {
        return $this->import;
    }

    /** @param array<string, mixed>|null $import */
    public function setImport(?array $import): self
    {
        $this->import = [] === $import ? null : $import;

        return $this;
    }

    /** @return array<string, mixed>|null the login with `passwordSet`, and the mask as `password` when one is stored */
    public function getAuth(): ?array
    {
        if (null === $this->auth) {
            return null;
        }

        return $this->auth + ($this->isPasswordSet() ? ['password' => self::MASK] : []) + ['passwordSet' => $this->isPasswordSet()];
    }

    /**
     * `password` and `removePassword` go to the password column, not into the stored login, and the
     * read-only `passwordSet` is dropped; no login removes the password too, since an anonymous crawl
     * has no use for it.
     *
     * @param array<string, mixed>|null $auth
     */
    public function setAuth(?array $auth): self
    {
        $remove = true === ($auth['removePassword'] ?? false) || (\is_array($auth) && \array_key_exists('password', $auth) && null === $auth['password']);
        $password = $auth['password'] ?? '';
        unset($auth['password'], $auth['removePassword'], $auth['passwordSet']);
        $this->auth = [] === $auth ? null : $auth;

        if ($remove || null === $this->auth) {
            $this->setPassword(null);
        } else {
            $this->setPassword(\is_string($password) ? $password : '');
        }

        return $this;
    }

    /**
     * The sections as the crawl reads them: `discover`, `crawl.web`, `crawl.files` and `auth`, with the
     * comma separated text split into lists ({@see RuntimeProfile::fromArray()}).
     *
     * @return array<string, mixed>
     */
    public function toProfileData(): array
    {
        $d = $this->discover;
        $c = $this->crawl;
        $data = [
            'discover' => [
                'seeds' => $d['seeds'] ?? [],
                'allowed_domains' => self::split($d['allowedDomains'] ?? ''),
                'excluded_domains' => self::split($d['excludedDomains'] ?? ''),
                'excluded_url_patterns' => $d['excludedUrlPatterns'] ?? [],
                'max_depth' => $d['maxDepth'] ?? 3,
                'limit' => $d['limit'] ?? 100,
                'file_extensions' => $d['fileExtensions'] ?? [],
                'opaque_download_hints' => self::split($d['downloadHints'] ?? ''),
                'file_domains' => self::split($d['fileDomains'] ?? ''),
            ],
            'crawl' => [
                'web' => array_filter([
                    'excluded_tags' => self::split($c['excludedTags'] ?? ''),
                    'excluded_selector' => $c['excludedSelector'] ?? '',
                    'wait_for' => $c['waitFor'] ?? null,
                ], static fn (mixed $v): bool => null !== $v),
                'files' => array_filter([
                    'enabled' => $c['convertFiles'] ?? false,
                    'max_bytes' => $c['maxFileBytes'] ?? null,
                    'pdf_page_markers' => $c['pdfPageMarkers'] ?? null,
                    'ocr' => $c['ocr'] ?? null,
                    'converters' => isset($c['converters']) ? array_column((array) $c['converters'], 'converter', 'extension') : null,
                ], static fn (mixed $v): bool => null !== $v),
            ],
        ];
        if (null !== $this->auth) {
            $a = $this->auth;
            $data['auth'] = array_filter([
                'type' => $a['type'] ?? 'yii2-form',
                'login_url' => $a['loginUrl'] ?? null,
                'username_field' => $a['usernameField'] ?? null,
                'password_field' => $a['passwordField'] ?? null,
                'username' => $a['username'] ?? null,
            ], static fn (mixed $v): bool => null !== $v);
        }

        return $data;
    }

    /**
     * Sets the sections from the structure of {@see toProfileData()}, as the former YAML files have it.
     *
     * @param array<string, mixed> $data
     */
    public function applyProfileData(array $data): self
    {
        $d = (array) ($data['discover'] ?? []);
        $web = (array) ($data['crawl']['web'] ?? []);
        $f = (array) ($data['crawl']['files'] ?? []);
        $keep = static fn (mixed $v): bool => null !== $v && '' !== $v;
        $this->discover = array_filter([
            'seeds' => $d['seeds'] ?? [],
            'allowedDomains' => self::join($d['allowed_domains'] ?? []),
            'excludedDomains' => self::join($d['excluded_domains'] ?? []),
            'excludedUrlPatterns' => $d['excluded_url_patterns'] ?? null,
            'maxDepth' => $d['max_depth'] ?? null,
            'limit' => $d['limit'] ?? null,
            'fileExtensions' => $d['file_extensions'] ?? null,
            'downloadHints' => self::join($d['opaque_download_hints'] ?? []),
            'fileDomains' => self::join($d['file_domains'] ?? []),
        ], $keep);
        $this->crawl = array_filter([
            'excludedTags' => self::join($web['excluded_tags'] ?? []),
            'excludedSelector' => $web['excluded_selector'] ?? null,
            'waitFor' => $web['wait_for'] ?? null,
            'convertFiles' => $f['enabled'] ?? null,
            'maxFileBytes' => $f['max_bytes'] ?? null,
            'pdfPageMarkers' => $f['pdf_page_markers'] ?? null,
            'ocr' => $f['ocr'] ?? null,
            'converters' => \is_array($f['converters'] ?? null)
                ? array_map(static fn (string $ext, mixed $converter): array => ['extension' => $ext, 'converter' => $converter], array_keys($f['converters']), $f['converters'])
                : null,
        ], $keep);
        $a = $data['auth'] ?? null;
        $this->auth = \is_array($a) ? array_filter([
            'type' => $a['type'] ?? 'yii2-form',
            'loginUrl' => $a['login_url'] ?? null,
            'usernameField' => $a['username_field'] ?? null,
            'passwordField' => $a['password_field'] ?? null,
            'username' => $a['username'] ?? null,
        ], $keep) : null;

        return $this;
    }

    /**
     * A new password replaces the stored one, `null` removes it, an empty string or the mask
     * keeps it (what a form sends for a field it cannot show).
     */
    public function setPassword(?string $password): self
    {
        if (null === $password) {
            $this->password = null;
            $this->passwordEncrypted = null;
            $this->touch();
        } elseif ('' !== $password && self::MASK !== $password) {
            $this->password = $password;
            $this->touch();
        }

        return $this;
    }

    /** Whether a login password is stored; read as `auth.passwordSet`. */
    public function isPasswordSet(): bool
    {
        return null !== $this->passwordEncrypted || null !== $this->password;
    }

    /** The password set through the API and not yet encrypted, for the processor. */
    public function takeNewPassword(): ?string
    {
        $password = $this->password;
        $this->password = null;

        return $password;
    }

    public function getPasswordEncrypted(): ?string
    {
        return $this->passwordEncrypted;
    }

    public function setPasswordEncrypted(?string $passwordEncrypted): self
    {
        $this->passwordEncrypted = $passwordEncrypted;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<string> the items of a comma separated text */
    private static function split(mixed $text): array
    {
        return \is_string($text) ? array_values(array_filter(array_map('trim', explode(',', $text)), static fn (string $item): bool => '' !== $item)) : [];
    }

    private static function join(mixed $items): string
    {
        return \is_array($items) ? implode(', ', array_map('strval', $items)) : (string) $items;
    }

    /**
     * Decoded JSON back to what opis validates: string-keyed arrays become objects, lists stay arrays, an
     * empty array is an object where $asObject says so (the top level) and a list below it.
     */
    private static function toJsonValue(mixed $value, bool $asObject = false): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if ([] === $value) {
            return $asObject ? new \stdClass() : [];
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::toJsonValue($item), $value);
        }

        $object = new \stdClass();
        foreach ($value as $key => $item) {
            $object->{$key} = self::toJsonValue($item);
        }

        return $object;
    }

    /** A change of the unmapped password must still reach the database. */
    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

// - revised 2026-11-17
