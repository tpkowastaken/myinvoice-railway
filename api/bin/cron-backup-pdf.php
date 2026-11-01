<?php

declare(strict_types=1);

/**
 * Denní záloha faktur — PDF, přílohy a originály v storage/invoices/,
 * storage/invoices-imported/, storage/work-reports/, storage/purchase-invoices/
 * → ZIP do storage/backup/{dbname}-pdf-YYYY-MM-DD.zip.
 * Retention: 30 denních + 12 měsíčních (1. v měsíci se zachová déle).
 *
 * Vyžaduje PHP ext-zip.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$dbName  = (string) $config->get('db.name');

$run = CronRun::start((new Connection($config))->pdo(), 'cron-backup-pdf');

// Resolve backup output dir — stejné pořadí jako cron-backup.php (issue #34).
$backupDir = (string) $config->get('cron.backup.output_dir', '');
if ($backupDir === '') {
    $backupDir = (string) $config->get('storage.backup_dir', '');
}
if ($backupDir === '') {
    $dataDir = (string) (getenv('MYINVOICE_DATA_DIR') ?: '');
    $backupDir = $dataDir !== '' ? rtrim($dataDir, '/\\') . '/storage/backup' : $rootDir . '/storage/backup';
}
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

if (!class_exists(ZipArchive::class)) {
    $msg = 'PHP ext-zip není nainstalována.';
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}

// Volitelné šifrování zálohy (cfg cron.backup.password, AES-256).
$zipPassword = BackupEncryption::passwordFromConfig($config);
if (($msg = BackupEncryption::unsupportedReason($zipPassword)) !== null) {
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}

$date = date('Y-m-d_H-i');
$file = "$backupDir/$dbName-pdf-$date.zip";

$pdfs = \MyInvoice\Service\Backup\BackupFileCollector::collect(
    \MyInvoice\Service\Backup\InvoiceBackupSources::sources($config),
    [],
    [],
    static function (string $abs): void {
        fprintf(STDERR, "  ✗ soubor mimo zdrojový adresář, přeskočen: %s\n", $abs);
    },
);

if (count($pdfs) === 0) {
    echo "[" . date('Y-m-d H:i:s') . "] backup-pdf: žádné soubory faktur k záloze.\n";
    $run->finish('ok', ['files' => 0, 'note' => 'no invoice files to back up']);
    exit(0);
}

@unlink($file);
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create ZIP: $file\n");
    exit(1);
}
foreach ($pdfs as $abs => $rel) {
    if (!$zip->addFile($abs, $rel)) {
        fwrite(STDERR, "Cannot add to ZIP: $abs\n");
        $zip->close();
        @unlink($file);
        exit(1);
    }
    // PDF je už interně komprimované (FlateDecode) — STORE je rychlejší a velikost stejná
    if (defined('ZipArchive::CM_STORE')) {
        $zip->setCompressionName($rel, ZipArchive::CM_STORE);
    }
    if (!\MyInvoice\Service\Backup\BackupZipPermissions::neutralize($zip, $rel)) {
        fwrite(STDERR, "Cannot normalize ZIP entry permissions: $rel\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'zip permission normalization failed', 1);
        exit(1);
    }
    if (!BackupEncryption::encryptEntry($zip, $rel, $zipPassword)) {
        fwrite(STDERR, "Cannot encrypt ZIP entry: $rel\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'zip encryption failed', 1);
        exit(1);
    }
}
if (!$zip->close()) {
    @unlink($file);
    fwrite(STDERR, "ZIP close failed.\n");
    exit(1);
}

if (!is_file($file) || filesize($file) < 100) {
    fwrite(STDERR, "ZIP backup is empty.\n");
    @unlink($file);
    exit(1);
}

$size = round(filesize($file) / 1024, 1);
$count = count($pdfs);
echo "[" . date('Y-m-d H:i:s') . "] backup-pdf: " . basename($file) . " ({$count} souborů, {$size} KB)\n";

$report = ['file' => basename($file), 'files' => $count, 'size_kb' => $size];
if ($zipPassword !== '') {
    $report['encrypted'] = 'AES-256';
}

// Retention: smaž PDF zálohy starší 30 dní (1. v měsíci drž 365 dní).
// Filtrujeme jen vlastní prefix "{dbName}-pdf-", aby se nedotklo DB dumpů.
$prefix = $dbName . '-pdf-';
$files = glob($backupDir . '/' . $prefix . '*.zip') ?: [];
$now = time();
foreach ($files as $f) {
    if (!preg_match('/-(\d{4}-\d{2}-\d{2})(?:_\d{2}-\d{2})?\.zip$/', $f, $m)) continue;
    $age = $now - strtotime($m[1]);
    $isMonthly = str_ends_with($m[1], '-01');
    $maxAge = $isMonthly ? 365 * 86400 : 30 * 86400;
    if ($age > $maxAge) {
        @unlink($f);
        echo "  - retention: smazáno " . basename($f) . "\n";
        $report['retention_purged'] = ($report['retention_purged'] ?? 0) + 1;
    }
}

$run->finish('ok', $report);
