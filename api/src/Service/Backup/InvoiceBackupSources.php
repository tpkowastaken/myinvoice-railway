<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;

/** Invoice archive scope shared with MyÚčto's cron-backup-pdf. */
final class InvoiceBackupSources
{
    /** @return list<array{0:string, 1:?list<string>, 2:string}> */
    public static function sources(Config $config): array
    {
        return [
            [
                RuntimePaths::storage('invoices'),
                ['pdf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp',
                 'csv', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'zip'],
                'storage/invoices',
            ],
            [(string) ($config->get('invoice.import_archive_storage', '') ?: RuntimePaths::storage('invoices-imported')), null, 'storage/invoices-imported'],
            [RuntimePaths::storage('work-reports'), ['pdf'], 'storage/work-reports'],
            [
                (string) ($config->get('purchase_invoice.archive_storage', '') ?: RuntimePaths::storage('purchase-invoices')),
                // Mirrors the original formats stored by PurchaseInvoicePdfArchiver.
                ['pdf', 'isdoc', 'isdocx', 'xml', 'json'],
                'storage/purchase-invoices',
            ],
        ];
    }
}
