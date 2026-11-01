<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup;

use MyInvoice\Service\Backup\BackupFileCollector;
use PHPUnit\Framework\TestCase;

final class BackupFileCollectorTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/bfc-' . bin2hex(random_bytes(6));
        $this->mkfile('docs/faktura.pdf');
        $this->mkfile('docs/sources/original.isdoc');
        $this->mkfile('docs/priloha.docx');
        $this->mkfile('docs/poznamka.txt');
        $this->mkfile('docs/_thumbs/nahled.pdf');
        $this->mkfile('docs/_jobs/tmpjob.pdf');
        $this->mkfile('docs/.tmp-rozpracovane.pdf');
        // Přílohy deníku jsou content-addressed — BEZ přípony.
        $this->mkfile('journal/sup-1/ab/abcdef0123456789');
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /**
     * strojové originály (.isdoc) i non-PDF přílohy musí do zálohy —
     * plochý filtr na `pdf` je dřív tiše vynechával.
     */
    public function testAllowedExtensionsIncludeMachineOriginalsAndAttachments(): void
    {
        $rels = array_values(BackupFileCollector::collect([
            [$this->tmp . '/docs', ['pdf', 'isdoc', 'docx'], 'storage/invoices'],
        ]));
        sort($rels);

        self::assertContains('storage/invoices/faktura.pdf', $rels);
        self::assertContains('storage/invoices/sources/original.isdoc', $rels, 'Strojový originál patří do zálohy.');
        self::assertContains('storage/invoices/priloha.docx', $rels, 'Non-PDF příloha patří do zálohy.');
        self::assertNotContains('storage/invoices/poznamka.txt', $rels, '.txt není v povolených příponách.');
    }

    /** Přílohy deníku nemají příponu — allowedExt = null je musí pustit. */
    public function testNullExtensionFilterTakesContentAddressedFiles(): void
    {
        $rels = array_values(BackupFileCollector::collect([
            [$this->tmp . '/journal', null, 'storage/journal'],
        ]));

        self::assertSame(['storage/journal/sup-1/ab/abcdef0123456789'], $rels);
    }

    /**
     * cesta v ZIPu se skládá z PEVNÉHO prefixu a ořezu VLASTNÍM zdrojovým
     * adresářem — nikdy ne podle kořene aplikace. Zdroj proto smí ležet kdekoli
     * (vlastní archive_storage, MYINVOICE_DATA_DIR) a tvar archivu se nezmění.
     */
    public function testZipPathIsIndependentOfSourceLocation(): void
    {
        $rels = array_values(BackupFileCollector::collect([
            [$this->tmp . '/journal', null, 'storage/journal'],
        ]));
        // Prefix je logický, ne odvozený z fyzického umístění.
        self::assertStringStartsWith('storage/journal/', $rels[0]);
        self::assertStringNotContainsString($this->tmp, $rels[0], 'V ZIPu nesmí být fyzická cesta.');
        self::assertStringNotContainsString('\\', $rels[0], 'Cesty v ZIPu jsou vždy s lomítky.');
    }

    /** Regenerovatelné a dočasné soubory se nezálohují. */
    public function testExcludesThumbnailsJobsAndTempFiles(): void
    {
        $rels = array_values(BackupFileCollector::collect(
            [[$this->tmp . '/docs', null, 'storage/documents']],
            ['/_thumbs/', '/_jobs/'],
            ['.tmp-'],
        ));

        self::assertNotContains('storage/documents/_thumbs/nahled.pdf', $rels);
        self::assertNotContains('storage/documents/_jobs/tmpjob.pdf', $rels);
        self::assertNotContains('storage/documents/.tmp-rozpracovane.pdf', $rels);
        self::assertContains('storage/documents/faktura.pdf', $rels);
    }

    /** Neexistující zdroj se přeskočí, ostatní se seberou. */
    public function testMissingSourceIsSkipped(): void
    {
        $rels = BackupFileCollector::collect([
            [$this->tmp . '/neexistuje', ['pdf'], 'storage/nic'],
            [$this->tmp . '/docs', ['pdf'], 'storage/documents'],
        ]);

        self::assertNotSame([], $rels);
        foreach ($rels as $rel) {
            self::assertStringStartsWith('storage/documents/', $rel);
        }
    }

    public function testFileSymlinkCannotIncludeFilesOutsideTheSourceRoot(): void
    {
        $this->mkfile('outside/private.pdf');
        $link = $this->tmp . '/docs/escape.pdf';
        if (!function_exists('symlink') || !@symlink($this->tmp . '/outside/private.pdf', $link)) {
            self::markTestSkipped('File symlinks are unavailable on this platform.');
        }
        $skipped = [];
        $files = BackupFileCollector::collect(
            [[$this->tmp . '/docs', ['pdf'], 'storage/invoices']],
            [],
            [],
            static function (string $path) use (&$skipped): void { $skipped[] = $path; },
        );
        self::assertNotContains('storage/invoices/escape.pdf', $files);
        self::assertSame([$link], $skipped);
    }

    private function mkfile(string $relative): void
    {
        $path = $this->tmp . '/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'x');
    }
}
