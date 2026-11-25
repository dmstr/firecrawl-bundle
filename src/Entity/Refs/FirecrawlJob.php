<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Entity\Refs;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\OpenApi\OpenApiTag;
use Dmstr\Firecrawl\Security\FirecrawlPermissions;
use Dmstr\Firecrawl\State\FirecrawlJobImportProcessor;
use Dmstr\Firecrawl\State\FirecrawlJobScanProcessor;
use Dmstr\Firecrawl\State\FirecrawlJobStartProcessor;
use Dmstr\Firecrawl\State\FirecrawlJobSyncProcessor;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * REF entity mirroring jobs on a Firecrawl instance (a `firecrawl`
 * connection, see {@see \Dmstr\Firecrawl\Connection\FirecrawlExtension}).
 *
 * Types: `scrape` (one page, synchronous), `crawl` (a site from a start
 * URL, asynchronous) and `batch_scrape` (a list of URLs, asynchronous; the
 * row's `url` is the first URL, the full list is `options.urls`).
 *
 * Rows are written only by the job services in {@see \Dmstr\Firecrawl\Jobs}
 * (API actions and their console twins `firecrawl:jobs:*`); there is no
 * generic write operation:
 *  - `POST /refs/firecrawl_jobs` starts a job on the connection in the body
 *    (or the single active one): a scrape runs synchronously and ends
 *    `completed` with a result summary, a crawl or batch scrape stores the
 *    remote id and ends `scraping`; a failed remote call is stored as
 *    `failed` with `errorMessage` ({@see \Dmstr\Firecrawl\Jobs\JobStarter});
 *  - `POST /refs/firecrawl_jobs/{id}/sync` refreshes status and result
 *    summary of a crawl (`GET /v2/crawl/{id}`) or batch scrape
 *    (`GET /v2/batch/scrape/{id}`) from the instance
 *    ({@see \Dmstr\Firecrawl\Jobs\JobSyncer});
 *  - `POST /refs/firecrawl_jobs/scan` queues a job (type `firecrawl_job_scan`)
 *    that creates a row for every active crawl of the instance that has none
 *    yet ({@see \Dmstr\Firecrawl\Jobs\JobScanner}). The scan mirrors crawl
 *    jobs only, because Firecrawl lists only active crawls
 *    (`GET /v2/crawl/active`); scrapes are synchronous and have no remote
 *    id, batch scrapes are not listed. Existing rows are never modified by
 *    the scan; `sync` refreshes them;
 *  - `POST /refs/firecrawl_jobs/{id}/import` queues a job (type
 *    `firecrawl_job_import`) that imports the result of a `completed` crawl
 *    or batch scrape into a knowledge collection, one document per page
 *    ({@see \Dmstr\Firecrawl\Import\JobImporter}); the row itself is not
 *    changed.
 *
 * The remote id is unique per connection (partial unique index on
 * `(api_configuration_id, firecrawl_id) WHERE firecrawl_id IS NOT NULL`), so
 * a crawl is never mirrored twice. Rows of a connection are deleted with the
 * connection (FK ON DELETE CASCADE). Rows for one connection:
 *
 *     $entityManager->getRepository(FirecrawlJob::class)
 *         ->findBy(['apiConfiguration' => $connection], ['createdAt' => 'DESC']);
 */
#[ORM\Entity]
#[ORM\Table(name: 'ref_firecrawl_job')]
#[ORM\UniqueConstraint(
    name: 'uniq_ref_firecrawl_job_api_configuration_firecrawl_id',
    columns: ['api_configuration_id', 'firecrawl_id'],
    options: ['where' => '(firecrawl_id IS NOT NULL)'],
)]
#[ORM\Index(name: 'idx_firecrawl_job_status', columns: ['status'])]
#[ORM\Index(name: 'idx_ref_firecrawl_job_api_configuration', columns: ['api_configuration_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(
            uriTemplate: '/firecrawl_jobs',
            description: 'Starts a scrape, crawl or batch scrape on a Firecrawl instance and records it here. A scrape finishes right away with its result summary; a crawl or batch scrape keeps running on the instance, use sync to follow it. If the instance cannot be reached, the job is recorded as failed with the error message.',
            status: 201,
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::FIRECRAWL],
                summary: 'Starts a job on a Firecrawl instance.',
                description: 'A `scrape` runs synchronously and is stored as `completed` with a result summary; a `crawl` (from `url`) or `batch_scrape` (from `urls`) is started remotely and stored as `scraping` with its `firecrawlId` (refresh it with `/sync`). A failed remote call is stored as `failed` with `errorMessage`, still with status 201. `apiConfiguration` selects the `firecrawl` connection; without it the single active one is used. The body is validated before anything is stored.',
            ),
            security: "is_granted('".FirecrawlPermissions::JOB_WRITE."')",
            input: false,
            read: false,
            deserialize: false,
            validate: false,
            name: self::OPERATION_START,
            processor: FirecrawlJobStartProcessor::class,
        ),
        new Post(
            uriTemplate: '/firecrawl_jobs/{id}/sync',
            description: 'Fetches the current status and progress of this crawl or batch scrape from the Firecrawl instance and updates the job. Scrapes have nothing to refresh and stay unchanged.',
            status: 200,
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::FIRECRAWL],
                summary: 'Refreshes a crawl or batch scrape job from its Firecrawl instance.',
                description: 'Copies status and result summary (`total`, `completed`, `credits_used`) of the remote crawl or batch scrape. A failing remote call keeps the status and sets `errorMessage`. Jobs without a remote id (scrapes) are returned unchanged.',
            ),
            security: "is_granted('".FirecrawlPermissions::JOB_WRITE."')",
            input: false,
            deserialize: false,
            validate: false,
            name: self::OPERATION_SYNC,
            processor: FirecrawlJobSyncProcessor::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/firecrawl_jobs/scan',
            description: 'Looks for crawls that are running on the Firecrawl instances but are not listed here yet, and adds them. Existing jobs are left as they are. The scan runs in the background; its progress is shown in **Job**.',
            status: 201,
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::FIRECRAWL],
                summary: 'Queues a scan of the active crawls of Firecrawl instances.',
                description: 'Creates a job of type `firecrawl_job_scan` that adds a row for every active crawl (`GET /v2/crawl/active`) of all active `firecrawl` connections, or of the connection given as `apiConfiguration`, that is not mirrored yet. Existing rows are left alone. The body is validated before the job is created.',
            ),
            security: "is_granted('".FirecrawlPermissions::JOB_WRITE."')",
            input: false,
            output: Job::class,
            read: false,
            deserialize: false,
            validate: false,
            name: self::OPERATION_SCAN,
            processor: FirecrawlJobScanProcessor::class,
        ),
        new Post(
            uriTemplate: '/firecrawl_jobs/{id}/import',
            description: 'Imports the pages of this completed crawl or batch scrape into a knowledge collection, one document per page: new pages are added, changed ones replaced, unchanged ones skipped. Import soon after the job is completed, because Firecrawl deletes results after a while. The import runs in the background; its progress and report are shown in **Job**.',
            status: 201,
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::FIRECRAWL],
                summary: 'Queues the import of a completed crawl or batch scrape into a knowledge collection.',
                description: 'Creates a job of type `firecrawl_job_import` that imports every result page as one document of `collection` (UUID, IRI or name): new pages are created, changed pages replaced, unchanged pages skipped. With `deleteVanished` (crawls only) documents of the same site that are no longer in the crawl are removed, but only after a complete import without failed pages. The job must be `completed` and of type `crawl` or `batch_scrape`; the body and the collection are validated before the job is created (422). The result report is the job\'s `resultData`. Import shortly after `sync` reports `completed`: Firecrawl removes results after a while.',
            ),
            security: "is_granted('".FirecrawlPermissions::JOB_WRITE."')",
            input: false,
            output: Job::class,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_IMPORT,
            processor: FirecrawlJobImportProcessor::class,
            throwOnNotFound: true,
        ),
    ],
    routePrefix: '/refs',
    security: "is_granted('".FirecrawlPermissions::JOB_READ."')",
    paginationEnabled: true,
    paginationItemsPerPage: 30,
    description: <<<'MD'
        A Firecrawl job is a scrape, crawl or batch scrape on a Firecrawl instance, recorded here so you can follow it. A scrape covers one page and finishes at once, a crawl follows the links of a site from a start URL, and a batch scrape covers a list of URLs; the last two keep running on the instance.

        Here you start jobs, refresh their status, pick up crawls that were started elsewhere (scan) and import the pages of a completed job into a knowledge collection. Jobs are only created and changed through these actions.

        Related: each job belongs to a Firecrawl connection in **ApiConfiguration** and is removed together with it. Scans and imports run in the background as **Job**.
        MD,
    openapi: new OpenApiOperation(tags: [OpenApiTag::FIRECRAWL]),
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'type' => 'exact', 'url' => 'partial', 'apiConfiguration' => 'exact'])]
class FirecrawlJob
{
    public const string TYPE_SCRAPE = 'scrape';
    public const string TYPE_CRAWL = 'crawl';
    public const string TYPE_BATCH_SCRAPE = 'batch_scrape';
    public const array TYPES = [self::TYPE_SCRAPE, self::TYPE_CRAWL, self::TYPE_BATCH_SCRAPE];

    public const string STATUS_PENDING = 'pending';
    public const string STATUS_SCRAPING = 'scraping';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED = 'failed';

    public const string OPERATION_START = 'firecrawl_job_start';
    public const string OPERATION_SYNC = 'firecrawl_job_sync';
    public const string OPERATION_SCAN = 'firecrawl_job_scan';
    public const string OPERATION_IMPORT = 'firecrawl_job_import';

    /** Job type of the asynchronous scan (dmstr_job.type is VARCHAR(50)). */
    public const string JOB_TYPE_SCAN = 'firecrawl_job_scan';

    /** Job type of the asynchronous result import. */
    public const string JOB_TYPE_IMPORT = 'firecrawl_job_import';

    /** Length of the `firecrawl_id` column. */
    public const int MAX_FIRECRAWL_ID_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** The `firecrawl` connection the job runs on. */
    #[ORM\ManyToOne(targetEntity: ApiConfiguration::class)]
    #[ORM\JoinColumn(name: 'api_configuration_id', nullable: false, onDelete: 'CASCADE')]
    private ApiConfiguration $apiConfiguration;

    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\Choice(choices: self::TYPES)]
    private string $type = self::TYPE_CRAWL;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Url(requireTld: false)]
    private string $url = '';

    /**
     * Firecrawl request options (formats, limit, scrapeOptions, ...); for a
     * batch scrape also the full URL list as `urls`.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $options = null;

    /** Remote job id (crawl and batch scrape; scrape is synchronous), unique per connection. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_FIRECRAWL_ID_LENGTH, nullable: true)]
    private ?string $firecrawlId = null;

    /** pending | scraping | completed | failed (mirrors the remote status). */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_PENDING;

    /**
     * Result summary: counts for crawls and batch scrapes, payload metadata
     * for scrapes.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $resultSummary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(ApiConfiguration $apiConfiguration)
    {
        $this->id = Uuid::v4();
        $this->apiConfiguration = $apiConfiguration;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getApiConfiguration(): ApiConfiguration
    {
        return $this->apiConfiguration;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOptions(): ?array
    {
        return $this->options;
    }

    /**
     * @param array<string, mixed>|null $options
     */
    public function setOptions(?array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getFirecrawlId(): ?string
    {
        return $this->firecrawlId;
    }

    public function setFirecrawlId(?string $firecrawlId): self
    {
        $this->firecrawlId = $firecrawlId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResultSummary(): ?array
    {
        return $this->resultSummary;
    }

    /**
     * @param array<string, mixed>|null $resultSummary
     */
    public function setResultSummary(?array $resultSummary): self
    {
        $this->resultSummary = $resultSummary;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

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
}
