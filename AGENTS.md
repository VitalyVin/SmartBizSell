# AGENTS.md

## Cursor Cloud specific instructions

SmartBizSell is a single PHP monolith (procedural PHP + PDO) served as flat `.php` files, backed by MySQL. There is no build step, no Composer, and no npm. The frontend (`styles.css`, `script.js`) is served statically by the same PHP process. See `README.md` and `INSTALL.md` for feature/DB details.

### Services

- **PHP dev server** (required): run from the repo root. `APP_ENV=development php -S 0.0.0.0:8000`. Entry point is `index.php`. The built-in server does not honor `.htaccess` rewrites, so pretty URLs (`/blog/{slug}`, `/business/{id}`) won't route — use the direct `*.php` paths (e.g. `register.php`, `dashboard.php`).
- **MySQL 8.0** (required): the app dies on DB failure. Database name `u3064951_SmartBizSell`.
- Together.ai / Alibaba DashScope (optional): only needed to exercise AI teaser / term-sheet generation. Set `TOGETHER_API_KEY` / `ALIBABA_API_KEY` env vars. Core app works without them.
- SMTP (optional): welcome / password-reset email. Absent SMTP just logs "Failed to send" and continues.

### Startup (each session, after the update script runs)

MySQL is not auto-started and its socket directory comes up locked-down. Run:

```
sudo service mysql start
sudo chmod 755 /var/run/mysqld   # let non-root (ubuntu) reach mysqld.sock; re-run after any mysql restart
```

Then start the dev server (see above).

### Database credentials (non-obvious)

`config.php` is committed to the repo (despite being listed in `.gitignore` it was force-added and is tracked) and hard-codes DB creds that connect via the local UNIX socket as user `u3064951_default` / db `u3064951_SmartBizSell`. The local MySQL is provisioned with a matching user and database so the committed `config.php` works unmodified — do NOT rewrite `config.php` for local dev. If the DB is ever recreated, re-import `db/install.sql` then the migrations listed in `db/README.md` (order matters).

### Tests

The test suite is a browser/HTTP-driven runner, not PHPUnit. It requires a local, git-ignored `tests/config.php` (defines `getTestDBConnection()`, `TEST_BASE_URL`, and `TEST_*` fixture constants). This file is created during environment setup; if it is missing, recreate it (it just wires the test DB connection to the same DB constants and defines fixtures like `TEST_USER_EMAIL`).

- Run all tests: `curl "http://localhost:8000/tests/index.php?action=run_all"` (returns JSON).
- Run one class: `curl "http://localhost:8000/tests/index.php?action=run&class=AuthTest"`.

Known-fragile behavior (test-harness design, not an environment problem): a few tests use `executeLocalFile()` to `include` auth-gated pages (e.g. `seller_form.php`, `dashboard.php`). Those pages call `header('Location: .../login.php'); exit;` when there is no session, which terminates the whole runner request — so `run_all` can stop early with a 302 and `FormTest`/`DashboardTest` return no JSON. Tests that hit AI endpoints fail without API keys, and tests isolate via a transaction on a second PDO connection that the app's own connection can't see, so a couple of insert-then-select assertions fail by design. Do not "fix" these by editing app code.

### Lint

There is no configured linter (the GitHub Actions workflow is disabled). Use PHP's syntax checker across the tree: `find . -name '*.php' -not -path './venv/*' -exec php -l {} \;`.
