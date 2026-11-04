# Invoice imports

## AWS Elastic Beanstalk deployment

This repository is prepared for the Elastic Beanstalk PHP platform. The `.platform` directory makes Nginx serve Laravel from `public/` and warms framework caches after deployment.

Before creating an environment, build the production assets in the deployment artifact:

```bash
npm ci
npm run build
composer install --no-dev --optimize-autoloader --no-interaction
```

Choose an Elastic Beanstalk PHP platform compatible with the PHP version in `composer.json`. Configure these environment properties in Elastic Beanstalk (or reference them from AWS Secrets Manager): `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, database credentials, `CACHE_STORE`, `SESSION_DRIVER`, mail credentials, Pusher credentials, and the required AWS/S3/SQS values listed below. Do not commit a production `.env` file.

Use an RDS database and persistent services for production: `IMPORTS_DISK=s3`, `IMPORT_QUEUE_CONNECTION=sqs`, `IMPORT_QUEUE`, `SQS_QUEUE`, `SQS_DLQ`, `AWS_DEFAULT_REGION`, and `AWS_BUCKET`. Attach an instance profile that grants only the S3 and SQS permissions this application requires; prefer the instance role over long-lived AWS access keys.

Run `php artisan migrate --force` as a controlled, one-off deployment step after validating database connectivity. It is intentionally not run automatically by the web instances.

The web environment does not run workers or the scheduler. Run a separate worker environment/process with the same application settings (see the queue command below).

Invoice workbooks are uploaded to private storage and processed asynchronously through the configured Laravel queue. In production configure `IMPORTS_DISK=s3`, `IMPORT_QUEUE_CONNECTION=sqs`, the AWS credentials/bucket, and Pusher credentials.

## Pusher

Set these environment variables to their **actual Pusher dashboard values** in Elastic Beanstalk, then deploy:

- `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, and `PUSHER_APP_CLUSTER` for Laravel server-side broadcasting.
- `VITE_PUSHER_APP_KEY` and `VITE_PUSHER_APP_CLUSTER` for the browser bundle.

Do not set a value to `${PUSHER_APP_KEY}` or `${PUSHER_APP_CLUSTER}`. Vite embeds `VITE_*` values at build time, so provide their actual values while building the production asset bundle. Unresolved placeholders produce invalid hosts such as `sockjs-${pusher app cluster}.pusher.com`.

Create an SQS primary queue named by `IMPORT_QUEUE` and a dead-letter queue named by `SQS_DLQ` (or supply its full URL). Set the primary queue's AWS redrive policy to the DLQ with `maxReceiveCount` matching `SQS_MAX_RECEIVE_COUNT` (3 by default). Terminal Laravel job failures also publish sanitized import metadata to that DLQ. Run a dedicated worker:

```powershell
php artisan queue:work sqs --queue=invoice-imports --tries=3 --backoff=30,120,300
```

The worker requires access to the same private import disk and Pusher configuration as the web application. Failed jobs are also recorded by Laravel's configured failed-job driver for investigation.

## Scheduled imports

Teams can upload an XLSX workbook and select a future processing time from **Schedule**. The workbook stays on the private import disk until the scheduler creates a normal invoice import and puts it onto the same queue as a manual upload. Run Laravel's scheduler alongside the queue worker in production:

```powershell
php artisan schedule:work
```

For a traditional server, run `php artisan schedule:run` every minute through the system cron instead. The `imports:dispatch-scheduled` command is safe to invoke independently when diagnosing scheduled work.

## Notifications

When an import or QuickBooks sync reaches a final state, the uploader and team members who can manage imports receive an in-app notification and email. The application header shows unread notifications; email delivery uses Laravel's normal `MAIL_*` configuration.

## API intake

An import manager can create and revoke a team API key from **Settings → Teams → API access**. Keys are displayed only once and stored as hashes. Use the key with the following endpoints:

```bash
curl -X POST "https://your-app.example/api/v1/teams/TEAM_SLUG/imports" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -F "file=@invoices.xlsx" \
  -F "auto_sync_qbo=true"

curl "https://your-app.example/api/v1/teams/TEAM_SLUG/imports/IMPORT_ID" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

The upload endpoint returns `202 Accepted` with the import ID; poll the status endpoint for progress. API requests are rate-limited to 30 requests per minute per client.
