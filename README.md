# PromptEdit Assessment

A Laravel, Vue, and Inertia application for the PromptEdit product engineering assessment.

Checkpoint 1 provides authentication and PostgreSQL. Checkpoint 2 adds exact pricing. Checkpoint 3 connects Pixelforge to a generation form, background jobs, and live history. Signed webhooks and deployment are the next checkpoints.

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

## Checkpoint 2 walkthrough

`app/Services/GenerationPricing.php` is a standalone calculator. It does not make network requests or write charges to the database. Its inputs will come from the Pixelforge client in the next checkpoint.

### 1. Keep estimates separate from actual costs

`estimate($prompt, $size, $quality, $referenceImages, $pricing)` accepts the full response from `GET /v1/pricing` and returns `minimum` and `maximum`. Each contains `provider_cost_usd` and `customer_charge_usd` as decimal strings.

- Text tokens start at `ceil(Unicode character count / characters_per_text_token)`, then apply the published variance. Bounds round outward to whole tokens: floor for the minimum and ceiling for the maximum. This gives a conservative range, including short prompts.
- Reference image tokens are the number of URLs multiplied by the published tokens per reference image. The calculator does not download images.
- Output tokens come from the quality/size table.
- Reasoning tokens use the published minimum and maximum for the selected quality.

The calculator reads rates and token rules from its input. The JSON under `tests/Fixtures/` is a test snapshot captured on November 19, 2026, not the application's production pricing source. See the [Pixelforge pricing documentation](https://pixelforge.promptedit.dev/docs#pricing).

### 2. Calculate final costs from completed usage

`actual($generation)` reads the generation's `status`, `usage`, and saved `rates`:

| Status                 | Result                                                                             |
| ---------------------- | ---------------------------------------------------------------------------------- |
| `queued`, `processing` | `null`: actual cost is not known yet.                                              |
| `failed`               | Zero provider cost and zero customer charge.                                       |
| `succeeded`            | Cost calculated from all four usage categories and the generation's rate snapshot. |

The generation's rate snapshot matters because current prices can change after submission. `total_tokens` is a summary and is not billed as an additional category. Missing usage, invalid rates, and unknown states raise an exception instead of silently producing an incorrect charge.

### 3. Follow the money calculation

Both estimates and final costs use the private `cost()` method:

```text
provider cost = sum(each token count * its rate per million) / 1,000,000
customer charge = provider cost * 1.25
```

For the completed-generation example in the API docs:

| Token category | Tokens | USD per million | Provider cost, USD |
| -------------- | ------ | --------------- | ------------------ |
| Input text     | 12     | 5.00            | 0.000060000        |
| Input image    | 765    | 10.00           | 0.007650000        |
| Output         | 6,450  | 40.00           | 0.258000000        |
| Reasoning      | 3,184  | 12.50           | 0.039800000        |
| Total          |        |                 | 0.305510000        |

Applying the markup gives a customer charge of `0.38188750000` USD.

### 4. Preserve exact decimal amounts

Brick's `BigDecimal` performs arithmetic using exact decimal values. `brick/math` was already installed through Laravel and is now declared as a direct dependency because this calculator uses it. Rates must be decimal strings, and token counts must be nonnegative integers.

Provider costs have nine decimal places to match Pixelforge's exact USD amounts. Customer charges retain eleven decimal places: multiplying an amount with nine places by `1.25` can need two additional places. Neither amount is rounded to cents. If a provider amount cannot be represented at the documented precision, calculation raises an exception instead of rounding it silently.

Keep these strings intact when passing money to Vue and when storing it later. The UI can format amounts for display while the underlying values stay exact.

### 5. Run the pricing tests and try the calculator

Pricing unit tests run without PostgreSQL or an API key:

```bash
php artisan test tests/Unit/GenerationPricingTest.php
php artisan tinker
```

In Tinker, try the same maximum-cost example used by the tests:

```php
$pricing = json_decode(file_get_contents(base_path('tests/Fixtures/pixelforge-pricing.json')), true, flags: JSON_THROW_ON_ERROR);
$calculator = app(\App\Services\GenerationPricing::class);
$calculator->estimate(
    prompt: str_repeat('a', 400),
    size: '1536x1024',
    quality: 'high',
    referenceImages: ['https://example.test/one.png', 'https://example.test/two.png'],
    pricing: $pricing,
);
```

Expected provider range: `0.286225000` to `0.373875000` USD. Customer range: `0.35778125000` to `0.46734375000` USD. Try changing quality to `low` and explain why the output and reasoning costs decrease.

The tests cover all four rates, every supported size and quality, Unicode prompts, text token bounds, reference images, changed pricing, exact fractional amounts, lifecycle states, and malformed billing data.

## Checkpoint 3: Pixelforge and image generations

### What these terms mean

| Term       | Meaning in this app                                                                                                                                    |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Pixelforge | The assessment's simulated third-party image provider. It returns placeholder images and simulated billing data. No real AI or real money is involved. |
| Prompt     | The description you type, such as "A lighthouse at sunrise, painted in watercolors".                                                                   |
| Generation | The whole image-generation job: inputs, status, token usage, cost, and eventual image. It has its own ID; it is not just the finished image.           |
| Tokens     | The provider's billing units for text, reference images, output, and reasoning. Different categories have different prices.                            |
| Queue job  | Work Laravel performs in the background, allowing the browser request to finish quickly.                                                               |
| Polling    | Asking for the latest status periodically. Laravel polls Pixelforge; Vue separately reloads this app's saved history.                                  |

The app sits between your users and Pixelforge. Pixelforge creates images and reports usage. Our app handles accounts, validates requests, shows estimates, records final costs with the 25% markup, and presents history. See the [API documentation](https://pixelforge.promptedit.dev/docs).

### 1. Configure and try it locally

Create an account at Pixelforge, create a key for local development, and put it in the ignored `.env` file:

```dotenv
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=your-local-api-key
```

After changing environment settings, run `php artisan config:clear`. Start PostgreSQL, run migrations, and start the development processes:

```bash
docker compose up -d --wait
php artisan migrate
composer run dev
```

Open the dashboard, sign in, and enter a prompt. Choose a size and quality, optionally add up to four reference image URLs, then review the USD estimate before selecting **Generate image**. History updates as the job progresses and shows its final charge after completion.

Keep the queue process running. The default `composer run dev` command includes it. For a separate terminal, run `php artisan queue:work --tries=5`. Queue jobs store only the local generation ID; the API key is read on the server and is never sent to Vue.

### 2. Follow an estimate

`GenerationForm.vue` uses Vue refs and an Inertia `useForm` object for the user's input. A watcher waits for 600 ms after typing stops, then sends the current input to `POST /generations/estimate`. Requests that are no longer relevant are aborted, so an old price cannot overwrite a newer one.

`GenerationRequest` validates the prompt, supported options, and reference URLs. `GenerationQuote` asks `PixelforgeClient` for public pricing, cached for one minute, then calls the exact calculator from checkpoint 2. It saves a five-minute quote tied to the authenticated user and the exact inputs. Vue receives only the customer's price range and the quote ID.

The form clears the quote whenever inputs change. An expired quote must be refreshed. The store endpoint also checks the saved quote, so changing the browser's estimate or submitting an old estimate cannot change the recorded price range.

### 3. Follow a submission

`useForm.post()` sends the inputs, quote ID, and a browser request UUID to `POST /generations`. `GenerationController::store()` creates the local row in a transaction, records the quoted estimate, and dispatches `SubmitGeneration` after the transaction commits. A repeated request UUID with the same inputs returns the existing generation; different inputs are rejected.

`SubmitGeneration` loads that row and calls `PixelforgeClient::createGeneration()`. The provider payload contains only the documented inputs and metadata with the local generation ID. Its `Idempotency-Key` is the local generation UUID, so a retry uses the same parameters and retrieves the same provider generation.

| Identifier            | Purpose                                                                            |
| --------------------- | ---------------------------------------------------------------------------------- |
| Browser `request_id`  | Prevents a repeated form submission from creating another local row for that user. |
| Local generation UUID | Identifies our saved record and supplies the provider idempotency key.             |
| Provider `gen_...` ID | Identifies the provider job when checking its status.                              |
| `quote_id`            | Finds the user's saved estimate and verifies that the inputs still match.          |

### 4. Follow status updates and billing

Once accepted, `RefreshGeneration` calls `GET /v1/generations/{id}` every five seconds while the provider is queued or processing. Successful status checks schedule a fresh job. Connection errors and temporary overloads use bounded retries, exponential backoff, and the provider's `Retry-After` value. Daily quotas are not retried automatically.

`ApplyGenerationResult` locks the local row inside a transaction. It calculates final amounts using the generation's actual usage and rate snapshot, preserves the provider's UTC completion time, and saves the image URL. Terminal results are final: duplicate results and stale processing responses cannot replace a completed charge or move the record backward.

Before the submission network call, the worker records that acceptance is uncertain. This covers a timeout or a worker stopping before it saves the provider response. A definite rejection can clear uncertainty from that attempt; it cannot erase an earlier uncertain attempt. The app keeps the actual charge unknown and can retry the same idempotency key. It does not label an uncertain request as failed or free. The history's **Check submission** or **Check status** button resumes a paused check. A known provider rejection or completed failure costs zero.

`Dashboard.vue` uses Inertia's `usePoll` to reload only the current user's generation history every three seconds while work is pending. It does not call Pixelforge from the browser. `GenerationResource` exposes customer-facing fields, preserving money as strings. The history is paginated, and expired image URLs show a fallback message.

### 5. Practice useful scenarios

Use these prompt keywords, described in the [provider's testing documentation](https://pixelforge.promptedit.dev/docs#testing):

| Prompt                                     | What to observe                                                          |
| ------------------------------------------ | ------------------------------------------------------------------------ |
| `A lighthouse at sunrise [succeed]`        | A successful result, image, and final charge.                            |
| `A lighthouse at sunrise [fail]`           | Accepted initially, then failed, with a zero charge.                     |
| `A lighthouse at sunrise [blocked]`        | Rejected during submission, with a zero charge.                          |
| `A lighthouse at sunrise [slow] [succeed]` | Processing continues for 60-90 seconds without blocking the browser.     |
| `A lighthouse at sunrise [overloaded]`     | Bounded retries, then a clear failure when the forced overload persists. |

Explain the full path in the interview: "Vue requests an estimate from Laravel, Laravel saves the user's intent before queueing work, and the worker submits an idempotent provider request. Background polling stores the final usage and cost. Vue reads our own saved history."

### Checks and next checkpoint

```bash
php artisan test tests/Feature/GenerationsTest.php tests/Unit/GenerationPricingTest.php
```

These tests fake provider HTTP calls and protect against unexpected external requests. They cover validation, quote ownership/expiry, duplicate submission, rate limits, ambiguous timeouts, exact billing, terminal-state protection, and private history.

The next checkpoint adds signed webhook verification, event deduplication, and tests for events arriving twice or out of order. Polling will remain as recovery when a webhook is missed. Local webhook delivery will need a public tunnel and the API key's webhook signing secret. Deployment and reconciliation against `GET /v1/usage` follow afterward.
