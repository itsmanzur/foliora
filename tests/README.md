# Foliora tests

Dev-only. Excluded from the WordPress.org zip via `.distignore`.

## PHPUnit

```bash
composer install
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
composer phpunit
```

Covers `Foliora_Loader`, `Foliora_Viewer`, `Foliora_Thumbnails`, and `Foliora_Library`. Needs bash, Subversion, and a MySQL client (GitHub Actions provides these; Local on Windows typically does not).

## Playwright

Needs Docker (wp-env). Default admin is `admin` / `password`.

```bash
npm install
npx wp-env start
npx playwright install chromium
npm run test:e2e
```

## GitHub Actions

Push or pull request runs PHPCS, Plugin Check, PHPUnit (PHP 7.4 + 8.2), and Playwright.
