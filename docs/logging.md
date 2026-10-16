# Backend logging

Application logs use Laravel 13 daily rotation and Monolog 3.10. `LOG_CHANNEL=daily` writes `storage/logs/laravel-YYYY-MM-DD.log`; `LOG_LEVEL=debug` and `LOG_DAILY_DAYS=14` are development defaults. Set `LOG_LEVEL=warning` in production deployment configuration. Set `APP_DEBUG=false` there as well so HTTP error responses do not expose diagnostic details. A configured `stack` defaults to `daily`; the legacy `single` channel remains available only when selected explicitly.

| Channel | Purpose | Level | File retention |
| --- | --- | --- | --- |
| `daily` | Application and reported exceptions | `LOG_LEVEL` (`debug` locally, `warning` in production) | `LOG_DAILY_DAYS` (14 days) |
| `auth` | Authentication and account data audit events | `AUTH_LOG_LEVEL` (`info`) | `AUTH_LOG_DAILY_DAYS` (30 days) |

`AUTH_LOG_CHANNEL=auth` selects the audit destination. `AUTH_SECURITY_LOG_RETENTION_DAYS=365` is a separate security setting; it no longer controls audit **file** retention. Existing framework channels (`stack`, `single`, `monthly`, `stderr`, `syslog`, `errorlog`, `slack`, `papertrail`, `null`, `emergency`) remain available. There are no dedicated financial transaction, scheduler, or critical-error channels. Reported failures use the application channel. Audit contexts contain identifiers, not credentials or financial payloads.

PHPUnit uses `LOG_CHANNEL="null"` by default to avoid saving expected test exceptions. Override that environment variable with `daily` when diagnosing a test. Developers using the current local checkout should set `LOG_CHANNEL=daily` in their ignored `.env` and clear cached configuration after changing environment values: `php artisan config:clear`.

## Inspect logs

Run these from the backend repository root:

```bash
du -ah storage/logs | sort -h | tail -20
ls -lh storage/logs/laravel-*.log storage/logs/authentication-*.log
tail -n 100 storage/logs/laravel-*.log
```

For a legacy file, use `tail -n 100 storage/logs/laravel.log`. To inspect container stdout/stderr, run `docker compose -f docker/docker-compose.yml logs --tail=100 app` from the backend root. The service is `app` in this Compose file; filenames and service names can differ in other deployments. The same command can target `scheduler` or `auth-mail-worker`.

Docker Compose sets the `local` logging driver on these three PHP services with 10 MB per file and three files per container. This controls container stdout/stderr only. Laravel writes under the bind-mounted `storage/logs/` directory and applies its own daily file retention there. Monolog limits the number of dated files and prunes them when rotation runs; an idle channel may keep an old file longer than the named day count. Compose logging changes apply when containers are recreated. Avoid adding `stderr` to `LOG_STACK` unless duplicate output is intentional.

## Legacy file cleanup

Daily retention manages dated files created by that daily channel. It does **not** remove `laravel.log` created by `single`, or old authentication files outside its own rotation history. After confirming that `LOG_CHANNEL=daily` is active and `laravel.log` is no longer receiving entries, back up or archive it according to your retention needs. Inspect its size and modification time with `ls -lh storage/logs/laravel.log` and `stat storage/logs/laravel.log`; remove it manually only after confirming the archive and that no writer still uses `single`. This change does not delete or truncate existing logs.
