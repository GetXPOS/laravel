# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- SSH host-key pinning from the `xpos.dev` `.well-known/ssh-host-keys` endpoint.

### Changed
- Keep auth token out of the SSH argv (write to a per-process `ssh_config` instead).
- Reject control characters and whitespace in `ssh_config` values.
- Validate subdomain/domain as DNS labels client-side.
- Warn when host-key pinning is disabled for a custom server.
- `chmod 0600` on `.xpos.pid` to protect against world-readable PIDs.
- Validate port range (1-65535) in the tunnel constructor.
- Require token for TCP mode (validated at construction time).
- Default tunnel host to `127.0.0.1` instead of `localhost` to avoid IPv4/IPv6 mismatch.
- Clean up `known_hosts` temp dir when `start()` detects a dead process.

## [0.2.4]

### Changed
- Hardened SDK: validation parity with Python/Node, state reset on restart, TCP output filter.

## [0.2.3]

### Fixed
- SSH host-key checking.
- Restrict proxy trust to `localhost`.

## [0.2.2]

### Fixed
- SSH auth failure on Windows by removing `BatchMode=yes`.

## [0.2.1]

### Changed
- Support Laravel 10+ with no upper version cap.

## [0.2.0]

### Added
- Programmatic API.
- TCP tunnels.

### Fixed
- Output filtering.

## [0.1.0]

### Added
- Initial release of the Laravel SDK for XPOS.
