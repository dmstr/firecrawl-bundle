<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Firecrawl v2 for dmstr/api-configuration-bundle: the `firecrawl`
 * connection type (schema branch and health probe), a REST client behind
 * {@see Client\FirecrawlClientInterface} with a scriptable test fake, a
 * connection resolver, an input-body validator, the idempotent seed
 * command `firecrawl:connection:seed`, the synchronous pass-through
 * resources under `/api/firecrawl` and the job reference table
 * {@see Entity\Refs\FirecrawlJob} (`ref_firecrawl_job`), both with
 * console twins.
 *
 * Configuration (`dmstr_firecrawl`, optional):
 *
 *     dmstr_firecrawl:
 *         seed:
 *             base_url: 'https://firecrawl.example.org'
 *
 * The seed values (`base_url`, `api_key`) are the default input of the single connection of the
 * seed command only (both default to empty); its `--file` option needs no
 * configuration. At runtime every client is built from a connection
 * record.
 */
final class FirecrawlBundle extends AbstractBundle
{
    protected string $extensionAlias = 'dmstr_firecrawl';

    public function configure(DefinitionConfigurator $definition): void
    {
        // @phpstan-ignore-next-line
        $definition->rootNode()
            ->children()
                ->arrayNode('seed')
                    ->info('Default input of firecrawl:connection:seed; the runtime reads the connection record')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('base_url')->defaultValue('')->end()
                        ->scalarNode('api_key')->defaultValue('')->end()
                    ->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Entities of the bundle: the job reference table Refs\FirecrawlJob.
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'DmstrFirecrawl' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__.'/Entity',
                        'prefix' => 'Dmstr\\Firecrawl\\Entity',
                        'alias' => 'DmstrFirecrawl',
                    ],
                ],
            ],
        ]);

        // Entity/ for Doctrine-backed resources, ApiResource/ for the
        // Doctrine-less pass-through resources.
        $builder->prependExtensionConfig('api_platform', [
            'mapping' => [
                'paths' => [__DIR__.'/Entity', __DIR__.'/ApiResource'],
            ],
        ]);

        $builder->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'Dmstr\\Firecrawl\\Migrations' => __DIR__.'/../migrations',
            ],
        ]);

        // Name uniqueness of firecrawl connections on the vendor
        // ApiConfiguration entity. Deliberately not under config/validation/:
        // FrameworkBundle loads that directory of every bundle by convention,
        // and the constraint would then be registered twice.
        $builder->prependExtensionConfig('framework', [
            'validation' => [
                'mapping' => [
                    'paths' => [__DIR__.'/../config/validator/ApiConfiguration.xml'],
                ],
            ],
        ]);

        // Dedicated log channel; services receive it as $firecrawlLogger.
        $builder->prependExtensionConfig('monolog', [
            'channels' => ['firecrawl'],
        ]);
    }

    /**
     * @param array{seed: array{base_url: string, api_key: string}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('dmstr_firecrawl.dir', \dirname(__DIR__))
            ->set('dmstr_firecrawl.seed.base_url', $config['seed']['base_url'])
            ->set('dmstr_firecrawl.seed.api_key', $config['seed']['api_key']);

        $container->import(__DIR__.'/../config/services.yaml');
    }
}
