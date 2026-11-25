<?php

// file generated with AI assistance: Claude Code - 2026-11-25 20:48:51 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Kernel;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Dmstr\ApiConfiguration\ApiConfigurationBundle;
use Dmstr\ApiPlatformUtils\ApiPlatformUtilsBundle;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;
use Dmstr\Firecrawl\FirecrawlBundle;
use Dmstr\Firecrawl\Import\PageImporterInterface;
use Dmstr\Firecrawl\Security\FirecrawlPermissions;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use Dmstr\Firecrawl\Tests\Fixtures\InMemoryPageImporter;
use Dmstr\OpenApiJsonSchema\OpenApi\OperationInputSchemaDecorator;
use Dmstr\OpenApiJsonSchema\OpenApiJsonSchemaBundle;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Dmstr\SymfonyJobQueue\Message\JobMessage;
use Dmstr\SymfonyJobQueue\SymfonyJobQueueBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Minimal host application for the integration tests of the bundle.
 *
 * - PostgreSQL from the `DATABASE_URL` environment variable (default in
 *   `phpunit.dist.xml`). The schema is created by running the migrations of
 *   all bundles, this bundle's own ones included ({@see TestDatabase}); the
 *   container itself never connects, so tests without the `database` group
 *   run without a server.
 * - API Platform with the resources of the bundles (discovered from their
 *   `src/Entity` and `src/ApiResource` directories) below {@see self::API_PREFIX},
 *   dmstr/api-configuration-bundle with its controller routes,
 *   dmstr/symfony-job-queue-bundle, dmstr/openapi-json-schema-bundle and
 *   dmstr/api-platform-utils-bundle with the insecure test key
 *   {@see FirecrawlSecrets::INSECURE_TEST_KEY}; the host-side services
 *   these bundles expect: an {@see OperationInputSchemaResolver} and the
 *   {@see OperationInputSchemaDecorator} on the OpenAPI factory.
 * - Monolog with the bundle's channel `firecrawl` (prepended by the bundle)
 *   and a null handler, Messenger with the `async` transport in memory (no
 *   worker runs; tests run queued jobs themselves), `cache.app` as an array
 *   adapter.
 * - The test seams a host application registers under `when@test`: the
 *   scripted {@see FakeFirecrawlClientFactory} as the client factory (no
 *   Firecrawl instance is contacted) and {@see InMemoryPageImporter} as the
 *   host's {@see PageImporterInterface}; both public, so tests script and
 *   read them.
 * - One stateless firewall authenticated from request headers by
 *   {@see TestHeaderAuthenticator}; `^/api` requires a full login.
 * - The permission contract of a host application: {@see self::process()}
 *   collects the `za7_core.permission` tags into the parameter
 *   `za7_core.permissions.registered`, {@see self::PERMISSIONS} is the map
 *   parameter `za7_core.permissions`, and {@see TestPermissionVoter} decides
 *   with both.
 *
 * Variants, selected by the kernel environment:
 * - {@see self::ENV_WITH_VOTER}: everything above;
 * - {@see self::ENV_NO_VOTER}: no permission voter at all, so every
 *   permission check of the bundle is denied (no voter grants it).
 *
 * Cache and logs live under the system temp directory, one directory per
 * variant. The crawl commands write below `%kernel.project_dir%/var/crawl/`
 * (the repository root, `var/` is gitignored); the crawl profile tests
 * remove their profile directories again.
 */
final class TestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    public const string ENV_WITH_VOTER = 'test';
    public const string ENV_NO_VOTER = 'test_no_voter';

    public const string API_PREFIX = '/api';

    public const string PERMISSION_TAG = 'za7_core.permission';
    public const string PERMISSION_MAP_PARAMETER = 'za7_core.permissions';
    public const string PERMISSIONS_REGISTERED_PARAMETER = 'za7_core.permissions.registered';

    /** Holds the pass-through and the crawl profiles. */
    public const string ROLE_FIRECRAWL_ADMIN = 'ROLE_FIRECRAWL_ADMIN';

    /** Starts, scans and imports jobs and uses the onboarding tools. */
    public const string ROLE_CRAWLER = 'ROLE_CRAWLER';

    /** Permission => roles that hold it, as a host application would map it */
    public const array PERMISSIONS = [
        FirecrawlPermissions::PASSTHRU_EXECUTE => ['ROLE_ADMIN', self::ROLE_FIRECRAWL_ADMIN],
        FirecrawlPermissions::PROFILE_MANAGE => ['ROLE_ADMIN', self::ROLE_FIRECRAWL_ADMIN],
        FirecrawlPermissions::JOB_READ => ['ROLE_ADMIN', 'ROLE_USER'],
        FirecrawlPermissions::JOB_WRITE => ['ROLE_ADMIN', self::ROLE_CRAWLER],
        FirecrawlPermissions::ONBOARDING_USE => ['ROLE_ADMIN', self::ROLE_CRAWLER],
        // Permissions of dmstr/api-configuration-bundle and
        // dmstr/symfony-job-queue-bundle
        'apiconfig:read' => ['ROLE_ADMIN'],
        'apiconfig:write' => ['ROLE_ADMIN'],
        'apiconfig:authorize' => ['ROLE_ADMIN'],
        'job:read' => ['ROLE_ADMIN'],
        'job:prune' => ['ROLE_ADMIN'],
    ];

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new MonologBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new ApiPlatformBundle();
        yield new OpenApiJsonSchemaBundle();
        yield new ApiPlatformUtilsBundle();
        yield new ApiConfigurationBundle();
        yield new SymfonyJobQueueBundle();
        yield new FirecrawlBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->tmpDir().'/cache';
    }

    public function getLogDir(): string
    {
        return $this->tmpDir().'/log';
    }

    public function hasPermissionVoter(): bool
    {
        return self::ENV_NO_VOTER !== $this->environment;
    }

    /**
     * Host-application wiring that needs the complete container: the list of
     * registered permissions.
     */
    public function process(ContainerBuilder $container): void
    {
        $registered = [];
        foreach ($container->findTaggedServiceIds(self::PERMISSION_TAG) as $tags) {
            foreach ($tags as $attributes) {
                if (\is_string($attributes['permission'] ?? null)) {
                    $registered[$attributes['permission']] = true;
                }
            }
        }
        $registered = array_keys($registered);
        sort($registered);
        $container->setParameter(self::PERMISSIONS_REGISTERED_PARAMETER, $registered);
    }

    /**
     * Not the repository's `config/` (the bundle's service definitions): in
     * debug mode FrameworkBundle dumps `reference.php` into an existing
     * config directory. This one does not exist, so nothing is written.
     */
    private function getConfigDir(): string
    {
        return $this->tmpDir().'/config';
    }

    private function tmpDir(): string
    {
        return sys_get_temp_dir().'/dmstr-firecrawl-bundle-tests/'.$this->environment;
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->parameters()
            ->set(self::PERMISSION_MAP_PARAMETER, self::PERMISSIONS)
            // Required by dmstr/api-configuration-bundle (OAuth callback of
            // its connection types); no test follows it.
            ->set('app.oauth.redirect_uri', 'http://localhost/api/oauth/callback');

        $container->extension('framework', [
            'test' => true,
            'secret' => 'test-secret',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'cache' => ['app' => 'cache.adapter.array'],
            'messenger' => [
                'transports' => ['async' => 'in-memory://'],
                'routing' => [JobMessage::class => 'async'],
            ],
        ]);

        $container->extension('monolog', [
            'handlers' => [
                'null' => ['type' => 'null'],
            ],
        ]);

        $container->extension('security', [
            'providers' => [
                'test_users' => ['memory' => ['users' => []]],
            ],
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'stateless' => true,
                    'provider' => 'test_users',
                    'custom_authenticators' => [TestHeaderAuthenticator::class],
                ],
            ],
            'access_control' => [
                // Only whether a login is needed; the operations check their
                // permissions themselves.
                ['path' => '^'.self::API_PREFIX, 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(resolve:DATABASE_URL)%',
            ],
            'orm' => [
                'enable_native_lazy_objects' => true,
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                // IDENTITY for AUTO ids on PostgreSQL with DBAL 3, as a host
                // application configures it.
                'identity_generation_preferences' => [
                    PostgreSQLPlatform::class => 'identity',
                ],
                // The entities of dmstr/api-configuration-bundle and
                // dmstr/symfony-job-queue-bundle; this bundle prepends the
                // mapping of its own.
                'auto_mapping' => true,
            ],
        ]);

        $container->extension('doctrine_migrations', [
            'storage' => [
                'table_storage' => ['table_name' => 'doctrine_migration_versions'],
            ],
        ]);

        $container->extension('api_platform', [
            'title' => 'firecrawl-bundle tests',
            'formats' => [
                'jsonld' => ['application/ld+json'],
                'json' => ['application/json'],
            ],
            'docs_formats' => [
                'jsonld' => ['application/ld+json'],
                'jsonopenapi' => ['application/vnd.openapi+json'],
            ],
            'patch_formats' => [
                'json' => ['application/merge-patch+json'],
            ],
            'serializer' => ['hydra_prefix' => true],
            'defaults' => ['stateless' => true],
            'enable_swagger_ui' => false,
            'enable_re_doc' => false,
        ]);

        $container->extension('dmstr_api_platform_utils', [
            'credential_encryption' => [
                'key' => FirecrawlSecrets::INSECURE_TEST_KEY,
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // Test seam: no Firecrawl instance is contacted. Public, so tests
        // script answers and read the recorded requests.
        $services->set(FakeFirecrawlClientFactory::class)->public();
        $services->alias(FirecrawlClientFactoryInterface::class, FakeFirecrawlClientFactory::class);

        // The host application's port of the import; public, so tests read
        // the recorded calls.
        $services->set(InMemoryPageImporter::class)->public();
        $services->alias(PageImporterInterface::class, InMemoryPageImporter::class);

        // The application's input-schema resolver, required by
        // dmstr/symfony-job-queue-bundle. It knows no schema directory of its
        // own; the bundles register their schemas with resolvers of their own.
        $services->set(OperationInputSchemaResolver::class)
            ->arg('$schemaBasePaths', []);

        // The application's OpenAPI decorator that turns the resolved input
        // schemas into request bodies (dmstr/openapi-json-schema-bundle ships
        // it unregistered).
        $services->set(OperationInputSchemaDecorator::class)
            ->decorate('api_platform.openapi.factory')
            ->arg('$decorated', service('.inner'));

        $services->set(TestHeaderAuthenticator::class);

        if ($this->hasPermissionVoter()) {
            // Autoconfiguration adds the `security.voter` tag; public so that
            // tests can tell the variants apart
            $services->set(TestPermissionVoter::class)
                ->args([param(self::PERMISSION_MAP_PARAMETER), param(self::PERMISSIONS_REGISTERED_PARAMETER)])
                ->public();
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'api_platform')->prefix(self::API_PREFIX);
        $routes->import('@ApiConfigurationBundle/config/routes.yaml');
    }
}
