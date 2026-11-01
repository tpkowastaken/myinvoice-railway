<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Backup\BackupFileCollector;
use MyInvoice\Service\Backup\InvoiceBackupSources;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class InvoiceBackupSourcesTest extends TestCase
{
    public function testInvoiceArchiveContainsOriginalsAttachmentsAndCanonicalPaths(): void
    {
        $old = getenv('MYINVOICE_DATA_DIR');
        $root = sys_get_temp_dir() . '/invoice-backup-' . bin2hex(random_bytes(8));
        $inputs = [
            'runtime/storage/invoices/sup-1/invoice.pdf' => 'storage/invoices/sup-1/invoice.pdf',
            'runtime/storage/invoices/sup-1/attachment.DOCX' => 'storage/invoices/sup-1/attachment.DOCX',
            'runtime/storage/invoices/sup-1/photo.jpg' => 'storage/invoices/sup-1/photo.jpg',
            'runtime/storage/invoices-imported/sup-1/imported.pdf' => 'storage/invoices-imported/sup-1/imported.pdf',
            'runtime/storage/work-reports/report.pdf' => 'storage/work-reports/report.pdf',
            'external/sources/a.isdoc' => 'storage/purchase-invoices/sources/a.isdoc',
            'external/sources/b.isdocx' => 'storage/purchase-invoices/sources/b.isdocx',
            'external/sources/c.xml' => 'storage/purchase-invoices/sources/c.xml',
            'external/sources/d.json' => 'storage/purchase-invoices/sources/d.json',
            'external/vendor.pdf' => 'storage/purchase-invoices/vendor.pdf',
            'external/ignored.tmp' => null,
            'runtime/storage/work-reports/ignored.txt' => null,
            'runtime/storage/documents/other.pdf' => null,
        ];
        try {
            foreach ($inputs as $path => $_) {
                @mkdir(dirname($root . '/' . $path), 0700, true);
                file_put_contents($root . '/' . $path, 'synthetic:' . $path);
            }
            putenv('MYINVOICE_DATA_DIR=' . $root . '/runtime');
            $config = new Config(['purchase_invoice' => ['archive_storage' => $root . '/external']]);
            $files = BackupFileCollector::collect(InvoiceBackupSources::sources($config));
            $expected = array_values(array_filter($inputs));
            $actual = array_values($files);
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($root . '/backup.zip', ZipArchive::CREATE));
            foreach ($files as $path => $entry) {
                self::assertTrue($zip->addFile($path, $entry));
            }
            self::assertTrue($zip->close());
            self::assertTrue($zip->open($root . '/backup.zip'));
            foreach ($inputs as $path => $entry) {
                if ($entry !== null) {
                    self::assertSame('synthetic:' . $path, $zip->getFromName($entry));
                }
            }
            $zip->close();
            // Both archive roots may also live outside the runtime data directory.
            $custom = new Config(['invoice' => ['import_archive_storage' => $root . '/external']]);
            $customFiles = BackupFileCollector::collect(InvoiceBackupSources::sources($custom));
            self::assertContains('storage/invoices-imported/sources/a.isdoc', $customFiles);
        } finally {
            putenv($old === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $old);
            if (is_dir($root)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $item) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($root);
            }
        }
    }
}
