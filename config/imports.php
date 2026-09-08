<?php

return [
    'disk' => env('IMPORTS_DISK', env('AWS_BUCKET') ? 's3' : 'local'),
    'queue_connection' => env('IMPORT_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sqs')),
    'queue' => env('IMPORT_QUEUE', env('SQS_QUEUE', 'standard-queue')),
    'dead_letter_queue' => env('SQS_DLQ'),
    'batch_size' => (int) env('IMPORT_BATCH_SIZE', 100),
];
