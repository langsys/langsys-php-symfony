<?php

namespace Langsys\Symfony\Tests;

use Langsys\SDK\Client;
use Langsys\Symfony\Cache\Psr6CacheAdapter;
use Langsys\Symfony\EventSubscriber\FlushPendingRegistrationsSubscriber;
use Langsys\Symfony\EventSubscriber\LocaleSubscriber;
use Langsys\Symfony\Interpolator;
use Langsys\Symfony\LangsysBundle;
use Langsys\Symfony\LangsysTranslator;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * No Laravel analogue beyond ServiceProviderTest: this covers the DI wiring
 * that replaces it. The Client must be shared — it holds the per-request
 * locale, the in-memory catalog, and the pending-registration queue, so a
 * second instance would silently split all three.
 */
class LangsysBundleTest extends PhpUnitTestCase
{
    private function build(array $config = []): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        // AbstractBundle resolves the env-placeholder defaults through the
        // kernel parameters a real application always has.
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.debug', false);
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new LangsysBundle())->getContainerExtension();
        $extension->load([['api_key' => 'test-key', 'project_id' => 'test-project'] + $config], $builder);

        return $builder;
    }

    public function testUsesTheLangsysExtensionAlias(): void
    {
        $this->assertSame('langsys', (new LangsysBundle())->getContainerExtension()->getAlias());
    }

    public function testRegistersTheCoreServices(): void
    {
        $builder = $this->build();

        $this->assertTrue($builder->hasDefinition('langsys.client'));
        $this->assertTrue($builder->hasDefinition('langsys.translator'));
        $this->assertTrue($builder->hasDefinition('langsys.interpolator'));
        $this->assertTrue($builder->hasDefinition('langsys.cache_adapter'));
    }

    public function testRegistersAutowiringAliases(): void
    {
        $builder = $this->build();

        $this->assertTrue($builder->hasAlias(Client::class));
        $this->assertTrue($builder->hasAlias(LangsysTranslator::class));
        $this->assertTrue($builder->hasAlias(Interpolator::class));
    }

    public function testTheClientIsShared(): void
    {
        $this->assertTrue($this->build()->getDefinition('langsys.client')->isShared());
    }

    public function testTheTranslatorIsPublicForNonAutowiredCallers(): void
    {
        $this->assertTrue($this->build()->getDefinition('langsys.translator')->isPublic());
    }

    public function testPassesCredentialsToTheClient(): void
    {
        $args = $this->build()->getDefinition('langsys.client')->getArguments();

        $this->assertSame('test-key', $args[0]);
        $this->assertSame('test-project', $args[1]);
    }

    public function testDefaultsTheApiUrlThroughAnEnvFallbackParameter(): void
    {
        $builder = $this->build();

        $this->assertSame(
            'https://api.langsys.dev/api',
            $builder->getParameter('langsys.api_url_default')
        );
    }

    public function testRegistersBothSubscribersAsKernelEventSubscribers(): void
    {
        $builder = $this->build();

        $this->assertSame(
            LocaleSubscriber::class,
            $builder->getDefinition('langsys.locale_subscriber')->getClass()
        );
        $this->assertArrayHasKey(
            'kernel.event_subscriber',
            $builder->getDefinition('langsys.locale_subscriber')->getTags()
        );
        $this->assertSame(
            FlushPendingRegistrationsSubscriber::class,
            $builder->getDefinition('langsys.flush_subscriber')->getClass()
        );
        $this->assertArrayHasKey(
            'kernel.event_subscriber',
            $builder->getDefinition('langsys.flush_subscriber')->getTags()
        );
    }

    public function testWiresTheCacheAdapterOntoTheConfiguredPool(): void
    {
        $definition = $this->build()->getDefinition('langsys.cache_adapter');

        $this->assertSame(Psr6CacheAdapter::class, $definition->getClass());
        $this->assertSame('cache.app', (string) $definition->getArgument(0));
        $this->assertSame('langsys.', $definition->getArgument(1));
        $this->assertSame(3600, $definition->getArgument(2));
    }

    public function testHonorsACustomCachePool(): void
    {
        $definition = $this->build(['cache' => ['pool' => 'cache.langsys', 'prefix' => 'ls.', 'ttl' => 60]])
            ->getDefinition('langsys.cache_adapter');

        $this->assertSame('cache.langsys', (string) $definition->getArgument(0));
        $this->assertSame('ls.', $definition->getArgument(1));
        $this->assertSame(60, $definition->getArgument(2));
    }

    public function testDefaultsTheLocaleSourceChain(): void
    {
        $config = $this->build()->getDefinition('langsys.locale_subscriber')->getArgument(1);

        $this->assertSame(['query', 'cookie', 'session', 'header'], $config['sources']);
        $this->assertSame('locale', $config['query_param']);
        $this->assertSame('langsys_locale', $config['cookie']);
        $this->assertSame('cookie', $config['persist']);
        $this->assertSame([], $config['supported']);
    }

    public function testHonorsACustomLocaleSourceChain(): void
    {
        $config = $this->build(['locale' => ['sources' => ['header'], 'supported' => ['en-US']]])
            ->getDefinition('langsys.locale_subscriber')
            ->getArgument(1);

        $this->assertSame(['header'], $config['sources']);
        $this->assertSame(['en-US'], $config['supported']);
    }

    public function testAutoFlushDefaultsOn(): void
    {
        $this->assertTrue($this->build()->getDefinition('langsys.flush_subscriber')->getArgument(1));
    }

    public function testAutoFlushCanBeDisabled(): void
    {
        $this->assertFalse(
            $this->build(['auto_flush' => false])->getDefinition('langsys.flush_subscriber')->getArgument(1)
        );
    }

    public function testRegistersTheTwigExtensionWhenTwigIsInstalled(): void
    {
        $builder = $this->build();

        $this->assertTrue($builder->hasDefinition('langsys.twig_extension'));
        $this->assertArrayHasKey(
            'twig.extension',
            $builder->getDefinition('langsys.twig_extension')->getTags()
        );
    }
}
