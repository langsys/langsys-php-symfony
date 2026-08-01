# Langsys Symfony SDK

Symfony integration for [Langsys](https://langsys.dev) — realtime continuous translations with automatic phrase discovery. Wraps the framework-agnostic [`langsys/php-sdk`](https://github.com/langsys/langsys-php), the same way [`langsys/laravel-sdk`](https://github.com/langsys/langsys-laravel) does for Laravel.

- `t()` Twig function and `|t` filter (auto-escaped, params interpolated)
- Autowirable `LangsysTranslator` service for controllers and services
- Locale detection from query / cookie / session / `Accept-Language`, with persistence
- Catalog cached through any PSR-6 pool (`cache.app` by default)
- Automatic phrase registration under a write key, flushed after the response (`kernel.terminate` — worker-runtime safe)
- ICU MessageFormat pluralization plus simple `{name}` interpolation, identical to the JS SDKs

## Requirements

- PHP 8.1+, Symfony 6.4 or 7.x
- `ext-intl` recommended (ICU plurals, locale-aware number/date formatting)

## Installation

`langsys/php-sdk` is installed from VCS for now:

```bash
composer config repositories.langsys-php vcs https://github.com/langsys/langsys-php
composer config repositories.langsys-symfony vcs https://github.com/langsys/langsys-symfony
composer require langsys/symfony-sdk:dev-main
```

Register the bundle (skip if Flex did it):

```php
// config/bundles.php
return [
    // ...
    Langsys\Symfony\LangsysBundle::class => ['all' => true],
];
```

Set your credentials:

```bash
# .env.local
LANGSYS_API_KEY=your-key        # WRITE key in dev (auto-registers phrases), READ-ONLY in prod
LANGSYS_PROJECT_ID=your-project-id
```

The key type is detected server-side — there is no local toggle.

## Usage

### Twig

```twig
<h1>{{ t('Welcome to our store', 'Home') }}</h1>
<p>{{ t('Hello, {name}!', 'Home', {name: app.user.name}) }}</p>
<button>{{ 'Save changes'|t('Buttons') }}</button>
```

The phrase is both the lookup key and the base-language default — unseen phrases render as-is (and are auto-registered under a write key). ICU works too:

```twig
{{ t('{count, plural, one {# item} other {# items}}', 'Cart', {count: count}) }}
```

### PHP

```php
use Langsys\Symfony\LangsysTranslator;

public function __construct(private readonly LangsysTranslator $langsys) {}

$subject = $this->langsys->translate('Your weekly digest', 'Emails');
```

`translate(phrase, category?, params?, locale?)` — locale defaults to the current request's locale.

## Configuration

Everything has defaults; override what you need:

```yaml
# config/packages/langsys.yaml
langsys:
    api_key: '%env(LANGSYS_API_KEY)%'
    project_id: '%env(LANGSYS_PROJECT_ID)%'
    api_url: 'https://api.langsys.dev/api'

    cache:
        pool: cache.app        # any PSR-6 pool service id
        prefix: 'langsys.'
        ttl: 3600

    locale:
        sources: [query, cookie, session, header]   # tried in order, first hit wins
        query_param: locale
        cookie: langsys_locale
        session_key: langsys_locale
        persist: cookie        # cookie | session | null — how ?locale=… choices stick
        supported: []          # empty = accept anything
        cookie_minutes: 525600

    auto_flush: true           # send discovered phrases after the response
```

## Locale detection

`LocaleSubscriber` runs on `kernel.request` (priority 10, after Symfony's own `LocaleListener`) and sets the resolved locale on both the `Request` and the Langsys client, canonicalized to BCP 47 (`es-ES`, `zh-Hant-TW`). A `?locale=…` choice is persisted per `locale.persist`. `{{ t(...) }}` calls need no locale argument after that.

## Phrase registration

Under a write key, phrases the templates encounter for the first time are queued during the request and flushed on `kernel.terminate` — after the response is sent, and reliably under FrankenPHP/RoadRunner/Swoole worker runtimes where the SDK's own shutdown handler never fires. Failures are logged, never thrown.

## Testing your app

The PHP SDK's HTTP client is not injectable, so don't stub HTTP — replace the seam:

```php
// In your test container: swap the translator (or decorate langsys.client)
$container->set('langsys.translator', new class extends LangsysTranslator { /* ... */ });
```

## Not included (yet)

- Whole-response HTML translation (`Client::translatePage()` as response middleware) — same open design questions as the Laravel wrapper's ROADMAP (double-translation vs `@t`, caching, opt-in scoping).
- Symfony Translation component bridge (`trans` interop).

## License

MIT
