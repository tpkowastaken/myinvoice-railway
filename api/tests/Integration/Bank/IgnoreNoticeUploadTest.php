<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/** MariaDB smoke test nad explicitně připravenou syntetickou firmou; všechny změny rollback. */
final class IgnoreNoticeUploadTest extends TestCase
{
    public function testManualIgnoreThenGpcPreviewConfirmAndDuplicate(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/cfg.php')) $this->markTestSkipped('Vyžaduje lokální DB a tools/prepareBankIgnoreDemo.php --prepare.');
        $container = Bootstrap::buildApp()->getContainer();
        $db = $container->get(Connection::class);
        try { $pdo = $db->pdo(); } catch (\PDOException) { $this->markTestSkipped('Lokální DB není dostupná.'); }
        $supplier = $pdo->query("SELECT id, default_currency_id FROM supplier WHERE company_name = 'TEST - ignorovani aviz - demo'")->fetch(PDO::FETCH_ASSOC);
        if (!$supplier) $this->markTestSkipped('Spusťte tools/prepareBankIgnoreDemo.php --prepare.');
        $sid = (int) $supplier['id'];
        $source = $pdo->prepare('SELECT id FROM bank_statements WHERE file_hash = ?');
        $source->execute([hash('sha256', 'bank-ignore-demo:demo')]);
        $sourceId = (int) $source->fetchColumn();
        self::assertGreaterThan(0, $sourceId);
        $userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE supplier_id = ' . $sid)->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ' . $sid)->fetchColumn());
        $action = $container->get(BankStatementAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/bank-statements/upload')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid);
        $tmp = tempnam(sys_get_temp_dir(), 'bank-ignore-smoke-');
        // Unikátní neinterpretovaný řádek brání kolizi s ručním testem stejného GPC.
        $content = file_get_contents($root . '/api/tests/Fixtures/BankIgnoreTransfer/statement.gpc') . '999TEST-' . bin2hex(random_bytes(8)) . "\r\n";
        file_put_contents($tmp, $content);
        $pdo->beginTransaction();
        try {
            $ids = $pdo->query('SELECT id FROM bank_transactions WHERE statement_id = ' . $sourceId . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            self::assertCount(5, $ids);
            foreach ($ids as $id) {
                $response = $action->ignore($request->withParsedBody(['note' => 'Synthetic audit note']), new Response(), ['id' => (int) $id]);
                self::assertSame(200, $response->getStatusCode());
            }
            $upload = function (array $body) use ($action, $request, $tmp, $content): array {
                $file = new UploadedFile($tmp, 'synthetic.gpc', 'text/plain', strlen($content));
                $response = $action->upload($request->withUploadedFiles(['file' => $file])->withParsedBody($body), new Response());
                return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
            };
            [$status, $preview] = $upload(['account_id' => $supplier['default_currency_id']]);
            self::assertSame(409, $status, json_encode($preview));
            self::assertCount(2, $preview['error']['candidates']);
            self::assertSame([0, 1], array_column($preview['error']['candidates'], 'index'));
            [$status, $result] = $upload(['account_id' => $supplier['default_currency_id'], 'ignore_decision' => json_encode(['fingerprint' => $preview['error']['fingerprint'], 'selected' => [0]])]);
            self::assertSame(200, $status, json_encode($result));
            self::assertSame(1, $result['ignored_transferred']);
            self::assertSame(0, $result['matched']);
            self::assertSame(6, $result['transactions']);
            $count = $pdo->query('SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ' . (int) $result['statement_id'] . " AND match_status = 'ignored'")->fetchColumn();
            self::assertSame(1, (int) $count);
            [$status, $duplicate] = $upload(['account_id' => $supplier['default_currency_id']]);
            self::assertSame(200, $status);
            self::assertTrue($duplicate['duplicate']);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            unlink($tmp);
        }
    }
}
