<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Service\Invoice\RecurringPriceListService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurringManualScheduleTest extends TestCase
{
    public static function generationMethods(): array
    {
        return [['generate'], ['openDraft'], ['issuePeriod']];
    }

    #[DataProvider('generationMethods')]
    public function testCronRejectsScheduleChangedBeforeLock(string $method): void
    {
        $locked = false;
        $repo = $this->createMock(RecurringTemplateRepository::class);
        $repo->expects(self::once())->method('lockSchedule')->with(12)->willReturnCallback(function () use (&$locked): void {
            $locked = true;
        });
        $repo->expects(self::once())->method('find')->with(12)->willReturnCallback(function () use (&$locked): array {
            self::assertTrue($locked);
            return ['next_run_date' => '2090-03-01'];
        });
        $repo->expects(self::once())->method('unlockSchedule')->with(12)->willReturnCallback(function () use (&$locked): void {
            $locked = false;
        });
        $repo->expects(self::never())->method('advanceSchedule');
        $reflection = new \ReflectionClass(RecurringInvoiceGenerator::class);
        $args = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType()->getName();
            $args[] = $type === RecurringTemplateRepository::class ? $repo : $this->createStub($type);
        }
        $generator = $reflection->newInstanceArgs($args);
        try {
            $generator->$method(12, expectedNextRunDate: '2090-02-01');
            self::fail('Stale cron candidate must be rejected');
        } catch (\MyInvoice\Service\Invoice\RecurringScheduleChangedException) {
            self::assertFalse($locked);
        }
    }

    public static function schedules(): array
    {
        return [
            'early annual invoice' => [true, false, '2026-09-08', '2028-02-01', null, 'active'],
            'early annual draft' => [true, true, '2026-09-08', '2028-02-01', null, 'active'],
            'late annual invoice' => [true, false, '2027-09-08', '2028-02-01', null, 'active'],
            'extra invoice' => [false, false, '2026-09-08', '2027-02-01', null, 'active'],
            'extra draft' => [false, true, '2026-09-08', '2027-02-01', null, 'active'],
            'last occurrence' => [true, true, '2026-09-08', '2028-02-01', '2027-12-31', 'expired'],
            'extra does not expire' => [false, true, '2027-09-08', '2027-02-01', '2027-12-31', 'active'],
        ];
    }

    #[DataProvider('schedules')]
    public function testManualGeneration(bool $advance, bool $draft, string $issueDate, string $next, ?string $end, string $status): void
    {
        // Výhradně syntetická in-memory databáze; žádné připojení k aplikační DB.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $columns = 'invoice_type, client_id, project_id, supplier_id, branding_profile_id, issue_date, tax_date, due_date, currency_id, reverse_charge, prices_include_vat, language, note_above_items, note_below_items, payment_method, discount_percent, recurring_template_id, revenue_category_id, status, created_by';
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, ' . $columns . ')');
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, is_vat_payer INTEGER)');
        $pdo->exec('INSERT INTO supplier VALUES (7, 1)');
        $pdo->exec('CREATE TABLE vat_rates (id INTEGER PRIMARY KEY, code TEXT, label_cs TEXT, valid_from TEXT, valid_to TEXT)');
        $pdo->exec("INSERT INTO vat_rates VALUES (1, 'TEST', 'Test', '2000-01-01', NULL)");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $items = [['description' => 'Test service', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 121, 'vat_rate_id' => 1, 'order_index' => 0]];
        $template = [
            'id' => 12, 'supplier_id' => 7, 'client_id' => 8, 'currency_id' => 1,
            'frequency' => 'annually', 'next_run_date' => '2027-02-01', 'day_of_month' => 1,
            'end_of_month' => false, 'end_date' => $end, 'status' => 'active',
            'auto_issue' => false, 'auto_send_email' => false, 'created_by' => 3,
            'payment_due_days' => 14, 'reverse_charge' => false, 'prices_include_vat' => true,
            'revenue_category_id' => 1, 'note_above_items' => null, 'note_below_items' => null,
            'increment_month_in_descriptions' => false, 'items' => $items,
        ];
        $repo = $this->createMock(RecurringTemplateRepository::class);
        $repo->method('find')->willReturn($template);
        if ($advance) {
            $repo->expects(self::once())->method('advanceSchedule')->with(12, $next, $issueDate, $status);
        } else {
            $repo->expects(self::never())->method('advanceSchedule');
        }
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('vatRateMap')->willReturn([1 => 21.0]);
        $invoices->expects(self::once())->method('replaceItems')->with(1, $items);
        $priceList = $this->createStub(RecurringPriceListService::class);
        $priceList->method('resolveForGeneration')->willReturn($items);
        $reflection = new \ReflectionClass(RecurringInvoiceGenerator::class);
        $args = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType()->getName();
            $args[] = match ($type) {
                Connection::class => $db,
                RecurringTemplateRepository::class => $repo,
                InvoiceRepository::class => $invoices,
                RecurringPriceListService::class => $priceList,
                default => $this->createStub($type),
            };
        }
        $generator = $reflection->newInstanceArgs($args);
        $result = $generator->generate(12, $issueDate, 3, '127.0.0.1', 'phpunit', $draft, $advance);
        self::assertSame($next, $result['new_next_run_date']);
        self::assertSame($status, $result['template_status']);
        $invoice = $pdo->query('SELECT * FROM invoices')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($issueDate, $invoice['issue_date']);
        self::assertSame($issueDate, $invoice['tax_date']);
        self::assertSame(1, (int) $invoice['prices_include_vat']);
        self::assertSame(12, (int) $invoice['recurring_template_id']);
    }
}
