<?php

return [
    // Encrypted with AES-256-GCM using a key derived from this secret. KEEP A COPY OUTSIDE THE SERVER:
    // without it a backup cannot be opened. Falls back to APP_KEY only if unset (then rotating APP_KEY breaks old backups).
    'key' => env('BACKUP_ENCRYPTION_KEY'),

    'disk' => env('BACKUP_DISK', 'local'),           // where archives are kept on the server (relative to that disk)
    'directory' => 'backups',
    'offsite_disk' => env('BACKUP_OFFSITE_DISK'),    // any Laravel filesystem disk (S3/SFTP need their flysystem package)
    'include_media' => (bool) env('BACKUP_INCLUDE_MEDIA', true),
    'media_disk' => env('BACKUP_MEDIA_DISK', 'public'),

    // Grandfather-father-son: keep all of the last N days, then one per week, then one per month.
    'retention' => ['daily' => (int) env('BACKUP_KEEP_DAILY', 7), 'weekly' => (int) env('BACKUP_KEEP_WEEKLY', 4), 'monthly' => (int) env('BACKUP_KEEP_MONTHLY', 6)],

    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'schedule_at' => env('BACKUP_AT', '02:30'),
];
