<?php

namespace Langsys\Symfony;

use Langsys\SDK\Client;
use Langsys\Symfony\Cache\Psr6CacheAdapter;
use Langsys\Symfony\EventSubscriber\FlushPendingRegistrationsSubscriber;
use Langsys\Symfony\EventSubscriber\LocaleSubscriber;
use Langsys\Symfony\Twig\LangsysExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Symfony counterpart of langsys/laravel-sdk's service provider. Wires the
 * vanilla PHP SDK Client as a shared service (it holds the per-request locale,
 * the in-memory catalog cache, and the pending-registration queue — two
 * instances would split that state), bridges catalog caching onto a PSR-6
 * pool, and replaces the Laravel middlewares with kernel event subscribers.
 */
class LangsysBundle extends AbstractBundle
{
    protected string $extensionAlias = 'langsys';

    public function configure(DefinitionConfigurator $definition): void
    {
        // Mirrors config/langsys.php in langsys/laravel-sdk, adapted to
        // Symfony idiom: `cache.pool` names a PSR-6 pool service instead of a
        // Laravel store, everything else keeps the same names and defaults.
        $definition->rootNode()
            ->children()
                ->scalarNode('api_key')->defaultValue('%env(LANGSYS_API_KEY)%')->end()
                ->scalarNode('project_id')->defaultValue('%env(LANGSYS_PROJECT_ID)%')->end()
                ->scalarNode('api_url')->defaultValue('%env(default:langsys.api_url_default:LANGSYS_API_URL)%')->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('pool')->defaultValue('cache.app')->end()
                        ->scalarNode('prefix')->defaultValue('langsys.')->end()
                        ->integerNode('ttl')->defaultValue(3600)->end()
                    ->end()
                ->end()
                ->arrayNode('locale')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('sources')
                            ->scalarPrototype()->end()
                            ->defaultValue(['query', 'cookie', 'session', 'header'])
                        ->end()
                        ->scalarNode('query_param')->defaultValue('locale')->end()
                        ->scalarNode('cookie')->defaultValue('langsys_locale')->end()
                        ->scalarNode('session_key')->defaultValue('langsys_locale')->end()
                        ->enumNode('persist')->values(['cookie', 'session', null])->defaultValue('cookie')->end()
                        ->arrayNode('supported')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('cookie_minutes')->defaultValue(525600)->end()
                    ->end()
                ->end()
                ->booleanNode('auto_flush')->defaultTrue()->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Fallback for the env(default:...) processor used by api_url.
        $container->parameters()->set('langsys.api_url_default', 'https://api.langsys.dev/api');

        $services = $container->services();

        $services->set('langsys.cache_adapter', Psr6CacheAdapter::class)
            ->args([
                service($config['cache']['pool']),
                $config['cache']['prefix'],
                $config['cache']['ttl'],
            ]);

        $services->set('langsys.client', Client::class)
            ->args([
                $config['api_key'],
                $config['project_id'],
                [
                    'api_url' => $config['api_url'],
                    'cache' => service('langsys.cache_adapter'),
                ],
            ]);
        $services->alias(Client::class, 'langsys.client');

        $services->set('langsys.interpolator', Interpolator::class);
        $services->alias(Interpolator::class, 'langsys.interpolator');

        $services->set('langsys.translator', LangsysTranslator::class)
            ->args([
                service('langsys.client'),
                service('langsys.interpolator'),
                service('request_stack'),
                service('logger')->nullOnInvalid(),
            ])
            ->public();
        $services->alias(LangsysTranslator::class, 'langsys.translator');

        $services->set('langsys.locale_subscriber', LocaleSubscriber::class)
            ->args([service('langsys.client'), $config['locale']])
            ->tag('kernel.event_subscriber');

        $services->set('langsys.flush_subscriber', FlushPendingRegistrationsSubscriber::class)
            ->args([
                service('langsys.client'),
                $config['auto_flush'],
                service('logger')->nullOnInvalid(),
            ])
            ->tag('kernel.event_subscriber');

        if (class_exists(\Twig\Extension\AbstractExtension::class)) {
            $services->set('langsys.twig_extension', LangsysExtension::class)
                ->args([service('langsys.translator')])
                ->tag('twig.extension');
        }
    }
}
