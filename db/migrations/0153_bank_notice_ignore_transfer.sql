-- Původ ignorování odlišuje rozhodnutí uživatele od systémové deduplikace.
ALTER TABLE bank_transactions ADD COLUMN IF NOT EXISTS ignore_origin VARCHAR(20) NULL;

-- Starší ruční ignorování lze doložit auditní událostí, nikoli textem poznámky.
UPDATE bank_transactions bt
   SET ignore_origin = 'manual'
 WHERE bt.source = 'email_notice' AND bt.match_status = 'ignored' AND bt.ignore_origin IS NULL
   AND EXISTS (SELECT 1 FROM activity_log a
                WHERE a.entity_type = 'bank_transaction' AND a.entity_id = bt.id
                  AND a.action = 'bank.tx_ignore');

-- Auditní vazba zůstává i po smazání výpisu nebo zrušení ignorování.
-- Záměrně bez FK s kaskádou: jedno avízo se nesmí použít znovu.
CREATE TABLE IF NOT EXISTS bank_notice_ignore_transfers (
    notice_transaction_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    statement_transaction_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    ignore_note VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bank_ignore_transfer_target (statement_transaction_id)
);

-- supplier.id je od migrace 0115 INT UNSIGNED; srovná i instance s dřívějším TINYINT.
ALTER TABLE bank_notice_ignore_transfers MODIFY COLUMN supplier_id INT UNSIGNED NOT NULL;
