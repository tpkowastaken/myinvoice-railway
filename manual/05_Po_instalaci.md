# 5. Po instalaci a CLI nástroje

Ať jsi instaloval přes [Docker](03_Instalace_Docker.md) nebo
[nativně](04_Instalace_Nativni.md), poslední krok je stejný: otevři aplikaci
v prohlížeči a projdi úvodním průvodcem. Tato kapitola navíc shrnuje CLI
nástroje a plánované úlohy (cron) pro běžnou údržbu.

## 5.1 První spuštění

Otevři aplikaci v prohlížeči (u Dockeru **http://localhost:8080**, u nativní
instalace URL podle web serveru) — naskočí **setup wizard**. Provede tě
založením prvního dodavatele, administrátorského účtu a základní konfigurace.
Detailní popis: [První spuštění (setup wizard)](06_Setup_wizard.md).

## 5.2 Co nastavit hned po prvním přihlášení

- **Dodavatel** — IČO/DIČ, adresa, logo, číslování faktur, bankovní účty
  (Nastavení → Můj dodavatel; detail viz [Nastavení](36_Nastaveni.md)).
- **Odchozí pošta (SMTP)** — aby fungovalo odesílání faktur a upomínek.
- **Daňové nastavení** — typ poplatníka, perioda DPH, kód FÚ (pokud jsi plátce;
  viz [Výkazy DPH](29_Vykazy_DPH.md)).
- **Zabezpečení** — 2FA, IP allowlist, role uživatelů (viz [Bezpečnost](39_Bezpecnost.md)).
- **Plánované úlohy (cron)** — zálohy, párování plateb, upomínky
  (viz [§ 5.5 Cron skripty](#55-cron-skripty)).

## 5.3 Produkční doporučení

- Nasazuj za **HTTPS** (u Dockeru reverse proxy — viz
  [§ 3.8 HTTPS / TLS terminace](03_Instalace_Docker.md#38-https-tls-terminace)).
- Zapni **zálohy** a ověř, že běží (Systém → Plánované úlohy).
- Pinuj konkrétní verzi image a sleduj [Aktualizace](40_Aktualizace.md).

## 5.4 CLI nástroje

```bash
php api/bin/migrate.php              # spustí pending migrace
php api/bin/migrate.php --status     # vypíše stav migrací
php api/bin/setup.php                # interaktivní úvodní zřízení
php api/bin/sample.php               # vygeneruje testovací data (po setupu)
php api/bin/reset.php                # smaže všechna user-data (vyžaduje "ANO")
php api/bin/recompute-stats.php      # přepočítá agregované statistiky
```

## 5.5 Cron skripty

V `cmd/` jsou připravené `.cmd` (Windows Task Scheduler) i `.sh` (Linux cron) wrappery:

| Skript | Doporučená frekvence |
|---|---|
| `cron-cleanup` | 1× denně 03:00 |
| `cron-backup` | 1× denně 02:00 |
| `cron-backup-pdf` | 1× denně 02:30 |
| `cron-backup-documents` | 1× denně 02:35 |
| `cron-bank-scan` | každých 30 min |
| `cron-bank-email-notices` | každých 30 min |
| `cron-send-reminders` | 1× denně 09:00, Po–Pá |

Detaily v `cmd/README.md`.

**Obsah záloh:** `cron-backup` vytvoří ZIP s databází. `cron-backup-pdf`
vytvoří ZIP `*-pdf-*.zip` s PDF faktur a výkazů práce, přílohami vystavených
faktur, převzatými originály z `invoices-imported` a originály přijatých faktur
(PDF, ISDOC, ISDOCX, XML a JSON). Pro obnovu dokladů uchovávejte databázový
ZIP i tento soubor. Archivy používají pevné cesty `storage/...` také při
externím úložišti a nastaveném `MYINVOICE_DATA_DIR`.

`cron-backup-documents` zálohuje samostatnou sekci Dokumenty (`storage/documents`),
nikoli originály faktur. Pokud tato sekce neobsahuje soubory, ZIP nevznikne.

**Šifrování záloh:** volitelné heslo `cron.backup.password` v `cfg.php`
zašifruje všechny tři typy ZIP záloh (DB dump, PDF dokladů, sekce Dokumenty)
algoritmem AES-256. Pro rozbalení použijte 7-Zip, WinRAR nebo `unzip -P` —
vestavěný Průzkumník Windows šifrované AES-256 archivy neumí otevřít. Šifruje
se obsah souborů, názvy souborů uvnitř archivu zůstávají čitelné. Pokud je
heslo nastavené a PHP šifrování nepodporuje (libzip < 1.2), záloha se záměrně
nevytvoří a úloha skončí chybou — nešifrovaná záloha by vznikla jen omylem.

> 💡 **Relativní cesty v `cfg.php`.** Cestové klíče (`cron.backup.output_dir`,
> `storage.*`, `logging.path`, archivy přijatých/importovaných dokladů, DKIM)
> zadané relativně (např. `storage/backup`) se ukotvují k **rootu aplikace**,
> ne k pracovnímu adresáři procesu. Záloha tak skončí na očekávaném místě
> i když cron běží pod Task Schedulerem nebo systémovým cronem s jiným
> aktuálním adresářem. Absolutní cesty (vč. `C:\…` a UNC `\\server`) i
> `MYINVOICE_DATA_DIR` zůstávají beze změny.

**Kontrola, že úlohy běží:** otevři v aplikaci **Systém → Plánované úlohy**.
Každý cron skript si zapisuje vlastní heartbeat do tabulky `cron_runs`
(start, konec, exit code, JSON report). Stránka ukazuje pro každou
doporučenou úlohu kdy naposled úspěšně proběhla, a pokud poslední běh
chybí nebo je starší než `max_age_hours` (typicky 36 h), je tu varování
**Stáří** / **Selhává** / **Neběželo**. Tím se odhalí "cron vůbec není
nastavený" i "cron běží, ale failuje" — bez ohledu na OS (crontab vs.
Task Scheduler vs. Docker host).
