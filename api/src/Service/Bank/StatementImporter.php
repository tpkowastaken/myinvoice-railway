<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Persist naparsovaného výpisu do DB (GPC nebo bank-specifický PDF parser — obojí
 * vrací stejný tvar `['header'=>..., 'transactions'=>...]`). Dedupe podle file_hash.
 */
final class StatementImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly GpcParser $parser,
        private readonly StatementMatcher $matcher,
        // Cross-source dedup GPC ← e-mailové avízo: převezme párování (i manuální/split)
        // z už spárované avízo-transakce místo dvojího párování téže platby.
        private readonly EmailNoticeReconciler $reconciler,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param ?int $currencyId Cílový měnový účet (currencies.id). Když je zadán, jeho
     *   měna + kód banky jsou AUTORITATIVNÍ a přebijí lookup podle čísla účtu. Nutné
     *   u víceměnových účtů se sdíleným číslem (Raiffeisenbank: CZK/EUR/USD = jedno
     *   číslo), kde GPC hlavička měnu nenese a z čísla účtu ji nelze odvodit (#167).
     *   Volající (BankStatementAction) ověřuje příslušnost k supplierovi i shodu
     *   čísla účtu; tady už jen načteme code/bank_code. NULL = dnešní chování
     *   (lookup podle account_number — folder scan, jednoznačný účet).
     *
     * @param ?array $ignoreDecision NULL = bez přenosu (scan), [] = náhled pro interaktivní upload.
     * @return array{statement_id:int, transactions:int, matched:int, duplicate:bool, ignored_transferred:int}
     */
    public function import(string $content, string $fileName, ?int $userId, ?int $currencyId = null, ?array $ignoreDecision = null): array
    {
        $parsed = $this->parser->parse($content);
        return $this->persist($parsed, $content, $fileName, $userId, $currencyId, 'gpc', $ignoreDecision);
    }

    /**
     * Persist výpis naparsovaný bank-specifickým PDF parserem (Creditas a další —
     * viz {@see \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry}). Stejná
     * dedupe/matcher/reconciler logika jako {@see import()}, ale zdrojové bajty jsou
     * PDF (uloží se do pdf_content, ne file_content — žádný GPC ekvivalent neexistuje).
     *
     * @param array{header:array,transactions:list<array>} $parsed
     */
    public function importParsedPdf(array $parsed, string $pdfBytes, string $fileName, ?int $userId, ?int $currencyId = null, ?array $ignoreDecision = null): array
    {
        return $this->persist($parsed, $pdfBytes, $fileName, $userId, $currencyId, 'pdf', $ignoreDecision);
    }

    /**
     * @param array{header:array,transactions:list<array>} $parsed
     * @param string $rawBytes Originální bajty souboru — hashují se pro dedup a ukládají
     *   se buď do file_content (source='gpc') nebo pdf_content (source='pdf').
     */
    private function persist(array $parsed, string $rawBytes, string $fileName, ?int $userId, ?int $currencyId, string $source, ?array $ignoreDecision = null): array
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            $result = $this->persistRows($parsed, $rawBytes, $fileName, $userId, $currencyId, $source, $ignoreDecision);
            if ($owns) $pdo->commit();
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        // Párování spravuje vlastní transakce; potvrzené ignorování už je atomicky uloženo.
        $matched = 0;
        foreach ($result['pending_ids'] ?? [] as $txId) {
            $takeover = $this->reconciler->takeOverFromEmailNotice($txId);
            if ($takeover !== null) { $matched++; continue; }
            $r = $this->matcher->match($txId);
            if (in_array($r['status'], ['auto_exact', 'auto_partial'], true)) $matched++;
        }
        unset($result['pending_ids']);
        if (!$result['duplicate']) {
            $pdo->prepare('UPDATE bank_statements SET matched_count = ? WHERE id = ?')->execute([$matched, $result['statement_id']]);
            $result['matched'] = $matched;
        }
        return $result;
    }

    private function persistRows(array $parsed, string $rawBytes, string $fileName, ?int $userId, ?int $currencyId, string $source, ?array $ignoreDecision): array
    {
        $hash = hash('sha256', $rawBytes);
        $pdo = $this->db->pdo();

        $transfer = new IgnoreNoticeTransfer($this->db);
        $transferAccount = null;
        if ($ignoreDecision !== null && $currencyId !== null) {
            $lock = $pdo->prepare('SELECT * FROM currencies WHERE id = ? FOR UPDATE');
            $lock->execute([$currencyId]);
            $transferAccount = $lock->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Dedupe
        $exists = $pdo->prepare('SELECT id FROM bank_statements WHERE file_hash = ?');
        $exists->execute([$hash]);
        $existingId = $exists->fetchColumn();
        if ($existingId !== false) {
            return ['statement_id' => (int) $existingId, 'transactions' => 0, 'matched' => 0, 'duplicate' => true, 'ignored_transferred' => 0];
        }

        $h = $parsed['header'];

        // GPC header (074) NEMÁ pole pro měnu — máme to jen v 075 transakcích
        // (pozice 118-122, ISO 4217 numeric). Odvodíme měnu výpisu v pořadí:
        //   1) Lookup do currencies podle account_number/IBAN — GPC výpis je vždy
        //      z JEDNOHO účtu (= jedna měna), takže měna registrovaného účtu je
        //      AUTORITATIVNÍ. Per-tx pole nelze upřednostnit: Fio ho dle své
        //      specifikace plní KONSTANTNĚ "0203" (CZK) i u EUR účtu (#109 —
        //      EUR výpis se pak zobrazil v Kč a kvůli currency guardu v matcheru
        //      se nikdy nespároval).
        //   2) Fallback (účet neregistrovaný): dominantní non-null currency
        //      z 075 transakcí (CREDITAS/KB plní reálný kód; původní Creditas
        //      bug report — EUR výpis s 00978 se zobrazoval jako CZK, protože
        //      bank_statements.currency zůstával NULL).
        //   3) Bez 1 i 2: NULL (UI fallback CZK).
        // Účet z currencies (autoritativní měna + kód banky). GPC header kód banky
        // nenese (na rozdíl od e-mailových avíz) → doplníme ho z konfigurovaného účtu,
        // ať jsou data normalizovaná napříč zdroji (jinak GPC výpis bank_code = NULL).
        // Explicitně zvolený měnový účet (#167) je autoritativní; jinak lookup podle čísla.
        $account = $currencyId !== null
            ? $this->loadCurrencyById($currencyId)
            : $this->lookupAccount($h['account_number']);
        $accountCurrency = $account['code'] ?? null;
        $accountBankCode = $account['bank_code'] ?? null;
        $statementCurrency = $accountCurrency
            ?? $this->detectStatementCurrency($parsed['transactions']);

        $selectedIgnores = $transferAccount !== null
            ? $transfer->select($parsed['transactions'], $transferAccount, $hash, $ignoreDecision)
            : [];

        // GPC: raw bajty jdou do file_content (zpětně stažitelný originál). PDF: do
        // pdf_content (existující sloupce z migrace 0052 — „Stáhnout PDF" tak funguje
        // bez jakékoli FE změny i pro tyto výpisy; file_content zůstává NULL, protože
        // žádný GPC ekvivalent neexistuje).
        $fileContent   = $source === 'gpc' ? $rawBytes : null;
        $pdfContent    = $source === 'pdf' ? $rawBytes : null;
        $pdfName       = $source === 'pdf' ? $fileName : null;
        $pdfHash       = $source === 'pdf' ? $hash : null;
        $pdfSize       = $source === 'pdf' ? strlen($rawBytes) : null;
        $pdfUploadedAt = $source === 'pdf' ? date('Y-m-d H:i:s') : null;

        $pdo->prepare(
            'INSERT INTO bank_statements
                 (source, file_name, file_hash, file_content, pdf_content, pdf_name, pdf_hash, pdf_size_bytes, pdf_uploaded_at,
                  account_number, bank_code, currency,
                  statement_number, statement_date,
                  prev_balance, curr_balance, credit_total, debit_total, transaction_count, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $source, $fileName, $hash, $fileContent, $pdfContent, $pdfName, $pdfHash, $pdfSize, $pdfUploadedAt,
            $h['account_number'], $accountBankCode, $statementCurrency,
            $h['statement_number'], $h['statement_date'],
            $h['prev_balance'], $h['curr_balance'], $h['credit_total'], $h['debit_total'],
            count($parsed['transactions']), $userId,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions
                 (statement_id, posted_at, amount, currency, variable_symbol, constant_symbol, specific_symbol,
                  counterparty_account, counterparty_bank, counterparty_name, description, bank_ref)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $pendingIds = [];
        foreach ($parsed['transactions'] as $index => $tx) {
            // Měna registrovaného účtu přebíjí i per-tx pole (#109): výpis je
            // jednoměnový a Fio do 075 píše konstantně CZK i u EUR účtu — per-tx
            // hodnota by rozbila currency guard v matcheru. Per-tx kód se použije
            // jen jako fallback, když účet není registrovaný (CREDITAS/KB ho
            // plní reálně) — aby se EUR transakce neztratila.
            $txCurrency = $accountCurrency ?? $tx['currency'] ?? $statementCurrency;
            $insertTx->execute([
                $statementId, $tx['posted_at'], $tx['amount'], $txCurrency,
                $tx['variable_symbol'], $tx['constant_symbol'], $tx['specific_symbol'],
                $tx['counterparty_account'], $tx['counterparty_bank'], $tx['counterparty_name'],
                $tx['description'], $tx['bank_ref'],
            ]);
            $txId = (int) $pdo->lastInsertId();

            if (isset($selectedIgnores[$index])) {
                $transfer->apply($txId, $selectedIgnores[$index], (int) $transferAccount['supplier_id'], $userId);
            } else {
                $pendingIds[] = $txId;
            }
        }

        return [
            'statement_id' => $statementId,
            'transactions' => count($parsed['transactions']),
            'matched'      => 0,
            'ignored_transferred' => count($selectedIgnores),
            'pending_ids' => $pendingIds,
            'duplicate'    => false,
        ];
    }

    /**
     * Dominantní currency z transakcí — vrátí ten kód, který se vyskytuje
     * nejčastěji (po vyřazení NULL). NULL pokud ani jedna transakce currency
     * nemá. Multi-currency výpisy jsou v praxi vzácné; když je víc kódů,
     * statement.currency dostane majoritní.
     *
     * @param list<array{currency?:?string}> $transactions
     */
    private function detectStatementCurrency(array $transactions): ?string
    {
        $counts = [];
        foreach ($transactions as $tx) {
            $c = $tx['currency'] ?? null;
            if (is_string($c) && $c !== '') {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
        }
        if ($counts === []) return null;
        arsort($counts);
        return (string) array_key_first($counts);
    }

    /**
     * Lookup currency podle account_number v `currencies` tabulce. Pro případy,
     * kdy banka nevyplňuje 075.currency (= per-tx detection selže) — vezmeme
     * měnu prvního nalezeného currencies řádku se stejným číslem účtu (napříč
     * tenanty; multi-supplier separace je doménou caller — StatementImporter
     * pracuje bez tenant kontextu, ale account_number je defakto unikátní).
     *
     * AccountNumberNormalizer::equals normalizuje leading zeros / dashes pro
     * porovnání (např. `0000000123456789` z GPC vs `123456789` z UI inputu).
     * Porovnává se i domácí část IBANu (#109) — cizoměnové účty bývají
     * evidované jen IBANem a bez toho EUR výpis spadl na CZK fallback.
     *
     * @return array{code:string, bank_code:?string}|null
     */
    private function lookupAccount(string $accountNumber): ?array
    {
        if ($accountNumber === '') return null;
        $stmt = $this->db->pdo()->query(
            'SELECT account_number, iban, code, bank_code FROM currencies
              WHERE account_number IS NOT NULL OR iban IS NOT NULL'
        );
        if ($stmt === false) return null;
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $iban = isset($row['iban']) && is_string($row['iban']) ? $row['iban'] : null;
            if (AccountNumberNormalizer::matchesAny($accountNumber, $row['account_number'] ?? null, $iban)) {
                $matches[] = [
                    'code'      => (string) $row['code'],
                    'bank_code' => isset($row['bank_code']) && (string) $row['bank_code'] !== '' ? (string) $row['bank_code'] : null,
                ];
            }
        }
        if ($matches === []) return null;

        // #206: víc účtů se stejným číslem účtu, lišících se kódem banky (příp. měnou),
        // nelze v NEINTERAKTIVNÍ cestě (folder scan / fallback měny) jednoznačně
        // rozlišit — GPC hlavička kód banky vlastního účtu nenese. Interaktivní upload
        // to řeší volbou účtu (BankStatementAction::resolveTargetCurrency → 409
        // ambiguous_account_currency). Tady jen zalogujeme varování a vezmeme první
        // shodu (zachování chování), ať se adresářový sken nezasekne.
        if (count($matches) > 1) {
            $variants = [];
            foreach ($matches as $m) {
                $variants[($m['bank_code'] ?? '?') . '/' . $m['code']] = true;
            }
            if (count($variants) > 1) {
                $this->logger->warning(
                    'Nejednoznačný účet při importu výpisu — použita první varianta. '
                    . 'U interaktivního uploadu zvolte cílový účet ručně.',
                    [
                        'account_number' => $accountNumber,
                        'match_count'    => count($matches),
                        'variants'       => array_keys($variants),
                        'used_bank_code' => $matches[0]['bank_code'] ?? '?',
                        'used_currency'  => $matches[0]['code'],
                    ]
                );
            }
        }
        return $matches[0];
    }

    /**
     * Měna + kód banky podle konkrétního currencies.id — pro víceměnové účty se
     * sdíleným číslem (#167), kde lookup podle account_number nestačí (vrátil by
     * první z N měnových variant). Příslušnost k supplierovi a shodu čísla účtu
     * ověřuje caller (BankStatementAction); tady už jen načteme řádek.
     *
     * @return array{code:string, bank_code:?string}|null
     */
    private function loadCurrencyById(int $currencyId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT code, bank_code FROM currencies WHERE id = ?');
        $stmt->execute([$currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return [
            'code'      => (string) $row['code'],
            'bank_code' => isset($row['bank_code']) && (string) $row['bank_code'] !== '' ? (string) $row['bank_code'] : null,
        ];
    }
}
