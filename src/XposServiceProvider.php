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

        // Auto-configure TrustProxies for HTTPS
        if (config('xpos.trust_proxies', true)) {
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
            // Laravel 11+ has TrustProxies::at() method
            if (method_exists(\Illuminate\Http\Middleware\TrustProxies::class, 'at')) {
                \Illuminate\Http\Middleware\TrustProxies::at('*');
            } else {
                // Laravel 10 fallback: use Request::setTrustedProxies()
                request()->setTrustedProxies(
                    ['*'],
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
                );
            }
        } catch (\Throwable) {
            // Silently fail if request not available
        }
    }
}
