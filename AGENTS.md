# SiMarsel V2 agent guide

## Project and sources of truth

SiMarsel V2 is a Laravel monolith for dashboards, promo management, task management, and operational workflows. Use the existing application structure instead of introducing a new frontend or service architecture.

Before implementing a ticket, read its acceptance criteria, parent ticket when relevant, and the affected code. Treat approved tickets and designs as requirements, and the repository as the current implementation. If they disagree, identify the conflict and its impact instead of silently changing a business rule. Follow the user's current instructions and scope.

## Stack and layout

- PHP `^8.3.36`, Laravel `13.33.0`, Eloquent, MySQL for the default application connection.
- Blade, Alpine.js, Tailwind CSS 4, Chart.js, Vite, and native `fetch()`.
- PHPUnit Feature/Unit tests use SQLite in-memory and a synchronous queue through `phpunit.xml`. Frontend tests use Node's test runner.
- HTTP controllers, Form Requests, API Resources, Policies, Services, Repositories, Models, and Observers live under `app/`.
- Routes are in `routes/`, Blade views in `resources/views/`, page JavaScript in `resources/js/pages/`, schema in `database/migrations/`, and tests in `tests/`.
- `vite.config.js` declares both shared assets and page-specific entrypoints. Keep a page's Blade `@vite` call aligned with its Vite entry.

## Work in this repository

1. Check the branch, working tree, and relevant diff. Preserve local changes you did not make.
2. Trace the affected flow through routes, validation, service, persistence, response, and frontend as needed. Search for an existing implementation before adding one.
3. Make the smallest change that satisfies the request. Do not bundle unrelated cleanup into a feature or fix.
4. Start a new feature branch from current `dev` unless its ticket genuinely depends on unmerged work. If a branch is stacked, state the dependency and keep its PR diff reviewable after the parent merges.
5. Verify behavior with focused tests, then run broader checks only when the change warrants them. Report what was tested and what remains unverified.

### Backend

- Controllers handle HTTP input and output. Form Requests validate input. Services own workflows and business rules. Models define relationships, casts, and simple scopes. Use Repositories for complex or shared data queries, not for every CRUD operation.
- Prefer Eloquent or the query builder. Use explicit SQL when aggregation or performance requires it, with parameters and tests.
- Use migrations for schema changes. Keep migrations, models, factories, and tests aligned. Do not run destructive database reset commands against non-disposable data.
- Enforce authorization on the server, not only by hiding UI controls. Return errors appropriate to HTML or JSON requests. Keep technical details in logs, not user responses.
- Do not hide infrastructure failures behind a success response or an unrelated business error. Log enough context to diagnose a failure without logging credentials or customer personal data.

### Frontend

- Keep the existing Blade, Alpine.js, Tailwind, and Vite approach. Register nontrivial Alpine behavior in `resources/js/` rather than large inline expressions.
- Use the existing page entrypoints and shared helpers. Do not assume all JavaScript is loaded through `resources/js/app.js`.
- Client-side validation can improve the form experience, but the server remains authoritative. For non-GET requests, preserve CSRF handling and show validation and API errors clearly.
- Do not add a new frontend framework or duplicate a component that can be reused.

### Markaz integration

For transaction or downloader sync, reconciliation, backfill, snapshots, or a related bug, read [the Markaz development skill](.agents/skills/simarsel-markaz-sync/SKILL.md), the latest approved design, and `docs/transaction-dates-coverage.md`. The current transaction schema may lag the approved contract. Verify identity keys, status handling, nullable payment dates, and promo normalization before implementing writes. Do not run a live import merely to test new code.

## Verification

Use the checks relevant to the change:

```bash
php artisan test --filter=RelevantTest
php artisan test
npm run test:frontend
npm run build
```

SQLite in-memory tests do not establish MySQL-specific constraints, worker concurrency, rate limiting, or production capacity. Validate those separately in a safe integration environment when the change depends on them. Avoid tests that only repeat implementation details rather than proving behavior.

## PR workflow

For a SiMarsel PR review or preparation request, use the [SiMarsel PR skill](.agents/skills/simarsel-pr-review/SKILL.md). If the skill is unavailable in the agent, read its shared source in `.claude/skills/simarsel-pr-review/SKILL.md`.

Check that the PR targets the intended branch and contains only its own work. Report concrete findings with their impact and evidence. Follow the user's current authorization for pushing, publishing comments, opening PRs, or merging.
