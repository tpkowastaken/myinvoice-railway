<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\RecurringScheduleChangedException;
use PHPUnit\Framework\TestCase;

final class RecurringScheduleLockTest extends TestCase
{
    public function testConcurrentScheduleMutationIsExcludedAcrossConnections(): void
    {
        $root = dirname(__DIR__, 3);
        if (!is_file($root . '/cfg.php')) {
            self::markTestSkipped('cfg.php missing');
        }
        $config = Config::load($root);
        $repos = [];
        for ($i = 0; $i < 2; $i++) {
            $repos[] = new RecurringTemplateRepository(new Connection($config));
        }
        $id = random_int(100000000, 200000000);
        $repos[0]->lockSchedule($id);
        try {
            try {
                $repos[1]->lockSchedule($id);
                self::fail('Concurrent mutation acquired the same schedule lock');
            } catch (RecurringScheduleChangedException) {
                self::assertTrue(true);
            }
            $repos[1]->lockSchedule($id + 1);
            $repos[1]->unlockSchedule($id + 1);
        } finally {
            $repos[0]->unlockSchedule($id);
        }
        $repos[1]->lockSchedule($id);
        $repos[1]->unlockSchedule($id);
        self::assertTrue(true);
    }
}
