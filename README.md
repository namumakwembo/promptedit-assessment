# PromptEdit Assessment

An image-generation app built with Laravel, Vue, Inertia, and PostgreSQL. Users can request an image, see its progress, and view the estimated and final cost. [Pixelforge](https://pixelforge.promptedit.dev/docs) is the simulated image provider used for this assessment.

## How it works

1. **Estimate:** The user enters a prompt, size, quality, and optional reference image URLs. Laravel reads Pixelforge's pricing and calculates a range that includes our 25% markup.
2. **Submit:** [GenerationController](app/Http/Controllers/GenerationController.php) saves the generation and queues a background job, so the browser does not wait for Pixelforge to finish.
3. **Generate:** [SubmitGeneration](app/Jobs/SubmitGeneration.php) sends the request to Pixelforge. Retries use the same idempotency key to avoid creating the same generation twice.
4. **Update:** Signed webhooks report status changes. [ProcessPixelforgeWebhook](app/Jobs/ProcessPixelforgeWebhook.php) handles each event once. Laravel also polls Pixelforge while work is pending, so missed webhooks do not leave generations stuck. Duplicate or delayed results cannot change a completed generation.
5. **Calculate the final cost:** [GenerationPricing](app/Services/GenerationPricing.php) uses actual token usage and the generation's saved rates, then adds 25%. Decimal arithmetic preserves fractions of a cent. Failed generations cost zero; pending charges stay unknown.
6. **Show the result:** [Dashboard.vue](resources/js/pages/Dashboard.vue) displays the user's own history, images, and charges. It polls Laravel every three seconds while work is pending to keep the screen updated.

## Run locally

Requires PHP 8.5, Composer, Node.js 22 or 24 with npm, and Docker Compose. PHP needs `pdo_pgsql`, `mbstring`, and `bcmath`.

```bash
cp .env.example .env
composer install
php artisan key:generate
docker compose up -d --wait
php artisan migrate
npm ci
npm run build
```

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in `.env`, then start the app:

```bash
php artisan config:clear
composer run dev
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000) and register an account. The development command starts Laravel, Vite, the queue listener, and logs. Keep the queue running for generation requests and status updates. Docker runs PostgreSQL on port `5433` and creates separate development and test databases.

## Local webhooks

With the app running, start an HTTPS tunnel:

```bash
ngrok http http://127.0.0.1:8000
```

In Pixelforge, set the API key's webhook URL to the tunnel address followed by `/webhooks/pixelforge`. The signing secret must match the key. A test event should return `204`.

The provider's [test keywords](https://pixelforge.promptedit.dev/docs#testing), including `[succeed]`, `[fail]`, and `[duplicate]`, can be added to prompts to check those scenarios.

## Checks

With PostgreSQL running:

```bash
npm run build
composer ci:check
```

This runs the build, formatting and lint checks, TypeScript, PHPStan, and Pest tests. Tests use a separate PostgreSQL database and fake provider requests. They cover authentication, pricing, submissions, retries, and duplicate or out-of-order webhooks.
