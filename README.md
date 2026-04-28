# XPOS for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/getxpos/laravel.svg?style=flat-square)](https://packagist.org/packages/getxpos/laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/getxpos/laravel.svg?style=flat-square)](https://packagist.org/packages/getxpos/laravel)
[![License](https://img.shields.io/packagist/l/getxpos/laravel.svg?style=flat-square)](https://packagist.org/packages/getxpos/laravel)

Instant public URLs for Laravel development. Expose your local app with a single command or programmatically from code — supports token auth, reserved subdomains, custom domains, and TCP tunnels.

**[xpos.dev](https://xpos.dev)**

## Features

- **One Command** — `php artisan xpos` gets you a public HTTPS URL instantly
- **Programmatic API** — `Xpos::connect(['port' => 8000])` for webhook testing and automated workflows
- **TCP Tunnels** — expose databases, Redis, or any TCP service
- **Token Auth** — longer sessions, reserved subdomains, and custom domains with an XPOS token
- **Reserved Subdomains** — keep `myapp.xpos.to` across sessions (Pro plan)
- **Custom Domains** — use your own domain like `dev.example.com` (Business plan)
- **Auto-Start Server** — starts `php artisan serve` automatically if not running
- **Auto TrustProxies** — HTTPS URLs work correctly out of the box
- **Laravel 10+** — full support

## Installation

```bash
composer require getxpos/laravel --dev
```

## Quick Start

**CLI:**

```bash
php artisan xpos
```

**Programmatic:**

```php
use GetXPOS\Laravel\Facades\Xpos;

$tunnel = Xpos::connect(['port' => 8000]);
echo $tunnel->url; // https://abc123.xpos.to
$tunnel->close();
```

## Authentication

For longer sessions and more features, add your XPOS token to `.env`:

```env
XPOS_TOKEN=tk_your_token_here
```

Get your token at [xpos.dev/dashboard/tokens](https://xpos.dev/dashboard/tokens).

## CLI Usage

```bash
# Anonymous tunnel (random subdomain, 3hr expiry)
php artisan xpos

# Authenticated tunnel (uses XPOS_TOKEN from .env)
php artisan xpos

# Reserved subdomain (Pro plan)
php artisan xpos --subdomain=myapp

# Custom domain (Business plan)
php artisan xpos --domain=dev.example.com

# Override token from command line
php artisan xpos --token=tk_your_token

# Use a specific port
php artisan xpos --port=8080

# Use an existing server (skip starting artisan serve)
php artisan xpos --no-serve --port=3000

# Bind to a specific host
php artisan xpos --host=0.0.0.0

# TCP tunnel (database, Redis, etc.)
php artisan xpos --mode=tcp --port=5432 --token=tk_xxx
```

## Programmatic API

Create tunnels from your PHP code — useful for webhook testing, integration tests, and automated workflows.

### Basic Usage

```php
use GetXPOS\Laravel\Facades\Xpos;

$tunnel = Xpos::connect(['port' => 8000]);

echo $tunnel->url;       // https://abc123.xpos.to
echo $tunnel->expiresAt; // 2026-03-28T10:30:45Z

$tunnel->close();
```

### With Options

```php
$tunnel = Xpos::connect([
    'port' => 8000,
    'token' => 'tk_your_token',
    'subdomain' => 'myapp',
]);
// https://myapp.xpos.to
```

### Without Facade

```php
use GetXPOS\Laravel\XposTunnel;

$tunnel = XposTunnel::connect(['port' => 8000]);
echo $tunnel->url;
$tunnel->close();
```

### Webhook Testing

```php
use GetXPOS\Laravel\Facades\Xpos;

// In your test setup
$tunnel = Xpos::connect(['port' => 8000, 'token' => 'tk_xxx']);

// Configure Stripe to send webhooks to your tunnel
$stripe = new \Stripe\StripeClient('sk_test_xxx');
$stripe->webhookEndpoints->create([
    'url' => $tunnel->url . '/api/webhooks/stripe',
    'enabled_events' => ['payment_intent.succeeded'],
]);

// Run your tests...

$tunnel->close();
```

### Callbacks

```php
use GetXPOS\Laravel\XposTunnel;

$tunnel = new XposTunnel(['port' => 8000]);

$tunnel->onConnect(function (array $info) {
    echo "Connected: {$info['url']}\n";
    // $info['expiresAt'] may be null for paid plans
});

$tunnel->onClose(function (?int $exitCode) {
    echo "Tunnel closed (exit: {$exitCode})\n";
});

$tunnel->onOutput(function (string $data) {
    // Raw SSH output
});

$tunnel->start();

// Block until process exits
$tunnel->wait();
```

> **Note:** `onClose` fires during `wait()` or `close()` — PHP runs synchronously, so there is no background event loop.

## TCP Tunnels

Expose any TCP service (databases, Redis, message queues, etc.).

### CLI

```bash
# Expose PostgreSQL
php artisan xpos --mode=tcp --port=5432 --token=tk_xxx

# Expose Redis
php artisan xpos --mode=tcp --port=6379 --token=tk_xxx

# Expose MySQL
php artisan xpos --mode=tcp --port=3306 --token=tk_xxx
```

### Programmatic

```php
use GetXPOS\Laravel\Facades\Xpos;

$tunnel = Xpos::connect([
    'mode' => 'tcp',
    'port' => 5432,
    'token' => 'tk_xxx',
]);

echo $tunnel->url; // myapp.xpos.to:34567

$tunnel->close();
```

> **Note:** TCP tunnels require authentication (a token). The tunnel URL is an `ip:port` pair instead of an HTTPS URL.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=xpos-config
```

Options in `config/xpos.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `token` | `env('XPOS_TOKEN')` | Auth token from xpos.dev dashboard |
| `server` | `go.xpos.dev` | XPOS tunnel server |
| `ssh_port` | `443` | SSH port |
| `default_port` | `8000` | Default dev server port |
| `trust_proxies` | `true` | Auto-configure TrustProxies for HTTPS |

## HTTPS & TrustProxies

The package automatically configures Laravel's TrustProxies middleware so `asset()`, `url()`, `route()`, and other helpers generate correct HTTPS URLs when accessed through an XPOS tunnel.

This works with `*.xpos.to` subdomains, custom domains, and any future tunnel domains. Disable with `XPOS_TRUST_PROXIES=false` in `.env` if needed.

## Requirements

- PHP 8.1+
- Laravel 10+
- SSH client in PATH

## Security

The auth token is passed as the SSH username (`<token>@go.xpos.dev`), so it
briefly appears in the local `ssh` process's argv. On a shared or audited
host other local users may be able to read it via `ps`,
`/proc/<pid>/cmdline`, or system audit logs.

To minimize exposure:

- **Set `XPOS_TOKEN` in `.env`** rather than passing `--token=tk_xxx` on
  the command line. The token still ends up in the spawned `ssh` argv,
  but it stays out of shell history, source control, and CI logs.
- **Rotate tokens regularly** and revoke any token you suspect was
  exposed — manage tokens at [xpos.dev/dashboard/tokens](https://xpos.dev/dashboard/tokens).
- **Avoid running on shared multi-user hosts** where untrusted local
  users can inspect process state.

A protocol change to remove argv exposure is on the roadmap.

## Troubleshooting

**Port already in use?**
```bash
php artisan xpos --port=8001
```

**SSH connection issues?**
- Ensure SSH client is installed and in PATH
- Check firewall settings for port 443

**HTTPS URLs not working?**
- Ensure `trust_proxies` is `true` in `config/xpos.php`
- Clear config cache: `php artisan config:clear`

**Reserved subdomain not working?**
- Ensure `XPOS_TOKEN` is set in `.env`
- Verify your plan supports reserved subdomains at [xpos.dev](https://xpos.dev)

**TCP mode requires --port?**
- TCP mode doesn't auto-start `artisan serve` — you must specify the port of the service you're exposing: `--mode=tcp --port=5432`

**TCP tunnel shows "authentication required"?**
- TCP tunnels require a token. Set `XPOS_TOKEN` in `.env` or pass `--token=tk_xxx`

## .gitignore

Add `.xpos.pid` to your project's `.gitignore`:

```
.xpos.pid
```

## Links

- **Website**: [xpos.dev](https://xpos.dev)
- **Dashboard**: [xpos.dev/dashboard](https://xpos.dev/dashboard)
- **GitHub**: [github.com/getxpos/laravel](https://github.com/getxpos/laravel)

## License

MIT License. See [LICENSE](LICENSE) for details.
