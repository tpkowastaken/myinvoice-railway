# 29. Výkazy DPH (DPHDP3 + KH)

MyInvoice.cz generuje XML pro EPO portál MFČR:
- **DPH přiznání (DPHDP3)** — měsíční nebo kvartální
- **Kontrolní hlášení (DPHKH1)** — měsíčně nebo kvartálně (pro OSVČ/FO kvartální plátce DPH)

Související výkazy a exporty mají v manuálu vlastní kapitoly: [Kniha DPH](30_Kniha_DPH.md)
(interní žurnál), [Souhrnné hlášení](31_Souhrnne_hlaseni.md) (EU dodání B2B) a
[Hromadný export](34_Hromadny_export.md) (ZIP balíček pro účetní). Všechny najdeš v menu **Daně**.
OSS najdeš samostatně jako **Daně → OSS přiznání**; jde o kvartální podklad
z ručně označených řádků a XML export pro EPO formulář **OSSEI1** (EU režim).
Režim je ve výchozím stavu vypnutý — dokud ho v **Nastavení → firma** nezapneš,
nenabízí se ani v menu, ani na řádcích faktury.
Export obsahuje běžná plnění (`VetaR`) i opravy minulých období (`VetaO`).
Opravy se v editoru položky označují původním čtvrtletím a ve výkazu se
zobrazují odděleně včetně celkového dopadu na DPH.

> [!WARNING]
> **⚠️ Vygenerovaný XML je pouze pomůcka.** Před odesláním na EPO portál MFČR VŽDY ověř s účetní nebo daňovým poradcem. Aplikace nezaručuje regulatorní správnost — testováno na omezené sadě dat.

## Předpoklady před prvním podáním

V **Nastavení → Daňové nastavení** vyplň:

1. **Typ poplatníka** — FO (OSVČ) nebo PO (s.r.o., a.s.)
2. **Perioda DPH přiznání** — Měsíční nebo Kvartální
3. **Kód finančního úřadu** (např. 451 = Praha 1) — povinné
4. **DIČ** v Identifikaci firmy — povinné
5. **Kód územního pracoviště (ÚzP)** — doporučené; najdeš na mojedane.gov.cz nebo v hlavičce dřívějšího podání jako `c_pracufo` (např. 2005 = ÚzP pro Prahu 5)
6. **E-mail** v Identifikaci firmy — doporučený kontakt pro FÚ
7. U právnické osoby **oprávněná osoba** (jméno, příjmení, postavení — typicky jednatel) — doporučené
8. Volitelně: telefon, CZ-NACE, datová schránka, sestavitel přiznání
9. Volitelně pro OSS: OSS režim, země identifikace a měna podání

Detailní mapping všech polí v UI na XML atributy najdeš v sekci [Pole EPO / VetaP](#pole-epo-vetap) níže.

> [!IMPORTANT]
> **Bez povinných polí XML nestáhneš.** Aplikace před sestavením výkazu
> kontroluje identifikaci daňového subjektu. Tvrdě blokují jen pole, která mají
> v EPO schématu `use="required"` — **kód FÚ, DIČ a typ poplatníka**; bez nich
> by podání neprošlo ani validací schématu, takže stažení XML vrátí chybu
> s výčtem chybějících polí a odkazem do nastavení. Dřív se vygenerovalo validně
> vypadající XML, které EPO odmítl až při podání, typicky v den lhůty.
>
> **Náhled výkazu se nikdy neblokuje** — čísla vidíš i s neúplným nastavením
> (třeba když je opisuješ do formuláře na portálu), jen se nad náhledem
> vypíše, co doplnit. Pole, která jsou ve schématu volitelná (ÚzP, e-mail,
> oprávněná osoba u PO), a dále telefon či CZ-NACE generování neblokují — jen
> se zobrazí upozornění. Sekce Daňové nastavení má v záhlaví štítek shrnující
> stav (červený, když chybí povinné pole; oranžový, když jen doporučené)
> a u svých polí hint — červený u povinných, oranžový u doporučených. DIČ,
> e-mail a telefon žijí v sekci Identifikace firmy, takže se hlásí jen tím
> štítkem a výpisem nad náhledem výkazu.

> [!NOTE]
> **Právnické osoby (PO/s.r.o./a.s.) podávají Kontrolní hlášení VŽDY měsíčně** (§ 101e odst. 1 ZDPH).
> OSVČ (FO) mohou podávat KH ve stejné lhůtě jako přiznání k DPH — tj. **kvartálně**, pokud jsou kvartálním plátcem (§ 101e odst. 2).
> Přepínač Měsíčně / Kvartálně se v `Daně → Kontrolní hlášení` zobrazí jen FO.

## Pole EPO / VetaP

Tato sekce mapuje pole z **Nastavení → Daňové nastavení** (admin only) na konkrétní
atributy v EPO XML (DPHDP3 + DPHKH1). Vyplň je všechny — bez nich EPO portál podání
odmítne nebo bude generovat formálně neúplný výkaz.

### Identifikace finančního úřadu

| Pole v UI | XML atribut | Popis | Jak zjistit |
|---|---|---|---|
| **Kód finančního úřadu** | `c_ufo` | Číselný kód územního finančního orgánu | např. `451` Praha 1, `463` Jihomoravský kraj. Najdeš na posledním podaném přiznání nebo v EPO. |
| **Kód územního pracoviště** | `c_pracufo` | Konkrétní pracoviště v rámci FÚ | např. `3203` pracoviště Brno III. Volitelné, ale EPO ho někdy vyžaduje. |
| **CZ-NACE kód (`cz_nace_code`)** | `c_okec` | Hlavní podnikatelská činnost (NACE) | Vybírá se **našeptávačem** nad číselníkem ČINNOSTI Daňového portálu ([rozhraní číselníků](https://adisspr.mfcr.cz/pmd/dokumentace/ciselniky)) — hledej podle názvu činnosti („reklamní") nebo podle kódu (`73`, `73.11`, u sekcí 01–09 i `01.48`). Nabízejí se **jen kódy platné k dnešku**; uloží se kanonická podoba číselníku (`731100`, u sekcí 01–09 bez vodicí nuly, tedy `14800` = 01.48.00). Kód mimo číselník lze zvolit taky (poslední položka nabídky) — uloží se s upozorněním. Pozor: číselník přešel k 1. 1. 2026 na NACE rev. 2.1, takže klasifikace převzatá z ARES může být expirovaná (např. `620200`, dnes `622000`) — pole to hlásí hned při otevření Nastavení. Prázdné/neúplné → atribut se vynechá a EPO nahlásí propustnou chybu 30. |

### Typ plátce a perioda

| Pole v UI | XML atribut | Hodnoty | Kdy použít |
|---|---|---|---|
| **Typ poplatníka** | `typ_ds` ve VetaP | `F` (FO/OSVČ) / `P` (PO/s.r.o.) | Podle právní formy. |
| **Typ plátce DPH** | `typ_platce` ve VetaD | `P` (plátce) / `I` (identifikovaná osoba) | `I` se nastaví automaticky, když je v dodavateli zaškrtnutá **Identifikovaná osoba** (viz [§ 28.1.1](28_Fakturujeme.md#2811-identifikovana-osoba-6g6l-zdph)). Perioda (měsíc/kvartál) jde zvlášť atributy `mesic`/`ctvrt` dle `vat_period`. |

> 🛈 **Identifikovaná osoba**: přiznání obsahuje jen řádky samovyměření
> z přeshraničních přijatých plnění (ř. 3–6, 12–13) **bez zrcadlového odpočtu
> ř. 43** (IO nemá nárok na odpočet — daň se reálně platí, ř. 64). Podává se
> **vždy měsíčně** a jen za měsíce, kdy povinnost vznikla; tuzemské řádky
> a oddíl C se automaticky vynechají (s upozorněním v náhledu). Kontrolní
> hlášení IO nepodává; služby do EU vykazuje v souhrnném hlášení.

### Sídlo / adresa

EPO rozděluje uliční adresu na tři samostatné atributy (`ulice` + `c_pop` + `c_orient`).
Naše DB tyto sloupce drží separátně (`supplier.street`, `street_number_pop`,
`street_number_orient`):

| Pole v UI | XML atribut | Popis |
|---|---|---|
| **Ulice** (`street`) | `ulice` | Název ulice bez čísla, např. `Vodičkova` |
| **Číslo popisné** (`street_number_pop`) | `c_pop` | Popisné číslo budovy, např. `1104` |
| **Číslo orientační** (`street_number_orient`) | `c_orient` | Orientační číslo, např. `36` |
| **Město** (`city`) | `naz_obce` | Posílá se beze změny; velikost písmen si normalizuje EPO samo |
| **PSČ** (`zip`) | `psc` | Bez mezer, builder odstraní |
| **Země** (`country_id` → ISO) | `stat` | Defaultně `CZE` (Česká republika) |

> [!IMPORTANT]
> **Pro OSVČ:** EPO vyžaduje **adresu sídla podnikání**, nikoli trvalého bydliště,
> pokud jsou různé. Najdeš v živnostenském rejstříku / ARES jako *„Místo podnikání"*.

### Osobní údaje (jen pro FO/OSVČ)

| Pole v UI | XML atribut | Popis |
|---|---|---|
| **Titul** | `titul` | Před jménem (Bc., Ing., Mgr., …) — nepovinné |
| **Jméno** | `jmeno` | Křestní jméno plátce |
| **Příjmení** | `prijmeni` | Příjmení plátce |

PO (právnické osoby) tyto pole nevyplňují — místo nich se použije `zkrobchjm` z firmy.

### Oprávněná osoba k podpisu — doporučené pro PO

Pole `opr_*` identifikují fyzickou osobu, která je u právnické osoby oprávněná
přiznání podepsat (typicky jednatel, předseda představenstva).

| Pole v UI | XML atribut | Popis |
|---|---|---|
| **Jméno oprávněné osoby** (`opr_jmeno`) | `opr_jmeno` | Křestní jméno jednatele / podepisujícího |
| **Příjmení oprávněné osoby** (`opr_prijmeni`) | `opr_prijmeni` | Příjmení |
| **Postavení** (`opr_postaveni`) | `opr_postaveni` | Funkce, typicky `jednatel`, `majitel`, `předseda představenstva` |

U FO (OSVČ) zůstávají prázdná — fallback je `jmeno` + `prijmeni`.

V EPO schématech jsou atributy `opr_*` deklarované jako `use="optional"`, takže
generování XML neblokují — aplikace jen upozorní, že je u PO nemáš vyplněné.
Finanční úřad je ale u právnických osob fakticky očekává, tak je doplň.

### Sestavitel přiznání (sest_*)

Pole sestavitele jsou relevantní jen pokud **přiznání za tebe podává jiná osoba**
(účetní, daňový poradce). Pokud podáváš sám, nech prázdná — builder použije tvoje
údaje (fallback na `jmeno` + `prijmeni` + `phone`).

| Pole v UI | XML atribut | Popis |
|---|---|---|
| **Jméno sestavitele** (`sest_jmeno`) | `sest_jmeno` | Křestní jméno sestavitele |
| **Příjmení sestavitele** (`sest_prijmeni`) | `sest_prijmeni` | Příjmení |
| **Telefon sestavitele** (`sest_telefon`) | `sest_telef` | Ve formátu `+420XXXXXXXXX` |
| **E-mail sestavitele** (`sest_email`) | (interní log) | Pro audit — EPO XML ho neukládá |
| **Funkce / role** (`sest_funkce`) | (interní log) | Volně psané, např. `účetní`, `daňový poradce` |

> Pokud necháš **Příjmení sestavitele** prázdné a do Jména napíšeš celé jméno
> („Jan Novák"), builder ho do XML rozdělí podle první mezery (zpětná
> kompatibilita). Pro spolehlivost ale vyplň obě pole zvlášť.

### Kontaktní údaje pro podání

| Pole v UI | XML atribut | Popis |
|---|---|---|
| **E-mail** (`email`) | `email` | Kontakt pro FÚ |
| **Telefon** (`phone`) | `c_telef` | Ve formátu `+420XXXXXXXXX` |

### Postup podání na EPO portál

1. **Vygeneruj XML** v aplikaci: `Daně → DPH přiznání` (resp. KH/SH), vyber období,
   klikni **Stáhnout XML**.
2. **Zkontroluj v textovém editoru**:
   - **VetaD** — ověř `rok`, `mesic`/`ctvrt`, `typ_platce`, `c_okec`, `d_poddp`
     (datum podání = dnes)
   - **VetaP** — ověř `dic`, `c_ufo`, `c_pracufo`, identifikační údaje, adresu
   - **Veta1/Veta4** — ověř součty `obrat23`/`dan23` (sales), `pln23`/`odp_tuz23_nar`
     (purchase) proti seznamu faktur za období
   - **Veta6** — `dano_da` (daň k odvodu) nebo `dano_no` (nadměrný odpočet)
3. **Přihlas se na [https://adisspr.mfcr.cz/dpr/epo](https://adisspr.mfcr.cz/dpr/epo)** (EPO portál MFČR).
4. Zvol **DPH přiznání → Nové podání → Nahrát soubor**.
5. **Nahraj XML** — portál ho zvaliduje vůči XSD a zobrazí náhled. Validace zachytí
   strukturální chyby (chybějící povinné atributy, špatný formát data, ...).
6. Pokud validace projde, potvrď **Odeslat**.
7. **Stáhni si potvrzení** (PDF nebo e-mail). To je tvůj doklad o podání.

> [!TIP]
> XML soubor lze ručně doupravit v textovém editoru — struktura musí zůstat
> zachovaná, ale hodnoty atributů můžeš editovat. Užitečné pro hotfix bez
> přepočtu celé databáze.

### Časté problémy

**EPO odmítne soubor s chybou „neúplná adresa"**
→ Vyplň `street_number_pop` + `street_number_orient` v Daňovém nastavení.
Pole `street` se ukládá samostatně, EPO chce všechny tři atributy.

**„Chybí kód finančního úřadu"** warning v náhledu
→ Vyplň `financial_office_code` v Daňovém nastavení. Bez něj XML neprojde XSD
validací (`c_ufo` je `use="required"`).

**„Tenant není evidovaný jako plátce DPH"**
→ V Identifikaci firmy zapni `is_vat_payer = true`. Vyplň DIČ. Pokud jsi
**identifikovaná osoba**, nech plátce vypnutého a zaškrtni `Identifikovaná
osoba` — přiznání se pak generuje s `typ_platce='I'`.

**Čísla v Veta1/Veta4 nesedí**
→ Zkontroluj **VAT klasifikační kódy** na položkách faktur za období. Každý řádek
musí mít `vat_classification_code` (1/2 pro sales 21/12 %, 40/41 pro purchase,
23 pro EU pořízení zboží, 5 pro tuzemský RC, atd.). Auto-defaulter to dělá při
vytvoření faktury — pro starší / importovaná data můžeš spustit backfill v
`Daně → DPH přiznání → topbar tlačítko **Přemapovat klasifikace**`.

**„Aplikace generuje `typ_platce='P'`, ale jsem čtvrtletní plátce"**
→ V Daňovém nastavení změň `vat_period` na `quarterly`. Pak v UI DPH přiznání
toggluj na **Kvartálně** a vyber kvartál.

**„Nevím, jaký je můj kód FÚ a pracoviště"**
→ Podívej se na poslední DPH přiznání, které jsi nahrál na EPO — kódy jsou v
sekci VetaD/VetaP. Alternativně zavolej na svůj FÚ nebo se podívej na
[seznam FÚ](https://www.financnisprava.cz/cs/financni-sprava/organy-financni-spravy/uzemni-pracoviste).

**„EPO hlásí propustnou chybu 30 — Hlavní ekonomická činnost neodpovídá číselníku"**
→ Otevři **Nastavení → Daňové nastavení → CZ-NACE klasifikace** a vyber činnost
z našeptávače (nabízí jen kódy platné k dnešku, hledá i podle názvu). Dvě časté
příčiny chyby 30: **(1)** dvoumístný oddíl převzatý z ARES (např. `74`) —
číselník ho nezná, aplikace ho neuloží a do XML nepropíše; **(2)** kód platný
do 31. 12. 2025, který přechodem číselníku na NACE rev. 2.1 expiroval
(např. `620200` → dnes `622000`) — aplikace ho označí upozorněním s datem konce
platnosti hned u pole, takže stačí vybrat nástupce ze seznamu.

> 🛈 Číselník je v aplikaci uložený jako snapshot (`api/resources/ciselniky/okec.txt`).
> Zastaralý snapshot nic neblokuje (kód mimo něj se uloží i odešle, jen s upozorněním);
> aktualizuje se skriptem `cmd/download-okec.{cmd,sh}` — stáhne aktuální číselník
> z Daňového portálu, ověří formát a soubor přepíše.

**„EPO hlásí propustnou chybu 49 na ř. 40/41 — daň neodpovídá základu"**
→ Nejde o chybu dat: EPO si daň dopočítává ze zaokrouhleného základu, zatímco
přiznání nese **součet daně z jednotlivých dokladů** (haléřové rozdíly per
doklad). Hodnota z dokladů je správná a odpovídá sekci B.2/B.3 kontrolního
hlášení — **neupravuj ji**. Náhled přiznání rozdíl dopředu vypíše ve varováních.

## DPH přiznání (DPHDP3)

### Cesta: `Daně → DPH přiznání`

#### Topbar

- **Toggle Měsíčně / Kvartálně** — override podle `supplier.vat_period`
- **Month / Year picker** — pro měsíční; **Q1/Q2/Q3/Q4 picker** pro kvartální
- **Forma podání** — Řádné (výchozí) / Opravné (§ 138 DŘ — nahrazuje řádné
  před uplynutím lhůty). **Datum zjištění** důvodů je u opravného volitelné —
  do XML se propíše jako `dapdph_forma` + `d_zjist`.
  **Dodatečné přiznání (§ 141 DŘ) aplikace zatím negeneruje:** podává se pouze
  v **rozdílech** proti poslední známé dani (§ 141 odst. 2 DŘ) a dopočet
  rozdílů není implementován — plné hodnoty za období by byly věcně špatně
  (v neprospěch poplatníka). Pro dodatečné přiznání použij formulář na portálu
  EPO / MOJE daně, hodnoty rozdílů spočítej z porovnání s posledním podaným
  přiznáním.
- **Stáhnout XML** — generuje DPHDP3 verze 03.01 pro EPO portál

#### 4 KPI karty

- **DPH na výstupu** — z vydaných faktur (řádky 1-29)
- **DPH na vstupu** — z přijatých faktur (řádky 40+)
- **Daň k odvodu** NEBO **Nadměrný odpočet** (color coded)
- **Termín podání** — 25. den následujícího měsíce (po kvartálu) s **countdown**
  (kolik dní zbývá, červené pokud po termínu). Připadne-li 25. na sobotu, neděli
  nebo státní svátek (vč. Velikonoc), termín se dle **§ 33 odst. 4 daňového řádu**
  posouvá na nejbližší následující pracovní den — např. 25. 7. 2026 (sobota)
  → pondělí 27. 7. 2026.

#### Trend graf

12 měsíců DPH na výstupu / vstupu / net due (rozdíl). Pro rychlou orientaci, jak se podání vyvíjí.

#### Tabulky DPH na výstupu (řádky 1-29) a vstupu (40+)

Per řádek: kód, popis, základ, DPH. Hodnoty se počítají agregací `invoice_items` / `purchase_invoice_items` per `vat_classification_code`.

### Jak se DPHDP3 generuje a co zahrnuje

Tato sekce přesně popisuje, podle jakých pravidel se přiznání sestavuje — užitečné
pro kontrolu proti seznamu faktur i pro účetní.

#### Zdroje dat a granularita

- **DPH na výstupu (ř. 1-26)** se počítá z **vystavených faktur** (`invoices`).
- **DPH na vstupu / nárok na odpočet (ř. 40-47)** z **přijatých faktur**
  (`purchase_invoices`).
- **Samovyměřená daň** u reverse charge a pořízení z EU se objevuje na **obou
  stranách** (výstup ř. 3-13 + odpočet ř. 43).
- Agreguje se **per řádek faktury** (`*_items`), ne per faktura — kvůli kurzu cizí
  měny a možnosti per-řádek klasifikace.

#### Které doklady se zahrnou

| Filtr | Pravidlo |
|---|---|
| **Období** | **Vystavené** se řadí podle **DUZP** (`COALESCE(tax_date, issue_date)`) — daň na výstupu vzniká k datu plnění. **Přijaté tuzemské** se řadí podle **pozdějšího z dat DUZP / vystavení** — nárok na odpočet nelze uplatnit dříve, než plátce drží daňový doklad (§ 73 odst. 1 písm. a ZDPH), takže faktura se zpětným DUZP, ale vystavená v pozdějším měsíci, spadá do měsíce vystavení. **Přijaté zahraniční reverse charge** (příznak RC + dodavatel mimo CZ — pořízení zboží z JČS, služby z EU/3. země, dovoz) se řadí **podle DUZP** — povinnost přiznat daň (ř. 3–13) vzniká k DUZP bez ohledu na to, kdy doklad dorazil (§ 25 odst. 1, § 24), a pozdní doklad neblokuje ani zrcadlový odpočet ř. 43 (§ 73 odst. 1 písm. b — nárok lze prokázat jiným způsobem). Tuzemský RC (kód 5) zůstává konzervativně na pozdějším z dat. (Zobrazené *Datum plnění* dál nese skutečné DUZP, mění se jen příslušnost k období.) Doklad bez vyplněného DUZP nevypadne. |
| **Stav** | Vylučují se `draft` a `cancelled`. U vystavených navíc `proforma`, u přijatých `advance` (zálohová výzva není daňový doklad). |
| **Dobropisy** | Přijatý dobropis (`document_kind='credit_note'`) snižuje nárok na odpočet (ř. 40/41) — do výpočtu vstupuje vždy záporně, bez ohledu na znaménko uložení. Vydaný dobropis (uložen záporně) snižuje daň na výstupu (ř. 1/2) — viz i § 42 upozornění v náhledu. |
| **Klasifikace** | Řádek se zařadí podle `vat_classification_code` (item-level override → header → auto-default podle sazby + RC + směru). Řádek bez výsledného kódu se do přiznání nedostane. |

#### Přepočet měny

Základ i daň se vždy převedou na **CZK** kurzem faktury (`exchange_rate`); u CZK
faktur je kurz 1. Přiznání je vždy v korunách, částky se zaokrouhlují na celé Kč.

#### Mapování na řádky přiznání

| Řádek | Co obsahuje | Typický kód |
|---|---|---|
| **1 / 2** | Tuzemská zdanitelná plnění na výstupu 21 % / 12 % | 1 / 2 |
| **3 / 4** | Pořízení zboží z JČS (samovyměření) 21 % / 12 % | 23 |
| **5 / 6** | Přijetí služby z EU (§ 9 odst. 1) | 24e |
| **7 / 8** | Dovoz zboží ze 3. země | 25 |
| **10 / 11** | Tuzemský reverse charge (příjemce) | 5 |
| **12 / 13** | Přijetí služby ze 3. země / od osoby neusazené v tuzemsku | 24 |
| **20-26** (oddíl C) | Dodání zboží do EU, vývoz, služby do JČS — **osvobozená plnění s nárokem na odpočet, jen základ bez daně** | 20 / 22 / 26 |
| **40 / 41** | Nárok na odpočet — tuzemsko 21 % / 12 % | 40 / 41 |
| **43** | Nárok na odpočet u samovyměřené daně (zrcadlo ř. 3-13) | (secondary) |
| **47** | Hodnota pořízeného dlouhodobého majetku — **doplňující údaj** k ř. 40-45 | flag majetek |

> [!NOTE]
> **Oddíl C (ř. 20-26)** — dodání do EU (`dod_zb`), vývoz (`pln_vyvoz`), služby do
> JČS (`pln_sluzby`) a další — se generuje do elementu `Veta2`. Jde o osvobozená
> plnění, na DPHDP3 se uvádí **jen základ** (žádná daň), ale ovlivňují vypořádací
> koeficient (ř. 51-53).

#### Samovyměření daně u reverse charge

U reverse charge (faktura s `reverse_charge=1` **nebo** klasifikační kód s příznakem
`is_reverse_charge` — kódy 5 a 23) vendor fakturuje **bez DPH**. Aplikace daň
**dopočítá** ze základu: `daň_CZK = základ_CZK × sazba / 100`. Tatáž částka se uvede
dvakrát:
- na **výstupu** (ř. 3 u zboží z EU, ř. 10 u tuzemského RC, ř. 5/12 u služeb),
- na **vstupu** jako odpočet na **ř. 43** (přes `dphdp3_line_secondary`).

Net dopad na vlastní daň je tedy nulový (daň = odpočet), pokud máš plný nárok.

#### Vlastní daň vs. nadměrný odpočet

`vlastní daň = DPH na výstupu − nárok na odpočet`. Kladná hodnota = daň k úhradě FÚ;
záporná = nadměrný odpočet. Atribut `trans` ve `VetaD` se nastaví `A` (vznikla
povinnost) / `N` podle znaménka.

### Jak fungují VAT klasifikační kódy

Každá faktura (nebo její řádek) má `vat_classification_code` (např. "1", "40", "5", "20"). Tento kód určuje na který **řádek DPH přiznání** položka patří.

**Standardní kódy (CZ, 2025-2026):**

| Vystavené (sale) | Přijaté (purchase) |
|---|---|
| **1** — Tuzemsko 21% (řádek 1 DPHDP3) | **40** — Tuzemsko 21% s odpočtem |
| **2** — Tuzemsko 12% (řádek 2) | **41** — Tuzemsko 12% s odpočtem |
| **3** — Osvobozeno (řádek 3) | **42** — Bez nároku na odpočet |
| **20** — EU dodání zboží (řádek 20) | **5** — Tuzemský reverse charge (řádek 10) |
| **22** — EU služby | **23** — EU acquisition zboží (řádek 3) |
| **26** — Export do 3. země | **24e** — Přijatá služba z EU (řádek 5) |
| | **24** — Přijatá služba ze 3. země (řádek 12) |
| | **25** — Dovoz zboží ze 3. země (řádek 7) |

### Auto-default klasifikace

Pokud na fakturu/řádek manuálně nevybereš kód, systém **automaticky přiřadí** podle:
- VAT sazby na řádku (`vat_rate_snapshot`)
- Reverse charge flagu na faktuře
- Direction (sale → vystavené kódy, purchase → přijaté kódy)
- Tax date faktury (pro budoucí změny sazby)

Mapování čte z databáze `vat_classifications` table. Pokud admin v Codebooks tabu **Klasifikace DPH** upraví sazbu (např. 21% → 20% k 1.1.2027), defaulter automaticky chytne novou hodnotu.

U **zahraničního reverse charge** bez výslovné klasifikace se auto-default řídí
zemí dodavatele: **EU → 24e** (služba § 9/1, ř. 5), **3. země → 24** (ř. 12),
tuzemsko → 5 (ř. 10). Zboží od služby ale z dat spolehlivě rozlišit nejde —
default předpokládá **službu** a náhled Kontrolního hlášení u takového dokladu
zobrazí adresné upozornění. Jde-li o **pořízení zboží z EU**, zvol ručně kód
**23** (ř. 3); u **dovozu zboží ze 3. země** kód **25** (ř. 7).

Vybereš-li na položce **výslovně** klasifikaci v režimu přenesené povinnosti
(24e/23/24/25/5…), aplikace při uložení dokladu **automaticky zapne příznak
reverse charge na hlavičce** a upozorní na to — hlavička a položky by si jinak
odporovaly a doklad by se mohl zařadit do špatného období. Platí to pro uložení
z editoru i pro API (POST/PUT přijaté faktury).

Naopak **importy** (ISDOC, iDoklad, Fakturoid, AI extrakce) příznak nemění:
tam klasifikace na položkách vzniká automaticky (zahraniční dodavatel s 0 %
dostane 24e/24) a přepisovat kvůli tomu příznak, který zdrojový systém uvedl,
by bylo tiché přepisování dat. Výkazy s tím počítají — zařazení do období
i samovyměření reagují na příznak **nebo** na klasifikační kód položky.

Pozor na jednu hranici: jakmile takový importovaný doklad otevřeš v editoru
a uložíš, kódy uložené na položkách se odešlou jako tvoje volba, takže se
příznak zapne (s upozorněním). Chceš-li u dokladu příznak trvale vypnutý,
zvol na položkách klasifikaci mimo režim přenesené povinnosti.

Historická data s rozporem srovná skript
`php api/bin/backfill-reverse-charge-consistency.php` (výchozí režim dry-run,
zápis až s `--apply`) — ten ale kód zadaný ručně od defaultovaného nerozliší,
takže výpis před zápisem projdi.

U vystavených řádků se sazbou **0 %** se klasifikace záměrně nedoplňuje automaticky.
Nulová sazba sama nerozlišuje osvobození bez nároku, vývoz, plnění mimo předmět
daně ani přeúčtování. Přiznání zobrazí warning a řádek zahrne až po výslovném
výběru správné klasifikace.

### Override per řádek nebo header

V editoru faktury (vystavené i přijaté) je sekce **Klasifikace** s VAT picker dropdown. Můžeš:
- Nechat prázdné → auto-default
- Vybrat konkrétní kód → manual override (např. specifický kód pro export)

### Reverse charge v cizí měně

Pro RC plnění (typicky `reverse_charge=true` na fakturě, kódy 5 / 23 / 24)
v cizí měně:

1. **Kurz** se aplikuje na základ DPH (`pii.total_without_vat × invoice.exchange_rate`).
2. **Samovyměřená daň** se dopočte ze sazby (`základ_CZK × vat_rate / 100`),
   protože vendor vystavil bez DPH.
3. **Odpočet** se uvede na ř. 43 jako mirror primary řádku (3 / 10 / 12 — viz
   `dphdp3_line_secondary` v `vat_classifications`).

Příklad: faktura z DE, 1 000 € @ kurz 25, vat_classification_code='23' →
ř. 3 (`p_zb23=25000`, `dan_pzb23=5250`) + ř. 43 (`nar_zdp23=25000`,
`od_zdp23=5250`) + KH sekce A.2.

> **Pozor na `odp_rezim` / `odp_rez_nar`.** Tahle dvojice patří na **ř. 45**
> (korekce odpočtu podle § 75, § 77 a § 79 — registrace, vyrovnání), ne na
> ř. 43. Zrcadlový odpočet ze samovyměření nese `nar_zdp23` / `od_zdp23`.
> Kdo by se řídil dřívějším zněním téhle sekce při ruční editaci XML, vykázal
> by korekci odpočtu místo odpočtu ze samovyměření.

### Pořízení dlouhodobého majetku

Checkbox **„Pořízení dlouhodobého majetku"** v editoru přijaté faktury označí
doklad za majetek vymezený v § 4 odst. 4 písm. c) (vozidlo, stroj). Pro
mixed doklady lze flag nastavit i per řádek.

Hodnota se na DPHDP3 uvede:
- **ř. 40** (nebo 41/42/43 podle klasifikace) — běžný odpočet
- **ř. 47** (atribut `nar_maj`) — doplňující údaj o hodnotě majetku

Daň se v součtech ř. 46 neduplikuje (ř. 47 je informativní). V [Knize DPH](30_Kniha_DPH.md)
je samostatná sekce **47.047** se sumací.

## Kontrolní hlášení (DPHKH1)

### Cesta: `Daně → Kontrolní hlášení`

KH se podává **vždy měsíčně** s sekcemi:

- **A.1** — Plnění v režimu přenesené daňové povinnosti (dodavatel)
- **A.2** — Pořízení zboží z jiného členského státu EU. Vyžaduje
  klasifikační kód `23` na řádcích faktury; pro EU vendora + RC + 21 % se
  přiřadí automaticky. Atributy: `k_stat`, `vatid_dod`, `c_evid_dd`, `dppd`,
  `zakl_dane1/dan1`, `zakl_dane2/dan2`. Daň je samovyměřená — Kniha DPH ji
  i u řádků RC počítá z `základ × sazba/100`.
- **A.4** — Tuzemská plnění s DPH **nad 10 000 Kč** (individuálně)
- **A.5** — Tuzemská plnění s DPH **do 10 000 Kč** (sumace)
- **B.1** — Přenesená daňová povinnost (odběratel)
- **B.2** — Přijatá tuzemská plnění nad 10 000 Kč
- **B.3** — Přijatá tuzemská plnění do 10 000 Kč (sumace)

UI ukazuje **count řádků per sekce** + deadline countdown (termín se stejně jako
u přiznání posouvá z víkendu/svátku na nejbližší pracovní den, § 33/4 DŘ).

V topbaru lze zvolit **Formu podání**: Řádné (výchozí) / **Opravné** (§ 101f
odst. 1 — nahrazuje řádné KH před uplynutím lhůty) / **Následné** (§ 101f
odst. 2 — po zjištění nesprávných údajů po lhůtě; podává se do 5 pracovních
dnů, **kompletní znovu se všemi údaji**, ne jen rozdíl). U následného je
povinné **Datum zjištění** důvodů — do XML jde jako `khdph_forma` + `d_zjist`.

### Pravidla zařazení do sekcí

Aby v reálně podaném KH seděly sekce, řídí se zařazení dokladů těmito pravidly
(odpovídají metodice GFŘ a opravám z reportu #35):

| Pravidlo | Detail |
|---|---|
| **Období** | `COALESCE(tax_date, issue_date)` v daném měsíci — DUZP, fallback datum vystavení. Doklad **bez DUZP** se zařadí podle data vystavení (nevypadne). |
| **Stav** | Bez `draft` a `cancelled` (storno je součást auditní stopy, do KH nepatří). |
| **Práh 10 000 Kč** | Porovnává se **`abs()` celkové částky vč. DPH** — záporný dobropis nad limit (např. −25 000 Kč) jde tedy správně do A.4/B.2 jednotlivě, ne do sumace. |
| **Dobropisy** | **Přijatý dobropis** (`document_kind='credit_note'`) vstupuje do evidence **vždy záporně** — snižuje odpočet i sumace B.3, bez ohledu na to, s jakým znaménkem je v DB uložen (normalizace `-ABS()`; starší importy ukládaly kladně). **Vydaný dobropis** je uložen záporně a znaménko se zachovává. Dobropis nad 10 000 Kč vč. DPH tvoří **samostatný záporný řádek B.2/A.4** dle metodiky KH. |
| **DIČ protistrany** | Do A.4/B.2 patří jen plnění **nad limit a s DIČ** plátce. Plnění **bez DIČ** (B2C, doklad od neplátce) jde do sumace **A.5/B.3 bez ohledu na částku** — dříve se nad limit bez DIČ tiše zahazovalo. |
| **Jen zdanitelná plnění** | Do A.4/A.5/B.2/B.3 patří jen plnění se **zdanitelným základem 21/12 %**. Osvobozená, EU dodání, vývoz a reverse charge (kde je uložená sazba 0) se sem **nezařazují** (netvoří nulové řádky). |

#### Kam který doklad patří

- **A.1** (vystavené RC) — faktury v režimu přenesené daňové povinnosti (dodavatel).
  Detekce: klasifikační kód s `is_reverse_charge` **nebo** příznak `reverse_charge`
  na faktuře. Vyžaduje DIČ odběratele.
- **A.2** (pořízení zboží z JČS) — přijaté faktury s klasifikací `kh_section = 'A.2'`
  (typicky kód 23). Daň je **samovyměřená** (počítá se ze základu × sazba).
  **Nezařadí se zároveň do B.2** ani do B.1.
- **A.4 / A.5** (vystavená tuzemská) — viz pravidla v tabulce výše.
- **B.1** (přijaté RC) — **tuzemský** reverse charge (kód 5 / `is_reverse_charge`).
  Pořízení z JČS (A.2) sem **nepatří**, i když je také samovyměřené.
- **B.2 / B.3** (přijatá tuzemská) — analogicky k A.4/A.5. Vylučují se doklady,
  které patří do A.2 / B.1 / reverse charge (aby se neduplikovaly).

> [!NOTE]
> **Rekapitulace (VetaC)** sčítá obraty napříč sekcemi. `pln_rez_pren` odpovídá
> A.1, `rez_pren23` a `rez_pren5` odpovídají B.1 v základní a snížené sazbě.

#### Atributy A.2 (pořízení z JČS)

`k_stat` (země dodavatele), `vatid_dod` (DIČ bez prefixu země), `c_evid_dd` (číslo
dokladu dodavatele), `dppd` (datum povinnosti přiznat daň), `zakl_dane1/dan1` (21 %),
`zakl_dane2/dan2` (12 %). Daň se dopočítá ze základu × sazba/100, protože vendor
fakturuje bez DPH.

Oddíl A.2 zahrnuje také přijaté služby od osoby neusazené v tuzemsku ze třetí
země (kód `24`, ř. 12/13 přiznání). U takového dodavatele může zůstat VAT ID
i kód členského státu prázdný.

### Zvláštní režimy a opravy nedobytných pohledávek

V `Systém → Číselníky → Klasifikace DPH` lze u vlastního kódu nastavit:

- režim KH `0` (běžný), `1` (cestovní služba § 89) nebo `2` (použité zboží § 90),
- příznak `P` pro opravu nedobytné pohledávky dle § 46 / § 74b.

Hodnoty se přenesou do `VetaA4.kod_rezim_pl` a `VetaA4/VetaB2.zdph_44`.
U vystaveného dobropisu, který snižuje daň, přiznání zároveň připomene ověření
data doručení opravného daňového dokladu podle § 42 ZDPH.

## Změna VAT sazby v budoucnu (např. 21% → 20% v 2027)

Pokud se sazba změní, postupuj:

1. **Codebooks → Sazby DPH:**
   - U existující CZ-21 nastav `valid_to = 2026-12-31`
   - Vytvoř novou CZ-20 s `rate_percent = 20.00`, `valid_from = 2027-01-01`
2. **Codebooks → Klasifikace DPH:**
   - U kódu "1" (vystavená 21%) — buď uprav `vat_rate` na 20, nebo nech a budou se používat **oba** kódy (jen historicky).
3. **Pro historické faktury 2026** — sazba 21% zůstane na řádku (snapshot, immutable po vystavení).
4. **Pro nové faktury 2027+** — systém auto-default najde novou sazbu/kód.

## Časté chyby

### "Chybí kód finančního úřadu"
→ Doplň v Nastavení → Daňové nastavení.

### "Faktura nemá VAT klasifikační kód"
→ Auto-default by ho měl přiřadit. Pokud ne, znamená to, že VAT sazba na řádku nemá v `vat_classifications` defaultní kód. Buď přidej kód v Codebooks, nebo vyber manual v editoru.

### "DIČ klienta není ve formátu CZxxxxxxxx"
→ Pro KH XML potřebuje DIČ být čisté číslo (bez prefixu CZ). Systém to ořezává automaticky. Pokud klient **nemá DIČ**, doklad se zařadí do **sumační sekce A.5 (resp. B.3)** bez ohledu na částku — do A.4/B.2, kde je DIČ povinné, se nedostane. Pokud doklad do A.4/B.2 patřit má (protistrana je plátce), doplň jí DIČ.

## Podpora pro daňového poradce

Pokud XML zpracovává externí účetní:
1. Vyplň v Nastavení **Sestavitel přiznání** (jméno, funkce, telefon, email)
2. Doporučujeme: u poradce ověřit XML před prvním podáním
3. Pro testovací podání používej **EPO portal v módu "Testovací podání"** (https://adisspr.mfcr.cz)
