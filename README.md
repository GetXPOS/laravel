# XPOS for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/getxpos/laravel.svg?style=flat-square)](https://packagist.org/packages/getxpos/laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/getxpos/laravel.svg?style=flat-square)](https://packagist.org/packages/getxpos/laravel)
[![License](https://img.shields.io/packagist/l/getxpos/laravel.svg?style=flat-square)](https://packagist.org/packages/getxpos/laravel)

Instant public URLs for Laravel development. Expose your local app with a single command — supports token auth, reserved subdomains, and custom domains.

**[xpos.dev](https://xpos.dev)**

## Features

- **One Command** — `php artisan xpos` gets you a public HTTPS URL instantly
- **Token Auth** — longer sessions, reserved subdomains, and custom domains with an XPOS token
- **Reserved Subdomains** — keep `myapp.xpos.to` across sessions (Pro plan)
- **Custom Domains** — use your own domain like `dev.example.com` (Business plan)
- **Auto-Start Server** — starts `php artisan serve` automatically if not running
- **Auto TrustProxies** — HTTPS URLs work correctly out of the box
- **Laravel 10, 11, 12** — full support

## Installation

```bash
composer require getxpos/laravel --dev
```

## Quick Start

```bash
# Anonymous tunnel (random subdomain, 3hr expiry)
php artisan xpos
```

That's it. The command starts your dev server (if needed), opens an SSH tunnel, and displays your public URL.

## Authentication

For longer sessions and more features, add your XPOS token to `.env`:

```env
XPOS_TOKEN=tk_your_token_here
```

Get your token at [xpos.dev/dashboard/tokens](https://xpos.dev/dashboard/tokens).

## Usage

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
```

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
- Laravel 10, 11, or 12
- SSH client in PATH

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
