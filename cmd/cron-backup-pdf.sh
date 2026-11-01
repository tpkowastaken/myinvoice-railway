#!/usr/bin/env bash
# =============================================================================
#  cron-backup-pdf.sh — denní záloha PDF, příloh a originálů faktur
#  (invoices/, invoices-imported/, work-reports/, purchase-invoices/) do storage/backup/{dbname}-pdf-YYYY-MM-DD.zip
#  Frekvence: 1× denně, doporučeno 02:30 (po cron-backup, před cron-cleanup)
#  Retention: 30 denních + 12 měsíčních (1. v měsíci se zachová déle)
#
#  crontab:
#    30 2 * * *  /var/www/myinvoice.cz/cmd/cron-backup-pdf.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-backup-pdf.php" "$@" \
    >> "$LOG_DIR/backup-pdf-$(date +%Y-%m-%d).log" 2>&1
