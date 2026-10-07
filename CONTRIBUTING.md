# Contributing to Ferrite

Thanks for looking. Ferrite is a small, opinionated project: self-hosted, web-only file storage for one person or a small team. Read the README and `PLAN.md` first; they say what is in scope and what is deliberately not (sync clients, WebDAV, office previews, real-time collaboration).

## Before you start

- **Bugs and small fixes:** open a pull request directly.
- **Features:** open an issue first and describe the problem you want solved. Something outside the scope in `PLAN.md` will probably be declined, and it is kinder to find that out before you write the code.
- **Security problems:** do not open a public issue. See [SECURITY.md](SECURITY.md).

## Setting up

```sh
git clone <your fork> ferrite && cd ferrite
composer setup            # installs dependencies, creates .env and the key, migrates, builds assets
composer dev              # app, queue workers and Vite together
```

PHP 8.3 or newer (the author runs 8.5), Composer and Node 22. SQLite is the default, so nothing else is needed. On a fresh database the first visit shows a page to create the admin account.

## Checks

Run these before pushing; CI runs the same:

```sh
php artisan test --compact          # Pest; tests use in-memory SQLite
vendor/bin/pint                     # code style (fixes in place)
vendor/bin/phpstan analyse          # static analysis
```

While working, run just the tests you touched (`php artisan test --compact tests/Feature/Uploads`).

## Guidelines

- **Tests:** a change in behaviour comes with a test. Files are a security surface, so anything that touches how user files are stored or served needs one even for a small fix (see `docs/serving-files.md` and `docs/security.md`).
- **Write like the surrounding code:** same naming, comment density and idiom. Actions live in `app/Actions`, one class per use case.
- **Keep the plan current:** if your change finishes or adds an item, update the checkboxes in `PLAN.md`; put detail in `docs/`, not in the plan.
- **UI:** it uses Flux UI and Tailwind, and has thirteen themes (`docs/themes.md`). Check a change in the default theme and at least one light and one retro one; do not hard-code colours.
- **Dependencies:** adding one needs a reason in the pull request. Prefer what is already in `composer.json`.
- **Commits:** a short imperative summary line saying what changed and why; no need for a particular format.

## Licence

Ferrite is under the [AGPL-3.0-or-later](LICENSE). By contributing you agree that your contribution is licensed under the same terms.
