# PromptEdit Assessment

A Laravel, Vue, and Inertia application for the PromptEdit product engineering assessment.

Checkpoint 1 provides password-based authentication, a protected dashboard, and PostgreSQL. Image generation, pricing, webhooks, and deployment are later checkpoints.

## Local setup

Requirements: PHP 8.5 with `pdo_pgsql`, `mbstring`, and `bcmath`; Composer 2; Node.js 24; npm; and Docker Compose. The app uses Laravel 13, Vue 3, Inertia 3, TypeScript, Tailwind CSS 4, and Pest 5.

From the project directory:

```bash
cp .env.example .env
composer install
php artisan key:generate
docker compose up -d --wait
php artisan migrate
npm ci
npm run build
composer run dev
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000) and register an account. New accounts can access the dashboard immediately. Password reset emails are written to `storage/logs/laravel.log` locally.

The initial setup above is already complete in this workspace. For subsequent sessions, run:

```bash
docker compose up -d --wait
composer run dev
```

Stop the development processes with Ctrl+C. `docker compose stop` stops PostgreSQL while preserving its data.

### Development processes

`composer run dev` runs Laravel's development process manager:

| Process             | Responsibility                                                                            |
| ------------------- | ----------------------------------------------------------------------------------------- |
| Laravel HTTP server | Handles browser requests, authentication, validation, and Inertia responses on port 8000. |
| Vite                | Serves Vue/CSS assets and reloads changed frontend code during development.               |
| Queue listener      | Processes database-backed jobs. There are no application jobs in this checkpoint.         |
| Pail                | Streams application logs into the terminal.                                               |

`npm run build` produces static assets for serving without Vite. It does not start the application or a queue worker.

### Databases

The Compose service runs PostgreSQL 17, bound to `127.0.0.1:5433`. Its development-only credentials are `promptedit` / `promptedit-local`.

| Database                        | Purpose                                                                |
| ------------------------------- | ---------------------------------------------------------------------- |
| `promptedit_assessment`         | Browser accounts, sessions, cache, and queued jobs during development. |
| `promptedit_assessment_testing` | Automated tests, using the same PostgreSQL engine.                     |

`docker/postgres/init-testing.sql` creates the test database when the volume is first initialized. `phpunit.xml` explicitly selects that database, and feature tests use `RefreshDatabase`. Tests use synchronous jobs and in-memory session/cache drivers to keep them isolated.

If the test database is missing from an existing volume, create it with:

```bash
docker compose exec postgres createdb -U promptedit promptedit_assessment_testing
```

The PostgreSQL data is stored in a named Docker volume. The example credentials are for local development; configure deployment credentials separately when deploying. Application keys and other credentials belong in the ignored `.env` file.

## Checks

Start PostgreSQL before running the tests:

```bash
docker compose up -d --wait
npm run build
composer ci:check
```

`composer ci:check` runs frontend formatting/lint checks, Vue/TypeScript checking, PHP formatting checks, PHPStan, and the Pest test suite. To run individual checks:

```bash
npm run check
npm run types:check
composer lint:check
composer types:check
php artisan test
```

GitHub Actions starts PostgreSQL and creates both databases before running the same checks.

## Checkpoint 1 walkthrough

### 1. A Laravel route chooses a Vue page

`routes/web.php` maps `/dashboard` to `DashboardController` behind the `auth` middleware. Guests are redirected to login. The controller returns:

```php
return Inertia::render('Dashboard', [
    'title' => 'Your generations',
]);
```

`Dashboard` selects `resources/js/pages/Dashboard.vue`. The array is the page's props, which Vue receives from Laravel. The controller is responsible for choosing the data; the Vue component renders it. The home route redirects to this dashboard.

### 2. Initial requests and Inertia navigation

On the first visit or browser refresh, Laravel returns the root HTML view, `resources/views/app.blade.php`, with the initial Inertia page data and frontend assets. Vue mounts that page in the browser. Server-side rendering is explicitly disabled in Vite and through `INERTIA_SSR_ENABLED=false`; this authenticated app does not need a separate rendering service.

For navigation using Inertia's `Link` component, Inertia requests the next page with an `X-Inertia` header. Laravel responds with the page component name and props as JSON. Inertia changes the Vue page and browser URL without reloading the entire document.

Laravel owns the routes, authentication, and validation. There is no separate Vue Router or custom frontend API layer in this checkpoint.

### 3. Typed props and layouts

`resources/js/pages/Dashboard.vue` declares its input:

```vue
<script setup lang="ts">
defineProps<{
    title: string;
}>();
</script>

<template>
    <h1>{{ title }}</h1>
</template>
```

`<script setup>` uses Vue's Composition API. Top-level bindings and declared props are available directly in the template. `lang="ts"` enables TypeScript, and `defineProps` supplies the component's input type. This is a compile-time check; it does not validate a server response at runtime.

The actual page also uses Inertia's `Head` to set the browser title. Its `defineOptions` declaration supplies breadcrumb options to the layout.

`resources/js/app.ts` initializes Inertia and chooses layouts: authentication pages use `AuthLayout`, dashboard pages use `AppLayout`, and settings pages add `SettingsLayout`. The Inertia Vite plugin resolves page components. `HandleInertiaRequests` shares the application name and authenticated user with every page.

### 4. Follow the login form

Start in `resources/js/pages/auth/Login.vue`:

- `store.form()` is a Wayfinder-generated description of Laravel's login route and HTTP method. The Vite plugin regenerates these TypeScript helpers from the routes.
- Inertia's `Form` collects named inputs and submits them to Laravel. Its slot exposes `processing` to disable duplicate submissions and `errors` to display validation messages.
- Fortify handles authentication, verifies the stored password hash, regenerates the session, and redirects the authenticated user to the dashboard.
- When validation fails, Laravel redirects back with session errors. Inertia exposes those errors to the form so `InputError` can render them.
- Successful login clears the password field through `reset-on-success`. Logout invalidates the authenticated session.

The app uses Laravel session cookies and CSRF protection. It does not need a browser API token for these pages.

### 5. Interview explanations to practice

- "Laravel owns routing and data access. Inertia sends the selected Vue page its props, so I can build an interactive UI with conventional Laravel controllers."
- "I use the official starter authentication instead of writing password and session handling myself. I kept registration and password confirmation, with optional authentication features omitted for this assessment."
- "The dashboard title is a small example of server-owned data flowing into a typed Vue component. The same flow will carry generation history later."
- "Tests use a separate PostgreSQL database, so they exercise the intended database engine without touching my development accounts."

Next checkpoint: calculate estimated and actual generation costs with exact decimal arithmetic, supported by focused pricing tests.
