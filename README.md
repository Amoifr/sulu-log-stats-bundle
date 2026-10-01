# Sulu Log Stats Bundle

A [Sulu](https://sulu.io) 3 bundle that reads your web server access logs, keeps **aggregates** of them in the
database, and shows traffic, status code and response time figures on a **Statistics** page of the Sulu admin.

Only totals are stored (per hour, per day, per path), never the log lines: the trends stay available long after
the logs themselves have been rotated away, and no personal data is kept.

What the dashboard shows, over any period of days:

- page views and unique visitors per day, and page views by hour of the day;
- requests by status class (2xx, 3xx, 4xx, 5xx), and the share of bots;
- average and 95th percentile response times, when the logs carry durations;
- the most viewed pages, the most frequent 404s and the pages with the most server errors;
- a table of the daily figures.

## Requirements

- PHP 8.2 or later, Sulu 3.0, Symfony 7.1 or later, Doctrine ORM 3;
- access logs readable by the user running the import command (see [Log file permissions](#log-file-permissions)).

## Installation

### 1. Install the package

The bundle is not on Packagist yet: declare its repository first.

```bash
composer config repositories.sulu-log-stats-bundle vcs https://github.com/Amoifr/sulu-log-stats-bundle
composer require amoifr/sulu-log-stats-bundle:dev-main
```

Symfony Flex registers `Amoifr\SuluLogStatsBundle\SuluLogStatsBundle` in `config/bundles.php`. Without Flex, add it
for all environments.

### 2. Configure the log sources

```yaml
# config/packages/amoifr_log_stats.yaml
amoifr_log_stats:
    # calendar days of the statistics: set it before the first import, stored days are not shifted
    timezone: Europe/Paris
    sources:
        # any name: it identifies the file in the database
        access:
            path: /var/log/nginx/access.log
            format: combined
```

See [Log formats](#log-formats) and the [server recipes](#server-recipes) to pick the format.

### 3. Import the admin API route

```yaml
# config/routes/amoifr_log_stats.yaml
amoifr_log_stats:
    resource: '@SuluLogStatsBundle/config/routes/admin_api.yaml'
    prefix: /admin/api
```

### 4. Create the tables

The tables are prefixed with `als_`. With Doctrine Migrations:

```bash
bin/adminconsole doctrine:migrations:diff
bin/adminconsole doctrine:migrations:migrate
```

### 5. Add the dashboard to the admin build

Declare the bundle JavaScript in `assets/admin/package.json`:

```json
"dependencies": {
    "sulu-log-stats-bundle": "file:../../vendor/amoifr/sulu-log-stats-bundle/assets/js"
}
```

Import it in `assets/admin/app.js`:

```js
import 'sulu-log-stats-bundle';
```

Then rebuild the admin: `bin/adminconsole sulu:admin:update-build`, or `npm install && npm run build` in
`assets/admin`.

> Keep the package name `sulu-log-stats-bundle`: the Sulu admin build only compiles the packages of
> `node_modules` named `sulu-…-bundle`.

### 6. Give access to the dashboard

The **Statistics** entry appears for the roles holding the **View** permission on **Log statistics**, under
*Settings* in the role permissions.

### 7. Import the logs regularly

```bash
bin/adminconsole amoifr:log-stats:import
```

Run it from cron every 5 to 15 minutes, as a user who can read the logs:

```cron
*/10 * * * * www-data cd /var/www/project && php bin/adminconsole amoifr:log-stats:import --quiet
```

Each run only reads what the logs gained since the previous one. A lock prevents two runs from overlapping.

## Log formats

| `format` | Log | Durations |
| --- | --- | --- |
| `combined` | the default access log of nginx and Apache | no |
| `nginx_timed` | `combined` followed by nginx `$request_time` (seconds) as the last field | yes |
| `apache_timed` | `combined` followed by Apache `%D` (microseconds) as the last field | yes |
| `upsun_php_access` | the PHP access log of an Upsun application, see [Upsun](#upsun-connector) | yes |

Fields appended after the user agent are ignored by `combined`. With the timed formats, the duration must be the
**last** field of the line.

## Server recipes

### nginx

`combined` works out of the box. For response times, log `$request_time` at the end of the line:

```nginx
log_format timed '$remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent '
                 '"$http_referer" "$http_user_agent" $request_time';
access_log /var/log/nginx/access.log timed;
```

and use `format: nginx_timed`.

### Apache

`combined` works out of the box. For response times, log `%D` at the end of the line:

```apache
LogFormat "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\" %D" timed
CustomLog ${APACHE_LOG_DIR}/access.log timed
```

and use `format: apache_timed`.

### Log rotation

The import keeps up with logrotate:

- **rename** (the default): when `access.log` was renamed `access.log.1` since the previous run, the lines left
  unread in `access.log.1` are read first, whether it is plain (`delaycompress`) or already gzipped
  (`access.log.1.gz`), then the new `access.log` from its start;
- **copytruncate**, or a host trimming its logs in place: the import finds the line it read last and resumes
  right after it.

A rotation happening more than once between two runs can lose lines: run the import more often than the logs
rotate.

### Log file permissions

On Debian and Ubuntu, `/var/log/nginx/access.log` belongs to `www-data:adm` with mode `0640`, and
`/var/log/apache2/access.log` to `root:adm`. Run the import as a user of the `adm` group, or adjust the `create`
line of the logrotate configuration.

### Behind a reverse proxy or a load balancer

Unique visitors are told apart by client address. Behind a proxy, the logged address is the proxy's unless the
server logs the real client address (`set_real_ip_from` and `real_ip_header` in nginx, `mod_remoteip` in Apache).

## Upsun connector

On [Upsun](https://upsun.com), each application container writes its nginx access log to `/var/log/access.log`
and its PHP access log, with durations, to `/var/log/php.access.log`. Read both: the access log feeds traffic,
pages and visitors, the PHP log feeds response times, so no request is counted twice.

Check the format of your access log first, with `upsun log access --lines 3`: the configuration below assumes
the `combined` format.

```yaml
amoifr_log_stats:
    timezone: Europe/Paris
    sources:
        access:
            path: /var/log/access.log
            format: combined
        php:
            path: /var/log/php.access.log
            format: upsun_php_access
```

Schedule the import in `.upsun/config.yaml`:

```yaml
applications:
    app:
        crons:
            log-stats:
                spec: '*/10 * * * *'
                commands:
                    start: 'php bin/adminconsole amoifr:log-stats:import --quiet'
```

Upsun trims its logs to 100 MB, which the import handles. Requests served by the Upsun router cache never reach
the container, so they are not in its logs.

## How the figures are counted

- **Page view**: a successful (200) GET of a page path by a human visitor. The admin, `/_profiler`, `/_wdt`,
  `/_fragment`, `/build`, `/bundles`, `/uploads` and `/media`, and files with an asset extension (`css`, `js`,
  images, fonts…) are not pages. Prefixes match whole path segments: `/admin` leaves `/administration` counted.
- **Bot**: a request without a user agent, or whose user agent matches `bot_user_agent_pattern`. The default
  pattern also catches cache warmers (`warm`), which would otherwise count every warmed page as a visit after
  each deployment.
- **Unique visitors**: per day, a hash of the client address and user agent, salted with a random value drawn
  for that day. Two hours after midnight, the day is closed: its salt and hashes are deleted and only the count
  remains. Over a period, the dashboard adds up the daily counts: someone coming back another day counts again.
- **Pages, 404s, 5xx**: per day and path, without the bots, so that scanners don't fill the table with made up
  paths.
- **Response times**: per day, as a histogram, from which the 95th percentile of any period is computed as the
  upper bound of the bucket holding it. With a timed access log, only page requests count: static files served in
  a few milliseconds would hide how fast the pages are.
- **Days and hours**: traffic is kept per UTC hour and shown in the configured time zone; the daily figures
  follow the calendar days of the configured time zone.

## Configuration reference

```yaml
amoifr_log_stats:
    sources: {}                    # name => {path, format}
    timezone: UTC
    page_views:
        excluded_path_prefixes: ['/admin', '/_profiler', '/_wdt', '/_fragment', '/build', '/bundles', '/uploads', '/media']
        excluded_extensions: [css, js, map, json, xml, txt, ico, png, jpg, jpeg, gif, svg, webp, avif, woff, woff2, ttf, eot, pdf]
    bot_user_agent_pattern: '~bot|crawl|spider|slurp|curl|wget|python|httpclient|headless|lighthouse|monitor|uptime|warm~i'
```

## License

MIT, see [LICENSE](LICENSE).
