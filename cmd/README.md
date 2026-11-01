# cmd/ — provozní a deploy skripty

Adresář obsahuje **wrappery a operační skripty** pro plánované úlohy (cron),
Docker provoz a build frontendu. Pro každý skript jsou typicky **dvě varianty**:

- `*.sh` — Linux / macOS (bash)
- `*.cmd` nebo `*.ps1` — Windows (cmd nebo PowerShell)

Skripty samy detekují cestu k projektu (`PROJECT_ROOT`) podle umístění
samotného skriptu, takže jsou přenositelné mezi `C:\inetpub\wwwroot\…`,
`C:\work\…` (junction), `/var/www/…` na Linuxu apod.

## Přehled všech skriptů

### Cron — plánované úlohy

| Skript | Co dělá |
|---|---|
| `cron-cleanup.{cmd,sh}` | Čištění expirovaných session, starých logů, PDF cache, login_attempts |
| `cron-backup.{cmd,sh}` | mariadb-dump celé DB do `storage/backup/YYYY-MM-DD.zip`, retention 30 dní |
| `cron-backup-pdf.{cmd,sh}` | ZIP PDF, příloh a originálů faktur (`storage/invoices/`, `invoices-imported/`, `work-reports/`, `purchase-invoices/`) do `storage/backup/{dbname}-pdf-YYYY-MM-DD.zip`, stejná retention jako `cron-backup` |
| `cron-backup-documents.{cmd,sh}` | ZIP celé sekce Dokumenty (`storage/documents/`, všechny typy; vynechává `_thumbs`/`_jobs`) do `storage/backup/{dbname}-documents-YYYY-MM-DD.zip`, stejná retention; oddělené od `cron-backup-pdf` (ten Dokumenty nezahrnuje) |
| `cron-bank-scan.{cmd,sh}` | Auto-import nových GPC výpisů z `private/bank-incoming/` + matching plateb na faktury |
| `cron-bank-email-notices.{cmd,sh}` | IMAP polling bankovních e-mailových avíz, parsování plateb a matching na faktury (konfigurace v **Admin → Bankovní účty**) |
| `cron-send-reminders.{cmd,sh}` | Odeslání upomínkových e-mailů na faktury po splatnosti (`--days=N`, `--cooldown=N`, `--dry-run`) |
| `cron-send-approval-reminders.{cmd,sh}` | Upomínky zákazníkům, kteří neschválili výkaz víceprací (`--days=N`, `--dry-run`) |
| `cron-generate-recurring-invoices.{cmd,sh}` | Generování faktur ze šablon pravidelné fakturace; volitelné rovnou vystavení a odeslání klientovi (`--dry-run`) |
| `cron-version-check.{cmd,sh}` | Denní kontrola GitHub Releases API; cachuje poslední dostupnou verzi + release notes pro **Systém → Aktualizace** |

Všechny tři backup ZIPy (DB, PDF, Dokumenty) lze volitelně šifrovat heslem
`cron.backup.password` v `cfg.php` (AES-256). Rozbalení pak vyžaduje 7-Zip /
WinRAR / `unzip -P` — vestavěný Průzkumník Windows AES-256 archivy neotevře.
Pokud je heslo nastavené a PHP ext-zip AES nepodporuje (libzip < 1.2), záloha
se záměrně nevytvoří a úloha skončí chybou (vidět v **Systém → Plánované úlohy**).

### Údržba číselníků — spouští se ručně

| Skript | Co dělá |
|---|---|
| `download-okec.{cmd,sh}` | Aktualizuje snapshot číselníku ČINNOSTI (CZ-NACE / `c_okec`) v `api/resources/ciselniky/okec.txt` z rozhraní číselníků Daňového portálu. Proti němu jede kanonizace CZ-NACE v přiznání k DPH a našeptávač v **Nastavení → Daňové nastavení**. |

**Není to cron úloha** — číselník se mění zřídka (poslední velká změna: přechod
na NACE rev. 2.1 k 1. 1. 2026). Pouštěj ručně, `--dry-run` napřed jen porovná
a vypíše rozdíl; výsledek zkontroluj přes `git diff` a commitni. Zastaralý
snapshot nic neblokuje — kód mimo něj se uloží i odešle, jen s upozorněním.

### Docker — vývoj v kontejnerech

| Skript | Co dělá |
|---|---|
| `docker-build.{sh,ps1}` | `docker compose build app` — postaví image (default **alpine/nginx** z `Dockerfile.alpine`; volitelné `--no-cache`, `--pull`) |
| `docker-install.{sh,ps1}` | First-run setup: vygeneruje `.env` + `cfg.docker.php`, **preferuje GHCR pull** (lokální build jen s `--build` / `MYINVOICE_INSTALL_MODE=source`), `up -d`, počká na DB healthcheck, spustí migrace, vypíše URL setup wizardu |
| `docker-ghcr.{sh,ps1}` | One-click install **z pre-built image na GHCR** (`ghcr.io/radekhulan/myinvoice:latest` = alpine/nginx) — žádný local build. Stejně jako install vygeneruje `.env` + `cfg.docker.php`, místo `build` udělá `pull`, pak `up -d` + migrace |
| `docker-update.{sh,ps1}` | Update běžící instance — **detekuje režim z image běžícího kontejneru**: registry (`ghcr.io/...`) → `pull`, lokální build → `git pull` + rebuild; pak `up -d` + migrace + úklid dangling vrstev. Přebití `MYINVOICE_UPDATE_MODE=registry\|source`. (Existující Debian instalace se přechodem `:latest` na alpine zmigrují samy při příštím updatu — drop-in.) |
| `docker-prune-images.{sh,ps1}` | Detekuje a maže **obsolete** myinvoice image (nepoužívané kontejnerem ani compose) + dangling vrstvy. `--dry-run` / `-DryRun` jen vypíše. Chrání běžící i compose-referencované image |
| `docker-update-watcher.{sh,ps1}` | Host-side daemon (systemd unit / Scheduled Task) — sleduje flag soubor `storage/upgrade-requested.json` (zapisuje UI v **Systém → Aktualizace**) a spustí `docker-update`, výsledek do `upgrade-result.json` |

### Build / deploy / kvalita

| Skript | Co dělá |
|---|---|
| `publish.{sh,ps1}` | `cd web && pnpm install && pnpm build` — produkční build frontendu do `web/dist/` (před commitem nebo nasazením na produkční IIS / Apache) |
| `test.{sh,ps1}`    | `cd api && vendor/bin/phpunit` — spustí testovou sadu (94 testů, ~1 s). Lze passnout filter / testsuite (`cmd/test.sh --filter=GpcParser`) |

## Cron — doporučené frekvence

| Skript | Frekvence | Příklad času |
|---|---|---|
| `cron-cleanup` | 1× denně | 03:00 |
| `cron-backup` | 1× denně | 02:00 (před cleanupem) |
| `cron-backup-pdf` | 1× denně | 02:30 (po DB backupu) |
| `cron-backup-documents` | 1× denně | 02:35 (po PDF backupu) |
| `cron-bank-scan` | každých 15–30 minut | `*/30 * * * *` |
| `cron-bank-email-notices` | každých 30 minut | `*/30 * * * *` |
| `cron-send-reminders` | 1× denně (pracovní dny) | 09:00, Po–Pá |
| `cron-send-approval-reminders` | 1× denně (pracovní dny) | 09:15, Po–Pá |
| `cron-generate-recurring-invoices` | 1× denně | 06:30 |
| `cron-version-check` | 1× denně | 06:00 |

Logy se ukládají do `log/cron/<nazev>-YYYY-MM-DD.log`. Stav úloh sleduj
v admin/activity-log (každý cron sám zapíše záznam `cron.<nazev>`).

### Windows — Task Scheduler

```cmd
schtasks /create /tn "MyInvoice Cleanup"   /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-cleanup.cmd"        /sc daily /st 03:00 /ru SYSTEM
schtasks /create /tn "MyInvoice Backup"    /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-backup.cmd"         /sc daily /st 02:00 /ru SYSTEM
schtasks /create /tn "MyInvoice BackupPDF" /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-backup-pdf.cmd"     /sc daily /st 02:30 /ru SYSTEM
schtasks /create /tn "MyInvoice BackupDocs" /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-backup-documents.cmd" /sc daily /st 02:35 /ru SYSTEM
schtasks /create /tn "MyInvoice BankScan"  /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-bank-scan.cmd"      /sc minute /mo 30 /ru SYSTEM
schtasks /create /tn "MyInvoice BankEmailNotices" /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-bank-email-notices.cmd" /sc minute /mo 30 /ru SYSTEM
schtasks /create /tn "MyInvoice Reminders" /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-send-reminders.cmd" /sc weekly /d MON,TUE,WED,THU,FRI /st 09:00 /ru SYSTEM
schtasks /create /tn "MyInvoice ApprovalReminders" /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-send-approval-reminders.cmd" /sc weekly /d MON,TUE,WED,THU,FRI /st 09:15 /ru SYSTEM
schtasks /create /tn "MyInvoice Recurring"         /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-generate-recurring-invoices.cmd" /sc daily /st 06:30 /ru SYSTEM
schtasks /create /tn "MyInvoice VersionCheck"      /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-version-check.cmd"           /sc daily /st 06:00 /ru SYSTEM
```

> 💡 **Update watcher** (samostatný proces, ne plánovaná úloha) — kontroluje
> flag soubor `storage/upgrade-requested.json` a aplikuje upgrade z UI. Spouští
> se **At startup**, ne na cron:
>
> ```cmd
> schtasks /create /tn "MyInvoice UpdateWatcher" ^
>   /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\wwwroot\myinvoice.cz\cmd\docker-update-watcher.ps1" ^
>   /sc onstart /ru SYSTEM /rl HIGHEST
> ```

> ⚠️ PHP musí být v `PATH` účtu, pod kterým úloha běží (typicky `SYSTEM`
> nemá uživatelský PATH — ověř `where php` v cmd spuštěném jako SYSTEM přes
> `PsExec -s -i cmd`). Případně uprav `.cmd` skripty a doplň absolutní cestu
> k `php.exe`.
>
> ⚠️ `cron-backup` potřebuje `mariadb-dump` (nebo `mysqldump`). Skript zkouší
> `PATH` a běžné Windows lokace (`C:\Program Files\MariaDB*\bin`,
> `C:\inetpub\MariaDB\bin`, XAMPP, Laragon). Pokud máš binárku jinde, nastav
> v `cfg.php` (resp. `cfg.docker.php`) absolutní cestu:
> `'db' => ['dump_tool' => 'D:\\mariadb\\bin\\mariadb-dump.exe', ...]`.

### Linux — crontab

Edituj `crontab -e` (nebo `/etc/cron.d/myinvoice`):

```cron
# m  h  dom mon dow  command
  0  3  *   *   *    /var/www/myinvoice.cz/cmd/cron-cleanup.sh
  0  2  *   *   *    /var/www/myinvoice.cz/cmd/cron-backup.sh
 30  2  *   *   *    /var/www/myinvoice.cz/cmd/cron-backup-pdf.sh
 35  2  *   *   *    /var/www/myinvoice.cz/cmd/cron-backup-documents.sh
*/30 *  *   *   *    /var/www/myinvoice.cz/cmd/cron-bank-scan.sh
*/30 *  *   *   *    /var/www/myinvoice.cz/cmd/cron-bank-email-notices.sh
  0  9  *   *   1-5  /var/www/myinvoice.cz/cmd/cron-send-reminders.sh
 15  9  *   *   1-5  /var/www/myinvoice.cz/cmd/cron-send-approval-reminders.sh
 30  6  *   *   *    /var/www/myinvoice.cz/cmd/cron-generate-recurring-invoices.sh
  0  6  *   *   *    /var/www/myinvoice.cz/cmd/cron-version-check.sh
```

`*.sh` skripty musí být spustitelné: `chmod +x cmd/*.sh`.

> 💡 **Update watcher** (samostatný proces, ne crontab job) — sleduje flag
> soubor `storage/upgrade-requested.json` a aplikuje upgrade spuštěný
> z UI. Nejjednodušší instalace přes systemd:
>
> ```bash
> sudo tee /etc/systemd/system/myinvoice-update-watcher.service <<'EOF'
> [Unit]
> Description=MyInvoice update watcher
> After=docker.service
>
> [Service]
> Type=simple
> WorkingDirectory=/var/www/myinvoice.cz
> ExecStart=/var/www/myinvoice.cz/cmd/docker-update-watcher.sh
> Restart=always
>
> [Install]
> WantedBy=multi-user.target
> EOF
>
> sudo systemctl daemon-reload
> sudo systemctl enable --now myinvoice-update-watcher
> ```

### Manuální spuštění (debug)

Skripty jsou bezpečné spustit ručně. Pro `cron-send-reminders` je
k dispozici `--dry-run`:

```cmd
cmd\cron-send-reminders.cmd --dry-run
cmd\cron-send-reminders.cmd --days=5 --cooldown=14
```

## Docker

V rootu projektu je `Dockerfile` (multi-stage: node → composer → php:8.5-apache)
a `docker-compose.yml` se službami **app** + **db** (MariaDB 11) + volitelně
**redis** (profile).

### První spuštění

```bash
# Linux / macOS
cmd/docker-install.sh

# Windows PowerShell
.\cmd\docker-install.ps1
```

Skript je **idempotentní** — bezpečně se dá pustit znovu (existující `.env`
a `cfg.docker.php` přeskočí). Po dokončení běží aplikace na
**http://localhost:8080** a v prohlížeči naskočí setup wizard.

### Rebuild image

```bash
cmd/docker-build.sh --no-cache    # po změnách v Dockerfile / composer.json / pnpm-lock.yaml
cmd/docker-build.sh --pull        # pull nových verzí base images (php:8.5-apache, mariadb:11.8)
```

### One-click instalace z GHCR (bez local buildu)

Pokud nechceš stavět image lokálně (a `pnpm`/`composer` v hostu řešit
vůbec), použij `docker-ghcr` — stáhne pre-built multi-arch image z
[ghcr.io/radekhulan/myinvoice](https://github.com/radekhulan/myinvoice/pkgs/container/myinvoice)
a zbytek (random hesla, `cfg.docker.php`, `up -d`, migrace) je shodný
s `docker-install`:

```bash
# Linux / macOS
cmd/docker-ghcr.sh

# Windows PowerShell
.\cmd\docker-ghcr.ps1
```

Skript používá **`docker-compose.production.yml`** (image-only, žádný
`build:` block), takže další compose příkazy vyžadují flag `-f`:

```bash
docker compose -f docker-compose.production.yml logs -f app
docker compose -f docker-compose.production.yml pull          # update na novější tag
docker compose -f docker-compose.production.yml up -d
docker compose -f docker-compose.production.yml down          # stop (data persist)
```

> 💡 V produkci pinuj konkrétní verzi (`:1.7.0` místo `:latest`)
> editací `docker-compose.production.yml` před prvním `pull`.

Pro **update** běžícího GHCR deploye stačí `cmd/docker-update.sh`
(auto-detekuje registry mode = `pull` + `up -d` + migrace) — viz výše.

### Konfigurace přes `.env`

Vzniká při prvním spuštění install skriptu:

| Proměnná           | Default     | Význam                                                |
|--------------------|-------------|-------------------------------------------------------|
| `APP_PORT`         | `8080`      | Host port pro Apache                                  |
| `DB_PORT`          | `3307`      | Host port pro MariaDB (vázán jen na `127.0.0.1`)      |
| `DB_NAME`          | `myinvoice` | Název DB                                              |
| `DB_USER`          | `myinvoice` | App user                                              |
| `DB_PASSWORD`      | random      | Heslo app usera (28 znaků base64)                     |
| `DB_ROOT_PASSWORD` | random      | Heslo MariaDB roota                                   |

### Volitelný Redis

```bash
docker compose --profile redis up -d
```

a v `cfg.docker.php` nastavit `redis.enabled => true` (host už je `redis`).

### Daily ops

```bash
docker compose up -d                                       # start
docker compose down                                        # stop (data v named volumes přežijí)
docker compose down -v                                     # stop + WIPE volumes (zničí DB)
docker compose logs -f app                                 # live logs
docker compose exec app bash                               # shell do kontejneru
docker compose exec app php api/bin/migrate.php --status   # cli z hostu
```

### Cron uvnitř kontejneru

Oba image (Debian/Apache i alpine/nginx) mají **vestavěný cron** — crontab se
build-time generuje z `CronCatalog` (`tools/generateDockerCrontab.php`), takže
obsahuje všechny úlohy + frekvence z UI **Systém → Plánované úlohy**. Spouští ho
entrypoint při startu (default `MYINVOICE_ENABLE_CRON=1`; logy v
`${MYINVOICE_DATA_DIR}/log/cron`). Při více replikách app nastav v jedné běžící
`MYINVOICE_ENABLE_CRON=0`, jinak by úlohy běžely vícenásobně.

Vypnutí vestavěného cronu a spouštění z hosta (alternativa):

```cron
0 9 * * 1-5  docker compose -f /opt/myinvoice/docker-compose.yml exec -T app php api/bin/cron-send-reminders.php
```

## Build / deploy

### `publish.{sh,ps1}` — produkční build frontendu

Spusť před deploy na IIS / Apache (frontend assety v `web/dist/`):

```bash
# Linux / macOS
cmd/publish.sh

# Windows PowerShell
.\cmd\publish.ps1
```

Co dělá (3 kroky):

1. `cd web/`
2. `pnpm install` — synchronizuje `web/node_modules/` s `pnpm-lock.yaml`
3. `pnpm build` — Vite build do `web/dist/` (s production optimalizacemi,
   tree-shaking, minifikací)

> 💡 `web/dist/` je v `.gitignore` — produkce si build dělá sama (tj. po
> `git pull` na produkci spusť `cmd/publish.sh`). Alternativně lze build
> commitnout (vyžaduje úpravu `.gitignore`).

> ⚠️ Vyžaduje `pnpm` v PATH. Instalace: `npm install -g pnpm`.

### `test.{sh,ps1}` — PHPUnit testy

```bash
# Linux / macOS
cmd/test.sh                            # všechny testy
cmd/test.sh --testsuite=Unit           # jen unit
cmd/test.sh --filter=GpcParser         # jen testy s názvem obsahujícím "GpcParser"

# Windows PowerShell
.\cmd\test.ps1
.\cmd\test.ps1 --filter=InvoiceMath
```

Pokrývá: GpcParser, InvoiceMath, AccountNumberNormalizer, SupplierGuard,
TurnstileVerifier, SecretEncryption, TotpService, IpMatcher, varsymbol +
month-increment helpers, error catalog. Integration test: unauthenticated
access (smoke check že middleware blokuje bez session).

## Konvence

- **Návratový kód 0** = OK, **non-zero** = chyba (vhodné pro shell pipes
  i Task Scheduler trigger).
- **`set -euo pipefail`** ve všech `.sh` (strict mode — fail fast).
- **`$ErrorActionPreference = 'Stop'`** ve všech `.ps1`.
- **PROJECT_ROOT** vždy resolvuju z `dirname` skriptu — žádné absolutní cesty
  v kódu.
- **Žádný `cd $HOME`** — pracuje se relativně k umístění skriptu, ne CWD volajícího.
