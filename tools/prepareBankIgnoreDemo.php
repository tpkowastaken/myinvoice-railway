<?php

declare(strict_types=1);

/** Syntetická data pro ruční test. Bez --prepare pouze vytvoří GPC výpis. */
require __DIR__ . '/../api/vendor/autoload.php';

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\GpcParser;

$options = getopt('', ['prepare', 'run:', 'output:']);
$run = (string) ($options['run'] ?? 'demo');
if (preg_match('/^[a-zA-Z0-9_-]{1,24}$/', $run) !== 1) throw new RuntimeException('Neplatný identifikátor --run.');
$root = dirname(__DIR__);
$rows = json_decode(file_get_contents($root . '/api/tests/Fixtures/BankIgnoreTransfer/notices.json'), true, flags: JSON_THROW_ON_ERROR);
$account = '1000000005'; // Syntetický účet s platnou mod-11 kontrolou.
if ($run !== 'demo') {
    $prefix = (int) (hexdec(substr(hash('sha256', $run), 0, 6)) % 900000) + 10000;
    do {
        $digits = str_pad((string) $prefix, 6, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach ([10, 5, 8, 4, 2, 1] as $i => $weight) $sum += (int) $digits[$i] * $weight;
        if ($sum % 11 === 0) break;
        $prefix++;
    } while (true);
    $account = $prefix . '-' . $account;
}
$gpcAccount = str_replace('-', '', $account);
$output = (string) ($options['output'] ?? $root . '/api/tests/Fixtures/BankIgnoreTransfer/statement.gpc');
function field(string $line, int $offset, int $width, string $value): string {
    return substr_replace($line, substr(str_pad($value, $width, ' ', STR_PAD_RIGHT), 0, $width), $offset, $width);
}
$header = str_repeat(' ', 128);
foreach ([[0,3,'074'], [3,16,$gpcAccount], [19,20,'TEST ' . substr(hash('sha256', $run), 0, 12)], [39,6,'140926'], [45,14,'00000001000000'], [59,1,'+'],
    [60,14,'00000000878800'], [74,1,'+'], [75,14,'00000000131300'], [89,1,'+'], [90,14,'00000000010100'], [104,1,'+'], [105,3,'001'], [108,6,'160926']] as [$offset,$width,$value]) {
    $header = field($header,$offset,$width,$value);
}
$lines = [$header];
$statementRows = [...$rows, ['amount' => 101, 'vs' => '910001', 'name' => 'TEST OPACNE ZNAMENKO', 'account' => $account]];
foreach ($statementRows as $index => $row) {
    $line = str_repeat(' ', 128);
    foreach ([[0,3,'075'], [3,16,$gpcAccount], [19,16,$row['account'] ?? ''], [35,13,str_pad((string) ($index+1),13,'0',STR_PAD_LEFT)],
        [48,12,str_pad((string) (int) round(abs($row['amount'])*100),12,'0',STR_PAD_LEFT)], [60,1,$row['amount'] < 0 ? '1' : '2'],
        [61,10,$row['vs'] ?? ''], [73,4,$row['account'] ? '0100' : ''], [97,20,$row['name']], [117,5,'00203'], [122,6,'160926']] as [$offset,$width,$value]) {
        $line = field($line,$offset,$width,$value);
    }
    $lines[] = $line;
}
$content = implode("\r\n", $lines) . "\r\n";
$parsed = (new GpcParser())->parse($content);
if (count($parsed['transactions']) !== 6 || array_sum(array_column($parsed['transactions'], 'amount')) !== -1212.0) {
    throw new RuntimeException('Kontrola syntetického GPC selhala.');
}
if (file_put_contents($output, $content) === false) throw new RuntimeException('Výpis se nepodařilo uložit.');
echo "Výpis: $output\n";
if (!isset($options['prepare'])) exit(0);

$db = new Connection(Config::load($root));
$pdo = $db->pdo();
if (!$db->hasColumn('bank_transactions', 'ignore_origin')) throw new RuntimeException('Nejdřív spusťte php api/bin/migrate.php.');
$name = 'TEST - ignorovani aviz - ' . $run;
$existing = $pdo->prepare('SELECT id FROM supplier WHERE company_name = ?');
$existing->execute([$name]);
if ($existing->fetchColumn() !== false) throw new RuntimeException('Testovací firma již existuje. Pro nové kolo zvolte jiné --run.');
$countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
$vatId = (int) $pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 0 ORDER BY id LIMIT 1')->fetchColumn();
$currencyId = (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
if (!$countryId || !$vatId || !$currencyId) throw new RuntimeException('Chybí základní číselníky.');
$collision = $pdo->prepare('SELECT id FROM currencies WHERE account_number = ? AND bank_code = ?');
$collision->execute([$account, '0100']);
if ($collision->fetchColumn() !== false) throw new RuntimeException('Testovací účet je již použitý. Zvolte jiné --run.');
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO supplier (company_name, display_name, street, city, zip, country_id, email, is_vat_payer, default_currency_id, default_vat_rate_id, auto_send_reminders)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 0)')->execute([$name, $name, 'Testovaci 1', 'Testov', '00000', $countryId, 'bank-ignore-demo@example.invalid', $currencyId, $vatId]);
    $supplierId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_default, account_number, bank_code) VALUES (?, 'CZK', 'TEST bankovni ucet', 'Kc', 'Ceska koruna', 'Czech crown', 1, ?, '0100')")
        ->execute([$supplierId, $account]);
    $currencyId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currencyId, $supplierId]);
    $pdo->prepare("INSERT INTO bank_statements (source, file_name, file_hash, account_number, bank_code, currency, statement_date, transaction_count)
        VALUES ('email_notice', ?, ?, ?, '0100', 'CZK', '2026-09-01', ?)")
        ->execute(['TEST aviza ' . $run, hash('sha256', 'bank-ignore-demo:' . $run), $account, count($rows)]);
    $statementId = (int) $pdo->lastInsertId();
    $txIds = [];
    foreach ($rows as $i => $row) {
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, source, source_ref, posted_at, amount, currency, variable_symbol, counterparty_account, counterparty_bank, counterparty_name, description)
            VALUES (?, 'email_notice', ?, '2026-09-15', ?, 'CZK', ?, ?, ?, ?, ?)")
            ->execute([$statementId, 'bank-ignore-demo:' . $run . ':' . $i, $row['amount'], $row['vs'], $row['account'], $row['account'] ? '0100' : null, $row['name'], 'SYNTETICKY TEST: ' . $row['note']]);
        $txId = (int) $pdo->lastInsertId();
        $txIds[] = $txId;
        $pdo->prepare("INSERT INTO bank_email_processed_messages (supplier_id, fallback_hash, message_id, sender, subject, status, bank_statement_id, bank_transaction_id)
            VALUES (?, ?, ?, 'bank-ignore-demo@example.invalid', 'SYNTETIC TEST NOTICE', 'processed_success', ?, ?)")
            ->execute([$supplierId, hash('sha256', 'bank-ignore-demo:' . $run . ':' . $i), 'bank-ignore-demo-' . $run . '-' . $i . '@example.invalid', $statementId, $txId]);
    }
    $pdo->commit();
    echo "Testovací firma: $name (ID $supplierId)\nÚčet ID: $currencyId\nAvízo-výpis: /bank/$statementId\nAvíza ID: " . implode(', ', $txIds) . "\n";
    echo "Avíza jsou nespárovaná. Označte je v UI jako ignorovaná a pak nahrajte připravený GPC.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
