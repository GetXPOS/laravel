# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.5] - 2026-06-25

### Fixed
- Catch a constructor `InvalidArgumentException` (bad `--host`/`--subdomain`/
  `--domain`) and clean up instead of crashing the command and leaking the
  `artisan serve` child + `.xpos.pid`.
- Stop the tunnel output buffer growing without bound — output is still
  forwarded to the `onOutput` callback, but the parse buffer no longer
  accumulates (and re-scans) for the tunnel's lifetime.
- Capture a tunnel's `Expires:` line even when it lands in a later read chunk
  than the URL, via a short bounded pre-resolve window before `start()` returns.
- Detect an `Error:` line split across a read-chunk boundary.
- Thread the requested `--host` through find-port / start / running-detection so
  a non-loopback `--host` is probed on the right interface, the bind host is
  persisted in `.xpos.pid`, the serving-status memo is keyed by host, and a live
  tracked serve on a different host is reported (not silently orphaned). Legacy
  PID files (no stored host) are treated as `127.0.0.1` for back-compat.
- Warn when an explicit `--port` is overridden by an already-running tracked
  serve instead of silently dropping it.

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
- README Security section rewritten to match the shipped design (token in a
  `0600` ssh_config `User` directive, not on the `ssh` argv).

### Security
- Re-derive and verify each SSH host key's SHA256 fingerprint, and reject
  whitespace/control chars in the key fields, before writing `known_hosts` —
  fail-closed against a tampered/buggy well-known response (matches the Go SDK).
- Require a successfully parsed 2xx status on the `.well-known/ssh-host-keys`
  fetch before trusting the body (treat an unparseable status as failure; keep
  the final status across redirect hops).

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
