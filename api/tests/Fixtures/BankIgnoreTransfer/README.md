# Ruční test přenosu ignorování z avíz

Výhradně syntetická data. Žádné e-maily se neposílají ani nestahují. Příprava
vytvoří samostatnou testovací firmu, účet a pět nespárovaných e-mailových avíz
včetně vazeb zpracovaných zpráv. Nezasahuje do existujících dokladů.

Z kořene repozitáře (stejné příkazy na Linuxu i Windows):

```sh
php api/bin/migrate.php --no-backfills
php tools/prepareBankIgnoreDemo.php --prepare
```

Skript vypíše název testovací firmy a adresu jejího avízo-výpisu. Jako admin
přepněte na tuto firmu. Pro běžného uživatele nejprve přidělte přístup k firmě.
Avíza jsou za září 2026, vlastní účet základního scénáře je `1000000005 / 0100`.

1. Otevřete připravený avízo-výpis. U všech pěti pohybů zvolte **Ignorovat**.
   U ALFA a BETA napište rozlišující poznámku; vzor je v popisu transakce.
2. V seznamu bankovních výpisů nahrajte přiložený `statement.gpc`.
3. Nabídka přenosu má obsahovat právě ALFA (−101 Kč, VS 910001) a BETA
   (−202 Kč, VS 910002). Nic není předvybráno. Dvě platby −303 Kč se stejným
   VS 910003 jsou víceznačné; karta −404 Kč nemá VS ani protiúčet; příchozí
   +101 Kč má opačné znaménko. Tyto řádky se nesmí nabídnout.
4. Nejprve dialog zavřete. Žádný nový výpis se nesmí uložit.
5. Nahrajte soubor znovu, vyberte pouze ALFA a potvrďte přenos.
6. Nový výpis má 6 řádků. ALFA je ignorovaná se zkopírovanou poznámkou;
   BETA a ostatní řádky zůstávají nespárované (testovací firma nemá faktury).
   Počet převzatých ignorování je 1, spárovaných 0. Avíza zůstanou ignorovaná.
7. Počáteční zůstatek je 10 000 Kč, konečný 8 788 Kč; přenos je nemění.
8. Stejný soubor znovu nahrajte: musí být rozpoznán jako duplicita.
9. U ALFA ve výpisu zrušte ignorování a opakujte upload: rozhodnutí se nesmí
   vrátit. Ani odstranění výpisu neuvolní spotřebované avízo k novému přenosu.

## Další scénáře

Každé další kolo připravte do nové testovací firmy a samostatného souboru:

```sh
php tools/prepareBankIgnoreDemo.php --prepare --run skip --output /tmp/ignore-skip.gpc
```

Na Windows použijte vlastní zapisovatelnou cestu místo `/tmp/ignore-skip.gpc`.
Odlišné `--run` vytvoří jiný syntetický vlastní účet s platným mod-11 prefixem
i jiný hash výpisu, aby se scénáře nemíchaly. Skript existující firmu nepřepíše.

- **Bez přenosu:** ignorujte avíza a při uploadu zvolte **Importovat bez přenosu**.
  Všech 6 řádků výpisu má zůstat nespárovaných.
- **Změna během náhledu:** v druhém okně zrušte ignorování ALFA nebo změňte její
  poznámku. Potvrzení starého výběru musí nabídnout nový náhled s prázdným výběrem.
- **Více souborů:** kombinujte GPC z různých kol; volba účtu a přenos se řeší
  jednotlivě pro každý soubor, zrušení přeskočí pouze aktuální soubor.

Nepoužívejte testovací firmu pro skutečné doklady. Příprava je záměrně ponechá
nespárovaná, aby šlo ručně projít krok ignorování, nikoli jen výsledek importu.
