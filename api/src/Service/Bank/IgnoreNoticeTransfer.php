<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Převzetí uživatelského rozhodnutí, nikoli převod úhrad nebo deduplikace avíz. */
final class IgnoreNoticeTransfer
{
    public function __construct(private readonly Connection $db) {}

    /** Volá se uvnitř transakce importu, po uzamčení cílového currencies řádku. */
    public function select(array $transactions, array $account, string $fileHash, array $decision): array
    {
        if (($decision['skip'] ?? false) === true) return [];
        $dates = array_column($transactions, 'posted_at');
        if ($dates === []) return [];
        $from = (new \DateTimeImmutable(min($dates)))->modify('-5 days')->format('Y-m-d');
        $to = (new \DateTimeImmutable(max($dates)))->modify('+5 days')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.*, bs.account_number AS own_account, bs.bank_code AS own_bank,
                    bs.currency AS statement_currency
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.source = 'email_notice' AND bs.source = 'email_notice'
                AND bt.match_status = 'ignored' AND bt.ignore_origin = 'manual'
                AND bt.matched_invoice_id IS NULL AND bt.posted_at BETWEEN ? AND ?
                AND EXISTS (SELECT 1 FROM bank_email_processed_messages m
                             WHERE m.bank_transaction_id = bt.id AND m.supplier_id = ?)
                AND NOT EXISTS (SELECT 1 FROM bank_email_processed_messages m
                                 WHERE m.bank_transaction_id = bt.id AND m.supplier_id <> ?)
                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id)
                AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id)
                AND NOT EXISTS (SELECT 1 FROM bank_notice_ignore_transfers tr WHERE tr.notice_transaction_id = bt.id)
              ORDER BY bt.id FOR UPDATE"
        );
        $stmt->execute([$from, $to, $account['supplier_id'], $account['supplier_id']]);
        $pairs = self::candidates($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC), $account);
        $fingerprint = hash('sha256', json_encode([$fileHash, $account, $pairs], JSON_THROW_ON_ERROR));
        $preview = ['fingerprint' => $fingerprint, 'candidates' => array_values($pairs)];
        if (!isset($decision['fingerprint'])) {
            if ($pairs === []) return [];
            throw new IgnoreNoticeConfirmationRequired($preview);
        }
        if (!hash_equals($fingerprint, (string) $decision['fingerprint'])) {
            throw new IgnoreNoticeConfirmationRequired($preview);
        }
        $selected = $decision['selected'] ?? null;
        if (!is_array($selected) || !array_is_list($selected)) throw new \InvalidArgumentException('Neplatný výběr avíz.');
        $result = [];
        foreach ($selected as $index) {
            if (!is_int($index) || !isset($pairs[$index]) || isset($result[$index])) {
                throw new \InvalidArgumentException('Neplatný výběr avíz.');
            }
            $result[$index] = $pairs[$index];
        }
        return $result;
    }

    /** Jednoznačnost kontrolujeme obousměrně přes celý soubor, ne po jednotlivých řádcích. */
    public static function candidates(array $transactions, array $notices, array $account): array
    {
        $possible = [];
        $uses = [];
        foreach ($transactions as $index => $tx) {
            foreach ($notices as $notice) {
                if (!AccountNumberNormalizer::matchesAny((string) $notice['own_account'], $account['account_number'] ?? null, $account['iban'] ?? null)) continue;
                if (empty($account['bank_code']) || (string) $notice['own_bank'] !== (string) $account['bank_code']) continue;
                $currency = $notice['currency'] ?: $notice['statement_currency'];
                if (!$currency || strtoupper($currency) !== strtoupper((string) $account['code'])) continue;
                if (abs((float) $tx['amount'] - (float) $notice['amount']) > 0.005) continue;
                $days = abs((new \DateTimeImmutable($tx['posted_at']))->diff(new \DateTimeImmutable($notice['posted_at']))->days);
                if ($days > 5) continue;
                $vs = VariableSymbolNormalizer::digits((string) ($tx['variable_symbol'] ?? ''));
                $noticeVs = VariableSymbolNormalizer::digits((string) ($notice['variable_symbol'] ?? ''));
                $counterparty = (string) ($tx['counterparty_account'] ?? '');
                $noticeCounterparty = (string) ($notice['counterparty_account'] ?? '');
                if ($counterparty !== '' && $noticeCounterparty !== '' && !AccountNumberNormalizer::equals($counterparty, $noticeCounterparty)) continue;
                if (!empty($tx['counterparty_bank']) && !empty($notice['counterparty_bank']) && $tx['counterparty_bank'] !== $notice['counterparty_bank']) continue;
                if ($vs !== '' || $noticeVs !== '') {
                    if ($vs === '' || $vs !== $noticeVs) continue;
                    $reason = 'variable_symbol';
                } else {
                    if ($counterparty === '' || $noticeCounterparty === '') continue;
                    $reason = 'counterparty_account';
                }
                $pair = [
                    'index' => $index, 'notice_id' => (int) $notice['id'],
                    'posted_at' => $tx['posted_at'], 'notice_date' => $notice['posted_at'],
                    'amount' => (float) $tx['amount'], 'currency' => $account['code'],
                    'variable_symbol' => $tx['variable_symbol'] ?? null,
                    'counterparty' => $tx['counterparty_name'] ?? null,
                    'counterparty_account' => $counterparty, 'reason' => $reason,
                    'ignore_note' => $notice['ignore_note'],
                ];
                $possible[$index][] = $pair;
                $uses[$notice['id']] = ($uses[$notice['id']] ?? 0) + 1;
            }
        }
        $result = [];
        foreach ($possible as $index => $pairs) {
            if (count($pairs) === 1 && $uses[$pairs[0]['notice_id']] === 1) $result[$index] = $pairs[0];
        }
        return $result;
    }

    /** Oba zápisy probíhají ve stejné transakci jako vložení výpisu. */
    public function apply(int $targetId, array $pair, int $supplierId, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO bank_notice_ignore_transfers
            (notice_transaction_id, statement_transaction_id, supplier_id, user_id, ignore_note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$pair['notice_id'], $targetId, $supplierId, $userId, $pair['ignore_note']]);
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'ignored', ignore_origin = 'notice_transfer', ignore_note = ? WHERE id = ? AND match_status = 'unmatched'")
            ->execute([$pair['ignore_note'], $targetId]);
    }
}
