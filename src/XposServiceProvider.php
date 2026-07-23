<?php

namespace GetXPOS\Laravel;

use GetXPOS\Laravel\Commands\XposCommand;
use Illuminate\Support\ServiceProvider;

class XposServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/xpos.php', 'xpos');

        $this->app->singleton('xpos', function () {
            return new class {
                public function connect(array $options = []): \GetXPOS\Laravel\XposTunnel
                {
                    return \GetXPOS\Laravel\XposTunnel::connect($options);
                }
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/xpos.php' => config_path('xpos.php'),
            ], 'xpos-config');

            $this->commands([
                XposCommand::class,
            ]);
        }

        // Auto-configure TrustProxies for HTTPS. Default FALSE (explicit opt-in):
        // configureTrustProxies() calls TrustProxies::at(), which REPLACES the
        // host app's trusted-proxy list, and boot() runs every web request — a
        // default-on package would clobber the application's own proxy config.
        if (config('xpos.trust_proxies', false)) {
            $this->configureTrustProxies();
        }
    }

    /**
     * Configure TrustProxies to trust XPOS proxy.
     * Applies unconditionally when enabled — covers *.xpos.to, custom domains,
     * and future tunnel domains (xpos.link, xpos.sh, etc.).
     */
    protected function configureTrustProxies(): void
    {
        // Only configure if we have a request (not in console)
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            $trustedIPs = ['127.0.0.1', '::1'];

            // Laravel 11+ has TrustProxies::at() method
            if (method_exists(\Illuminate\Http\Middleware\TrustProxies::class, 'at')) {
                \Illuminate\Http\Middleware\TrustProxies::at($trustedIPs);
            } else {
                // Laravel 10 fallback: use Request::setTrustedProxies()
                request()->setTrustedProxies(
                    $trustedIPs,
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
                );
            }
        } catch (\Throwable $e) {
            logger()->warning('XPOS: failed to configure TrustProxies', ['error' => $e->getMessage()]);
        }
    }
}
