<?php

declare(strict_types=1);

namespace Beziworld\Autenti;

use Beziworld\Autenti\Console\CallbacksCommand;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Registers the Autenti connection manager, the client of the default connection and the callback route.
 */
final class AutentiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/autenti.php', 'autenti');

        $this->app->singleton(AutentiManager::class, static function (Application $app): AutentiManager {
            $config = $app->make(Config::class);

            return new AutentiManager(
                $app->make(HttpFactory::class),
                $config,
                $app->make(CacheFactory::class)->store(),
                static function (AutentiConfig $autenti) use ($app, $config): ?string {
                    $route = (string) $config->get('autenti.callbacks.name', 'autenti.callback');

                    if ($autenti->name === null || ! (bool) $config->get('autenti.callbacks.enabled', true)) {
                        return null;
                    }

                    try {
                        return $app->make(UrlGenerator::class)->route($route, ['connection' => $autenti->name]);
                    } catch (RouteNotFoundException) {
                        return null;
                    }
                },
            );
        });

        // The client and configuration of the default connection.
        $this->app->bind(AutentiClient::class, static fn(Application $app): AutentiClient => $app->make(AutentiManager::class)->connection());
        $this->app->bind(AutentiConfig::class, static fn(Application $app): AutentiConfig => $app->make(AutentiClient::class)->config());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/autenti.php' => $this->app->configPath('autenti.php')], 'autenti-config');
            $this->commands([CallbacksCommand::class]);
        }

        if ((bool) $this->app->make(Config::class)->get('autenti.callbacks.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/autenti.php');
        }
    }
}
