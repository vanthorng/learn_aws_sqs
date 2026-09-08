# Invoice imports

Invoice workbooks are uploaded to private storage and processed asynchronously through the configured Laravel queue. In production configure `IMPORTS_DISK=s3`, `IMPORT_QUEUE_CONNECTION=sqs`, the AWS credentials/bucket, and Pusher credentials.

Create an SQS primary queue named by `IMPORT_QUEUE` and a dead-letter queue named by `SQS_DLQ` (or supply its full URL). Set the primary queue's AWS redrive policy to the DLQ with `maxReceiveCount` matching `SQS_MAX_RECEIVE_COUNT` (3 by default). Terminal Laravel job failures also publish sanitized import metadata to that DLQ. Run a dedicated worker:

```powershell
php artisan queue:work sqs --queue=invoice-imports --tries=3 --backoff=30,120,300
```

The worker requires access to the same private import disk and Pusher configuration as the web application. Failed jobs are also recorded by Laravel's configured failed-job driver for investigation.
