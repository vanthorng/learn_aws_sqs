# Invoice imports

Invoice workbooks are uploaded to private storage and processed asynchronously through the configured Laravel queue. In production configure `IMPORTS_DISK=s3`, `IMPORT_QUEUE_CONNECTION=sqs`, the AWS credentials/bucket, and Pusher credentials.

## Vercel and Pusher

Set these Vercel environment variables to their **actual Pusher dashboard values** for the Production environment, then redeploy:

- `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, and `PUSHER_APP_CLUSTER` for Laravel server-side broadcasting.
- `VITE_PUSHER_APP_KEY` and `VITE_PUSHER_APP_CLUSTER` for the browser bundle.

Do not set a Vercel variable to `${PUSHER_APP_KEY}` or `${PUSHER_APP_CLUSTER}`. Vite embeds `VITE_*` values at build time, and unresolved placeholders produce invalid hosts such as `sockjs-${pusher app cluster}.pusher.com`. The Vercel build now runs `npm run build`, so each deployment compiles the browser bundle with the Vercel `VITE_*` values.

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
