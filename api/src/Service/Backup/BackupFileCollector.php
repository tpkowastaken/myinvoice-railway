<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Collects backup files under stable logical ZIP paths, including external storage. */
final class BackupFileCollector
{
    /**
     * @param list<array{0:string, 1:?list<string>, 2:string}> $sources
     *        [adresář, povolené přípony (null = vše), prefix uvnitř ZIPu]
     * @param list<string> $excludeSegments   podřetězce normalizované cesty k vyloučení (např. '/_thumbs/')
     * @param list<string> $excludeFilePrefixes prefixy názvu souboru k vyloučení (např. '.tmp-')
     * @param null|callable(string):void $onSkipped hlášení přeskočeného souboru mimo kořen
     * @return array<string, string> absolutní cesta => cesta uvnitř ZIPu
     */
    public static function collect(
        array $sources,
        array $excludeSegments = [],
        array $excludeFilePrefixes = [],
        ?callable $onSkipped = null,
    ): array {
        $files = [];
        foreach ($sources as [$dir, $allowedExt, $zipPrefix]) {
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }
            $root = realpath($dir);
            if ($root === false) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($it as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }
                if ($allowedExt !== null && !in_array(strtolower($entry->getExtension()), $allowedExt, true)) {
                    continue;
                }
                $abs  = $entry->getPathname();
                $norm = str_replace('\\', '/', $abs);
                foreach ($excludeSegments as $segment) {
                    if (str_contains($norm, $segment)) {
                        continue 2;
                    }
                }
                foreach ($excludeFilePrefixes as $prefix) {
                    if (str_starts_with($entry->getFilename(), $prefix)) {
                        continue 2;
                    }
                }
                // Pojistka: iterátor by neměl vylézt mimo kořen, ale kdyby (symlink,
                // race), radši soubor vynecháme, než abychom do ZIPu dali nesmyslnou cestu.
                $resolved = realpath($abs);
                if ($resolved === false || !str_starts_with(
                    strtolower(str_replace('\\', '/', $resolved)),
                    strtolower(rtrim(str_replace('\\', '/', $root), '/')) . '/',
                )) {
                    if ($onSkipped !== null) {
                        $onSkipped($abs);
                    }
                    continue;
                }
                $files[$abs] = $zipPrefix . '/' . ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
            }
        }

        return $files;
    }
}
