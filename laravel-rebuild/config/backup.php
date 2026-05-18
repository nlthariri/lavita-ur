<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

return [

    'backup' => [

        /*
         * De naam van de backup. Wordt gebruikt als directory-naam in de backup-destination.
         */
        'name' => env('APP_NAME', 'lavita-urenregistratie'),

        'source' => [

            'files' => [

                /*
                 * Bestanden en directories die in de backup worden opgenomen.
                 */
                'include' => [
                    storage_path('app/private'),
                ],

                /*
                 * Bestanden en directories die worden uitgesloten.
                 */
                'exclude' => [
                    storage_path('app/private/backups'),
                ],

                /*
                 * Volg symlinks.
                 */
                'follow_links' => false,

                /*
                 * Negeer onleesbare directories.
                 */
                'ignore_unreadable_directories' => false,

                /*
                 * Relatief pad voor bestanden in het zip-archief.
                 */
                'relative_path' => null,
            ],

            'databases' => [
                'mysql',
            ],
        ],

        /*
         * Database-dump instellingen.
         */
        'database_dump_compressor' => GzipCompressor::class,

        'database_dump_file_timestamp_format' => null,

        'database_dump_filename_base' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [

            /*
             * Bestandsnaam prefix voor het zip-archief.
             */
            'filename_prefix' => 'backup-',

            /*
             * Disks waarop de backup wordt opgeslagen.
             */
            'disks' => [
                'local',
            ],
        ],

        /*
         * Archief-encryptie: AES-256-CBC met BACKUP_ARCHIVE_PASSWORD.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',

        /*
         * Tijdelijke directory voor het aanmaken van de backup.
         */
        'temporary_directory' => storage_path('app/backup-temp'),

    ],

    /*
     * Notificatie-instellingen bij backup-events.
     */
    'notifications' => [

        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            'to' => env('BACKUP_ALERT_EMAIL', 'admin@lavita.nl'),
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'noreply@lavita.nl'),
                'name' => env('MAIL_FROM_NAME', 'LaVita Backup'),
            ],
        ],
    ],

    /*
     * Monitoring: controleer of backups gezond zijn.
     */
    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'lavita-urenregistratie'),
            'disks' => ['local'],
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    'cleanup' => [

        /*
         * Cleanup-strategie: verwijder backups ouder dan 30 dagen.
         */
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [

            /*
             * Bewaar alle backups van de afgelopen 30 dagen.
             */
            'keep_all_backups_for_days' => 30,

            /*
             * Bewaar dagelijkse backups tot 30 dagen.
             */
            'keep_daily_backups_for_days' => 30,

            /*
             * Bewaar wekelijkse backups tot 8 weken.
             */
            'keep_weekly_backups_for_weeks' => 8,

            /*
             * Bewaar maandelijkse backups tot 12 maanden.
             * Enterprise: maandelijkse snapshots voor het afgelopen jaar.
             */
            'keep_monthly_backups_for_months' => 12,

            /*
             * Bewaar jaarlijkse backups tot 7 jaar.
             * Conform fiscale bewaarplicht en NFR-9 (7-jaars retentie).
             */
            'keep_yearly_backups_for_years' => 7,

            /*
             * Verwijder de oudste backup als de maximale opslag wordt overschreden.
             */
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],
    ],

];
