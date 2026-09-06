<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Builder XML pro DPH přiznání (DPHDP3) — EPO portál MFČR.
 *
 * Verze EPO: 03.01 (platná 2025-2026).
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním vždy ověřit s účetní
 *    nebo daňovým poradcem. Aplikace nezaručuje regulatorní správnost.
 *
 * Schema: https://adisspr.mfcr.cz/dpr/adis/idpr_pub/dpr_info/schemas.faces
 */
final class DphPriznaniBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatClassificationMapper $mapper,
    ) {}

    /**
     * Povolené formy podání: B řádné, O opravné (§ 138 DŘ).
     *
     * Dodatečné formy D/E (§ 141 DŘ) jsou ZÁMĚRNĚ vypnuté: dodatečné přiznání
     * se dle § 141 odst. 2 DŘ podává v ROZDÍLECH proti poslední známé dani,
     * builder ale umí jen plné hodnoty za období — vygenerované XML by bylo
     * věcně špatně (znovu přiznaná celá daň, v neprospěch poplatníka).
     * Re-enable = vrátit 'D'/'E' sem a do FORMS_REQUIRING_DZJIST, až bude
     * implementován dopočet rozdílů proti poslední známé dani.
     * (Následné KH je jiný případ — podává se kompletní, KH formy se nemění.)
     */
    public const FORMS = ['B', 'O'];
    /** Formy, u kterých EPO vyžaduje datum zjištění důvodů (d_zjist) — dodatečná podání (§ 141 DŘ). */
    public const FORMS_REQUIRING_DZJIST = [];

    /**
     * Sestaví XML pro DPH přiznání za daný měsíc/kvartál.
     *
     * @param string $period 'monthly' (default) nebo 'quarterly' (sumuje celý kvartál)
     * @param string $form dapdph_forma — viz FORMS; u O je $dZjist volitelné
     * @param string|null $dZjist datum zjištění důvodů pro podání (DD.MM.YYYY)
     * @return array{xml: string, summary: array<string, mixed>, warnings: list<string>}
     */
    public function build(int $supplierId, int $year, int $month, ?string $period = null, string $form = 'B', ?string $dZjist = null): array
    {
        if (!in_array($form, self::FORMS, true)) {
            throw new \InvalidArgumentException("Neplatná forma přiznání '{$form}' (povolené: " . implode(', ', self::FORMS) . ').');
        }
        if (in_array($form, self::FORMS_REQUIRING_DZJIST, true) && ($dZjist === null || $dZjist === '')) {
            throw new \InvalidArgumentException('Dodatečné přiznání vyžaduje datum zjištění důvodů (d_zjist).');
        }
        $supplier = $this->loadSupplier($supplierId);
        // Default period z supplier.vat_period, fallback 'monthly'
        if ($period === null) {
            $period = (string) ($supplier['vat_period'] ?? 'monthly');
        }
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            $period = 'monthly';
        }
        // Identifikovaná osoba (§ 6g–6l ZDPH, issue #94): přiznání typu 'I' —
        // vyplňuje JEN samovyměření z přeshraničních přijatých plnění (ř. 3-6
        // pořízení zboží / služby z EU, ř. 12-13 služby ze 3. zemí), vždy měsíčně,
        // a jen za měsíce, kdy povinnost vznikla. BEZ nároku na odpočet (žádná
        // Veta4 vč. zrcadlového ř. 43), bez tuzemských výstupů (ř. 1/2) i oddílu C
        // (ř. 20-26 — služby do EU vykazuje jen v souhrnném hlášení).
        $isIdentified = !$supplier['is_vat_payer'] && !empty($supplier['is_identified']);

        $warnings = [];
        if ($isIdentified) {
            $warnings[] = 'Přiznání identifikované osoby (typ I): jen samovyměření z přeshraničních plnění, bez nároku na odpočet. Podává se pouze za měsíce, kdy povinnost vznikla (do 25. dne následujícího měsíce).';
        } elseif (!$supplier['is_vat_payer']) {
            $warnings[] = 'Tenant není evidovaný jako plátce DPH — výkaz nemusí být relevantní.';
        }
        if (empty($supplier['financial_office_code'])) {
            $warnings[] = 'Chybí kód finančního úřadu — XML nemusí projít validací EPO.';
        }
        if (empty($supplier['ic'])) {
            $warnings[] = 'Chybí IČO tenanta.';
        }
        if (empty($supplier['dic'])) {
            $warnings[] = 'Chybí DIČ tenanta.';
        }

        if ($isIdentified && $period !== 'monthly') {
            // IO má zdaňovací období VŽDY kalendářní měsíc (§ 99 se na ni nevztahuje,
            // povinnost vzniká per měsíc dle § 101 odst. 5).
            $period = 'monthly';
            $warnings[] = 'Identifikovaná osoba podává vždy za kalendářní měsíc — kvartální volba ignorována.';
        }

        $lines = $this->mapper->aggregateForDphPriznani($supplierId, $year, $month, $period);
        // #238: doklady v cizí měně bez kurzu — NEházíme chybu, vrátíme je v
        // `missing_rates` a akce je při stažení doplní z ČNB (náhled jen varuje).
        $missingRates = $this->mapper->missingRatesForPeriod($supplierId, $year, $month, $period);
        if ($missingRates !== []) {
            $warnings[] = 'Chybí kurz u dokladů v cizí měně: '
                . implode(', ', \MyInvoice\Service\Report\VatLedgerService::missingExchangeRateLabels($missingRates))
                . '. Při stažení XML se doplní z ČNB.';
        }
        $this->appendSalesDataWarnings($supplierId, $year, $month, $period, $warnings);
        if ($isIdentified) {
            $lines = $this->filterLinesForIdentified($lines, $warnings);
        }
        $quarter = $period === 'quarterly' ? (int) ceil($month / 3) : null;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        // Root: <Pisemnost nazevSW="MyInvoice.cz" verzeSW="X.Y.Z">
        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        // <DPHDP3 verzePis="03.01">
        $dphdp3 = $dom->createElement('DPHDP3');
        $dphdp3->setAttribute('verzePis', '03.01');
        $pisemnost->appendChild($dphdp3);

        // ── VetaD: identifikační údaje (typ podání + perioda) ─────────
        // Per EPO XSD: typ_platce je v VetaD, typ_ds v VetaP.
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('rok', (string) $year);
        if ($quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        // B = řádné (default), O = opravné. Dodatečné D/E jsou vypnuté do
        // implementace dopočtu rozdílů dle § 141/2 DŘ — viz komentář u FORMS.
        $vetaD->setAttribute('dapdph_forma', $form);
        // Datum zjištění důvodů (§ 141 DŘ) — u O volitelně propustíme, když ho
        // uživatel vyplní.
        if ($dZjist !== null && $dZjist !== '') {
            $vetaD->setAttribute('d_zjist', $dZjist);
        }
        $vetaD->setAttribute('dokument', 'DP3');   // identifikace typu výkazu
        // P = plátce DPH (default), I = identifikovaná osoba (S = skupina, N = neplátce)
        $vetaD->setAttribute('typ_platce', $isIdentified ? 'I' : 'P');
        // CZ-NACE klasifikace (hlavní ekonomická činnost) — vyplňuje se
        // z `supplier.cz_nace_code`, kanonizuje se proti snapshotu číselníku
        // ČINNOSTI (viz EpoOkecCodebook); expirace/neznámý kód se hlásí
        // ve warnings completeness checku, build neblokuje.
        $okec = EpoSupplierBlockBuilder::normalizeOkec((string) ($supplier['cz_nace_code'] ?? ''));
        if ($okec !== null) {
            $vetaD->setAttribute('c_okec', $okec);
        }
        $vetaD->setAttribute('d_poddp', date('d.m.Y')); // datum podání (dnes)
        // trans: „existují údaje pro oddíl C?" — spočteme níže, až budou Vety sestavené.
        // Spočteme níže po sestavení Veta6 a setneme přes setAttribute.
        $dphdp3->appendChild($vetaD);

        // ── VetaP: identifikace daňového subjektu ─────────────────────
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);
        $dphdp3->appendChild($vetaP);

        // ── Veta1 / Veta4: namapování řádků 1-13 (Veta1) a 40-47 (Veta4) ──
        //
        // Mapping odpovídá EPO XSD (api/xsd/dphdp3.xsd), reálně podanému
        // přiznání a oficiálnímu MFČR DPHDP3 formuláři (verze 03.01):
        //
        // Veta1 — DPH na výstupu (vč. samovyměřené u RC):
        //   ř.1  obrat23/dan23           = sale 21 %
        //   ř.2  obrat5/dan5             = sale 12 %
        //   ř.3  p_zb23/dan_pzb23        = pořízení zboží z JČS 21 % (EU)
        //   ř.4  p_zb5/dan_pzb5          = pořízení zboží z JČS 12 % (EU)
        //   ř.5  p_sl23_e/dan_psl23_e    = přijetí služby z EU 21 %
        //   ř.6  p_sl5_e/dan_psl5_e      = přijetí služby z EU 12 %
        //   ř.7  dov_zb23/dan_dzb23      = dovoz zboží 21 %
        //   ř.8  dov_zb5/dan_dzb5        = dovoz zboží 12 %
        //   ř.10 rez_pren23/dan_rpren23  = tuzemský reverse charge 21 %
        //   ř.11 rez_pren5/dan_rpren5    = tuzemský reverse charge 12 %
        //   ř.12 p_sl23_z/dan_psl23_z    = přijetí služby ze 3. země 21 %
        //   ř.13 p_sl5_z/dan_psl5_z      = přijetí služby ze 3. země 12 %
        //
        // Veta4 — Nárok na odpočet daně:
        //   ř.40 pln23/odp_tuz23_nar     = tuzemsko 21 %
        //   ř.41 pln5/odp_tuz5_nar       = tuzemsko 12 %
        //   ř.42 dov_cu/odp_cu_nar       = dovoz CÚ
        //   ř.43 nar_zdp23/od_zdp23      = odpočet ze samovyměřených plnění (ř. 3-13),
        //                                  sloupec „V plné výši", ZÁKLADNÍ sazba (21 %).
        //   ř.44 nar_zdp5/od_zdp5        = totéž ve SNÍŽENÉ sazbě (12 %) — RC řádek s 12%
        //                                  sazbou se sem remapuje ve VatLedgerService (S3).
        //                                  POZOR: odp_rezim/odp_rez_nar je ř.45 (korekce
        //                                  odpočtu dle §75/§77/§79 — registrace, vyrovnání),
        //                                  NE ř.44. Ř.45 se negeneruje automaticky (mimo
        //                                  rozsah, řeší účetní) — případný custom kód mířící
        //                                  na ř.45 se nevykreslí ani nezapočte (viz guard níže).
        //   ř.46 odp_sum_nar             = součtový řádek odpočtu (ř.40-45, „V plné výši")
        //   ř.47 nar_maj/—               = hodnota pořízeného majetku
        //                                  (doplňující údaj, jen základ; XSD má
        //                                  jediný atribut, daň se neuvádí)
        $lineMap = [
            // Veta1 (výstup)
            '1'  => ['veta' => 1, 'base' => 'obrat23',    'vat' => 'dan23'],
            '2'  => ['veta' => 1, 'base' => 'obrat5',     'vat' => 'dan5'],
            '3'  => ['veta' => 1, 'base' => 'p_zb23',     'vat' => 'dan_pzb23'],
            '4'  => ['veta' => 1, 'base' => 'p_zb5',      'vat' => 'dan_pzb5'],
            '5'  => ['veta' => 1, 'base' => 'p_sl23_e',   'vat' => 'dan_psl23_e'],
            '6'  => ['veta' => 1, 'base' => 'p_sl5_e',    'vat' => 'dan_psl5_e'],
            '7'  => ['veta' => 1, 'base' => 'dov_zb23',   'vat' => 'dan_dzb23'],
            '8'  => ['veta' => 1, 'base' => 'dov_zb5',    'vat' => 'dan_dzb5'],
            '10' => ['veta' => 1, 'base' => 'rez_pren23', 'vat' => 'dan_rpren23'],
            '11' => ['veta' => 1, 'base' => 'rez_pren5',  'vat' => 'dan_rpren5'],
            '12' => ['veta' => 1, 'base' => 'p_sl23_z',   'vat' => 'dan_psl23_z'],
            '13' => ['veta' => 1, 'base' => 'p_sl5_z',    'vat' => 'dan_psl5_z'],
            // Veta2 (oddíl C — ostatní plnění s nárokem na odpočet; jen základ, bez daně):
            //   ř.20 dodání zboží do JČS · ř.21 služby do JČS (§9/1) · ř.22 vývoz (§66)
            //   ř.23 dodání nového dopr. prostředku neregistrované osobě · ř.24 zasílání zboží
            //   ř.25 RC dodavatel (§92a) · ř.26 ostatní plnění s nárokem na odpočet
            '20' => ['veta' => 2, 'base' => 'dod_zb',      'vat' => null],
            '21' => ['veta' => 2, 'base' => 'pln_sluzby',  'vat' => null],
            '22' => ['veta' => 2, 'base' => 'pln_vyvoz',   'vat' => null],
            '23' => ['veta' => 2, 'base' => 'dod_dop_nrg', 'vat' => null],
            '24' => ['veta' => 2, 'base' => 'pln_zaslani', 'vat' => null],
            '25' => ['veta' => 2, 'base' => 'pln_rez_pren','vat' => null],
            '26' => ['veta' => 2, 'base' => 'pln_ost',     'vat' => null],
            // Veta3 (oddíl C — doplňující údaje; jen základ, bez daně):
            //   ř.30 pořízení zboží prostřední osobou · ř.31 dodání zboží prostřední osobou
            //   (třístranný obchod § 17). Hodnota z ř.31 jde do souhrnného hlášení s kódem 2.
            '30' => ['veta' => 3, 'base' => 'tri_pozb',   'vat' => null],
            '31' => ['veta' => 3, 'base' => 'tri_dozb',   'vat' => null],
            // Veta5 (oddíl B — krácení nároku na odpočet §76):
            //   ř.50 plnosv_kf = plnění osvobozená od daně bez nároku na odpočet (§51),
            //   sloupec „S nárokem na odpočet" vstupující do koeficientu §76. Jen základ,
            //   bez daně (osvobozené plnění daň nenese). Plný koeficient (koef_p20_*,
            //   ř.52/53) se needituje — mimo rozsah, řeší účetní.
            '50' => ['veta' => 5, 'base' => 'plnosv_kf',  'vat' => null],
            // Veta4 (odpočet)
            '40' => ['veta' => 4, 'base' => 'pln23',      'vat' => 'odp_tuz23_nar'],
            '41' => ['veta' => 4, 'base' => 'pln5',       'vat' => 'odp_tuz5_nar'],
            '42' => ['veta' => 4, 'base' => 'dov_cu',     'vat' => 'odp_cu_nar'],
            '43' => ['veta' => 4, 'base' => 'nar_zdp23',  'vat' => 'od_zdp23'],
            '44' => ['veta' => 4, 'base' => 'nar_zdp5',   'vat' => 'od_zdp5'],
            '47' => ['veta' => 4, 'base' => 'nar_maj',    'vat' => null],
        ];

        $totalDanZdanitelne = 0.0;
        $totalDanOdpocitatelne = 0.0;
        $veta1Attrs = [];
        $veta2Attrs = [];
        $veta3Attrs = [];
        $veta4Attrs = [];
        $veta5Attrs = [];

        foreach ($lines as $lineNum => $data) {
            $lineKey = (string) $lineNum;
            // Řádek mimo lineMap (builder ho neumí vykreslit — např. custom kód na ř.45)
            // se NEvykreslí ANI nezapočítá do rekapitulace. Dřív se tiše přičítal do
            // ř.46/62/63, aniž by byl v detailu → EPO hlásilo nekonzistenci (audit 2026-07).
            if (!isset($lineMap[$lineKey])) {
                continue;
            }
            $m = $lineMap[$lineKey];
            $target = &${'veta' . $m['veta'] . 'Attrs'};
            $target[$m['base']] = $this->formatAmount($data['base']);
            if ($m['vat'] !== null) {
                $target[$m['vat']] = $this->formatAmount($data['vat']);
            }
            unset($target);

            // Rekapitulace jen z řádků, které NESOU daň (mají vat atribut). Řádky jen se
            // základem — oddíl C (ř.20-31), osvobozené (ř.50), majetek (ř.47) — do ř.62/63
            // nepatří; jinak by zbloudilá daň na základovém řádku nafoukla ř.62. Sčítáme
            // zaokrouhleně na celé Kč (jak se vykazují), aby ř.62/63 seděly se součtem detailu.
            if ($m['vat'] === null) {
                continue;
            }
            $lineVat = round($data['vat']);
            if ($this->isOutputLine($lineKey)) {
                $totalDanZdanitelne += $lineVat;
            } else {
                $totalDanOdpocitatelne += $lineVat;
            }
        }
        if (!empty($veta1Attrs)) {
            $veta1 = $dom->createElement('Veta1');
            foreach ($veta1Attrs as $k => $v) $veta1->setAttribute($k, $v);
            $dphdp3->appendChild($veta1);
        }
        // Veta2 — oddíl B (ř.20-26). XSD vyžaduje pořadí Veta1 → Veta2 → Veta3 → Veta4.
        if (!empty($veta2Attrs)) {
            $veta2 = $dom->createElement('Veta2');
            foreach ($veta2Attrs as $k => $v) $veta2->setAttribute($k, $v);
            $dphdp3->appendChild($veta2);
        }
        // Veta3 — oddíl C, doplňující údaje (ř.30/31 třístranný obchod prostřední osobou).
        if (!empty($veta3Attrs)) {
            $veta3 = $dom->createElement('Veta3');
            foreach ($veta3Attrs as $k => $v) $veta3->setAttribute($k, $v);
            $dphdp3->appendChild($veta3);
        }
        if (!empty($veta4Attrs)) {
            // ř.46 (odp_sum_nar) = součtový řádek 40-45 ve sloupci „V plné výši" =
            // celkový nárok na odpočet. Bez něj EPO portál hlásí propustnou chybu
            // („Odpočet daně celkem nevyplněn"). Rovná se ř.63 (odp_zocelk) — proto
            // používáme totéž $totalDanOdpocitatelne, které ř.47 (doplňující údaj
            // k majetku) správně nezapočítává.
            $veta4Attrs['odp_sum_nar'] = $this->formatAmount($totalDanOdpocitatelne);
            $veta4 = $dom->createElement('Veta4');
            foreach ($veta4Attrs as $k => $v) $veta4->setAttribute($k, $v);
            $dphdp3->appendChild($veta4);
        }
        // Veta5 — oddíl B, ř.50 (osvobozená plnění bez nároku na odpočet, §76 koeficient).
        // XSD pořadí Veta4 → Veta5 → Veta6; emit jen když je co vykázat (jako Veta2/3/4).
        if (!empty($veta5Attrs)) {
            $veta5 = $dom->createElement('Veta5');
            foreach ($veta5Attrs as $k => $v) $veta5->setAttribute($k, $v);
            $dphdp3->appendChild($veta5);
        }

        $vlastniDan = $totalDanZdanitelne - $totalDanOdpocitatelne;

        // ── Veta6: rekapitulace (XSD pořadí Veta4 → Veta6 → VetaR) ───────
        // ř.62 dan_zocelk = daň na výstupu celkem, ř.63 odp_zocelk = odpočet celkem,
        // ř.64 dano_da = vlastní daň (jen když výstup > odpočet),
        // ř.66 dano_no = nadměrný odpočet (kladné číslo, jen když odpočet > výstup).
        // EPO si rekapitulaci po importu sice dopočítá, ale úplný soubor je správnější.
        $veta6 = $dom->createElement('Veta6');
        $veta6->setAttribute('dan_zocelk', $this->formatAmount($totalDanZdanitelne));
        $veta6->setAttribute('odp_zocelk', $this->formatAmount($totalDanOdpocitatelne));
        if ($vlastniDan > 0) {
            $veta6->setAttribute('dano_da', $this->formatAmount($vlastniDan));
        } elseif ($vlastniDan < 0) {
            $veta6->setAttribute('dano_no', $this->formatAmount(-$vlastniDan));
        }
        $dphdp3->appendChild($veta6);

        // ── VetaR: poradi (wrapper element, summary attrs jdou jinam) ────
        $vetaR = $dom->createElement('VetaR');
        $vetaR->setAttribute('poradi', '1');
        $dphdp3->appendChild($vetaR);

        // ⚠️ `trans` NENÍ znaménko daně, ale zaškrtávátko „Neexistují-li údaje
        // pro C. oddíl". EPO podle něj oddíl C buď vykreslí, nebo PŘEŠKRTNE —
        // a přeškrtnutý oddíl obsahovou kontrolou neprojde:
        //
        //   CHYBA 36 — JE ZAŠKRTNUTO, ŽE NEEXISTUJÍ ÚDAJE PRO C. ODDÍL,
        //   NESMÍ BÝT VYPLNĚNY ÚDAJE V ODDÍLE C.
        //
        // Dokud se odvozovalo jen ze znaménka vlastní daně, dostalo přiznání
        // složené výhradně ze samovyměření (daň na výstupu i zrcadlový odpočet
        // ve stejné výši, ř. 64 = 0) hodnotu `N` — a bylo bez ručního zásahu
        // nepodatelné, přestože Veta1 i Veta4 byly vyplněné správně. Totéž
        // hrozilo u nadměrného odpočtu.
        //
        // Proto: máme-li v oddílu C cokoliv vyplněné, `trans` je `A`. `N`
        // zůstává pro období, ve kterém se opravdu nic nestalo.
        $sectionCHasData = !empty($veta1Attrs) || !empty($veta2Attrs) || !empty($veta3Attrs)
            || !empty($veta4Attrs) || !empty($veta5Attrs);
        $vetaD->setAttribute('trans', ($vlastniDan > 0 || $sectionCHasData) ? 'A' : 'N');

        // Termín podání: 25. den následujícího měsíce po skončení období
        $deadlineMonth = $quarter !== null ? ($quarter * 3 + 1) : ($month + 1);
        $deadlineYear  = $year;
        if ($deadlineMonth > 12) {
            $deadlineMonth -= 12;
            $deadlineYear += 1;
        }
        // § 33/4 daňového řádu: víkend/svátek → nejbližší následující pracovní den.
        $deadline = CzechWorkingDays::shiftToWorkingDay(
            new \DateTimeImmutable(sprintf('%04d-%02d-25', $deadlineYear, $deadlineMonth))
        )->format('Y-m-d');

        $summary = [
            'period'                  => sprintf('%04d-%02d', $year, $month),
            'period_type'             => $period,
            'form'                    => $form,
            'typ_platce'              => $isIdentified ? 'I' : 'P',
            'quarter'                 => $quarter,
            'lines'                   => $lines,
            'total_vat_output'        => round($totalDanZdanitelne, 2),
            'total_vat_input'         => round($totalDanOdpocitatelne, 2),
            'tax_due'                 => round($vlastniDan, 2),
            'is_excess_deduction'     => $vlastniDan < 0,
            'submission_deadline'     => $deadline,
            'supplier_vat_period'     => (string) ($supplier['vat_period'] ?? ''),
        ];

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => $summary,
            'warnings' => $warnings,
            'missing_rates' => $missingRates,
        ];
    }

    /**
     * @param list<string> $warnings
     */
    private function appendSalesDataWarnings(int $supplierId, int $year, int $month, string $period, array &$warnings): void
    {
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            $endMonth = $quarter * 3;
        } else {
            $startMonth = $endMonth = $month;
        }
        $start = sprintf('%04d-%02d-01', $year, $startMonth);
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))
            ->modify('last day of this month')->format('Y-m-d');

        // Čistě OSS dobropis nesnižuje tuzemskou daň na výstupu (jeho záporná DPH je zahraniční),
        // takže by § 42 varování jen mátlo. Vyžadujeme aspoň jeden ne-OSS řádek.
        $creditNoteOssFilter = $this->db->hasColumn('invoice_items', 'oss_applicable')
            ? "AND EXISTS (SELECT 1 FROM invoice_items cii
                            WHERE cii.invoice_id = invoices.id
                              AND COALESCE(cii.oss_applicable, 0) = 0)"
            : '';
        $creditNotes = $this->db->pdo()->prepare(
            "SELECT varsymbol
               FROM invoices
              WHERE supplier_id = ?
                AND status NOT IN ('draft', 'cancelled')
                AND invoice_type = 'credit_note'
                AND (total_without_vat < 0 OR total_vat < 0)
                AND COALESCE(tax_date, issue_date) BETWEEN ? AND ?
                {$creditNoteOssFilter}
           ORDER BY COALESCE(tax_date, issue_date), id"
        );
        $creditNotes->execute([$supplierId, $start, $end]);
        foreach ($creditNotes->fetchAll(\PDO::FETCH_COLUMN) as $number) {
            $warnings[] = "Dobropis {$number} snižuje daň na výstupu. Ověřte, že datum zařazení odpovídá doručení opravného daňového dokladu nebo vynaložení rozumného úsilí o jeho doručení (§ 42 ZDPH).";
        }

        $ossFilter = $this->db->hasColumn('invoice_items', 'oss_applicable')
            ? 'AND COALESCE(ii.oss_applicable, 0) = 0'
            : '';
        $unclassifiedZero = $this->db->pdo()->prepare(
            "SELECT DISTINCT i.varsymbol
               FROM invoices i
               JOIN invoice_items ii ON ii.invoice_id = i.id
              WHERE i.supplier_id = ?
                AND i.status NOT IN ('draft', 'cancelled')
                AND i.invoice_type <> 'proforma'
                AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
                AND COALESCE(i.reverse_charge, 0) = 0
                AND ii.vat_rate_snapshot = 0
                {$ossFilter}
                AND ii.vat_classification_code IS NULL
                AND i.vat_classification_code IS NULL
           ORDER BY i.varsymbol"
        );
        $unclassifiedZero->execute([$supplierId, $start, $end]);
        foreach ($unclassifiedZero->fetchAll(\PDO::FETCH_COLUMN) as $number) {
            $warnings[] = "Doklad {$number} obsahuje neklasifikovaný řádek se sazbou 0 %. Řádek nebyl zahrnut na ř. 50; zvolte výslovnou klasifikaci DPH.";
        }
    }

    /**
     * Řádky povolené identifikované osobě (§ 6g–6l, issue #94): jen samovyměření
     * z přeshraničních přijatých plnění. Cokoli jiného (tuzemské výstupy ř. 1/2,
     * oddíl C ř. 20-31, odpočty ř. 40+ vč. zrcadlového ř. 43 z RC mirroru) IO
     * nevyplňuje — vyhazujeme s warningem, ať uživatel ví, co a proč vypadlo.
     *
     * Vyloučené řádky se vznikem povinnosti, které IO věcně nemá:
     *   ř. 7/8 (dovoz zboží — DPH u neplátce vybírá celní úřad),
     *   ř. 10/11 (tuzemský RC § 92a — jen mezi plátci).
     *
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $lines
     * @param list<string> $warnings by-ref
     * @return array<string, array{base:float, vat:float, count:int, label:string}>
     */
    private function filterLinesForIdentified(array $lines, array &$warnings): array
    {
        $allowed = ['3', '4', '5', '6', '12', '13'];
        // Zrcadlový odpočet ř. 43 (dphdp3_line_secondary klasifikací 23/24/25)
        // a navázaný doplňující ř. 47 vznikají u IO automaticky z klasifikace —
        // jejich vyřazení JE pointa režimu (IO nemá nárok na odpočet), žádný warning.
        $silentDrop = ['43', '47'];
        $kept = [];
        foreach ($lines as $line => $data) {
            $key = (string) $line;
            if (in_array($key, $allowed, true)) {
                $kept[$line] = $data;
                continue;
            }
            if (in_array($key, $silentDrop, true)) {
                continue;
            }
            $warnings[] = sprintf(
                'Řádek %s (%s, základ %s Kč) identifikovaná osoba nevyplňuje — vynechán. Zkontroluj klasifikaci dokladů.',
                $key,
                $data['label'],
                number_format($data['base'], 0, ',', ' '),
            );
        }
        return $kept;
    }

    /**
     * Načti tax-relevantní info o tenantovi.
     * @return array<string,mixed>
     */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer, s.is_identified,
                    s.taxpayer_type, s.vat_period, s.financial_office_code,
                    s.workplace_code, s.cz_nace_code, s.data_box_id,
                    s.email, s.phone,
                    s.street_number_pop, s.street_number_orient,
                    s.opr_jmeno, s.opr_prijmeni, s.opr_postaveni,
                    s.sest_jmeno, s.sest_prijmeni, s.sest_telefon, s.sest_email, s.sest_funkce
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        }
        return $row;
    }

    private function loadAppVersion(): ?string
    {
        $verFile = __DIR__ . '/../../../../VERSION';
        if (is_file($verFile)) {
            return trim((string) file_get_contents($verFile)) ?: null;
        }
        return null;
    }

    // VetaP a normalizeOkec přesunuto do EpoSupplierBlockBuilder (sdíleno s KH/SHV).

    /**
     * Output lines (DPH na výstupu): 1-29 dle DPHDP3.
     * Input lines (DPH na vstupu, odpočet): 40+ dle DPHDP3.
     */
    private function isOutputLine(string $line): bool
    {
        return (int) $line < 40;
    }

    /**
     * Formátování částky pro EPO XML — celé číslo Kč (zaokrouhleno).
     */
    private function formatAmount(float $amount): string
    {
        return (string) (int) round($amount);
    }
}
