<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Zaokrouhlení vydaného dokladu převzaté z importu (#258).
 *
 * Náš přepočet (InvoiceCalculator) sčítá položky na haléře, zdrojový systém ale
 * mohl celkovou částku zaokrouhlit (typicky na celé koruny) a klient zaplatil
 * zaokrouhlenou částku. Rozdíl ukládáme do `invoices.rounding`: do DPH se
 * nepočítá a je součástí `amount_to_pay`, takže párování plateb i stav úhrady
 * sedí na částku, kterou klient skutečně platil.
 *
 * Věrohodné je jen zaokrouhlení pod 1 jednotku měny. Větší rozdíl znamená, že
 * se položky nepřevedly přesně (jiný režim cen apod.), a pak raději nehádáme.
 */
final class ImportedInvoiceRounding
{
    /**
     * Fakturoid v3: `total` = celkem s DPH v měně dokladu, `rounding_adjustment`
     * = zaokrouhlení mimo DPH. Primárně rozdíl `total` proti našemu přepočtu
     * (sedí, ať `total` zaokrouhlení obsahuje, nebo ne), `rounding_adjustment`
     * jen když se `total` s přepočtem shoduje a zaokrouhlení tedy neobsahuje.
     *
     * @param array<string,mixed> $doc Fakturoid invoice JSON
     */
    public static function fromFakturoid(array $doc, float $computedWithVat): float
    {
        if (isset($doc['total']) && is_numeric($doc['total'])) {
            $diff = round((float) $doc['total'] - $computedWithVat, 2);
            if (self::isPlausible($diff)) {
                return $diff;
            }
            if (abs($diff) >= 0.005) {
                return 0.0;
            }
        }
        $adjustment = is_numeric($doc['rounding_adjustment'] ?? null) ? round((float) $doc['rounding_adjustment'], 2) : 0.0;
        return self::isPlausible($adjustment) ? $adjustment : 0.0;
    }

    /**
     * iDoklad v3 posílá zaokrouhlení jako položku s ItemType = 1 (ItemTypeRound).
     *
     * @param array<string,mixed> $line
     */
    public static function isIdokladRoundingItem(array $line): bool
    {
        return (int) ($line['ItemType'] ?? 0) === 1;
    }

    /**
     * Součet zaokrouhlovacích položek iDokladu (s DPH, znaménko dle dokladu), nebo
     * null, když doklad žádnou nemá nebo je součet nevěrohodný. Při null volající
     * nechává zaokrouhlovací položky jako běžné řádky, aby celková částka seděla.
     *
     * @param array<int,mixed> $items
     */
    public static function fromIdokladItems(array $items): ?float
    {
        $found = false;
        $sum = 0.0;
        foreach ($items as $line) {
            if (!is_array($line) || !self::isIdokladRoundingItem($line)) {
                continue;
            }
            $found = true;
            $prices = is_array($line['Prices'] ?? null) ? $line['Prices'] : [];
            if (isset($prices['TotalWithVat']) && is_numeric($prices['TotalWithVat'])) {
                $sum += (float) $prices['TotalWithVat'];
            } else {
                $sum += (float) ($prices['UnitPrice'] ?? $line['UnitPrice'] ?? 0) * (float) ($line['Amount'] ?? 1);
            }
        }
        $sum = round($sum, 2);
        return $found && abs($sum) < 1.0 ? $sum : null;
    }

    /**
     * ISDOC (#289): primárně `<PayableRoundingAmount>`. Je to jediný spolehlivý
     * zdroj, některé exportéry (Fakturovač) píšou `<PayableAmount>` 0 a do
     * `<TaxInclusiveAmount>` už zaokrouhlenou částku. Bez něj rozdíl nenulového
     * `<PayableAmount>` proti přepočtu, ale jen když doklad neodečítá zálohy.
     * Položky přebíráme ve znaménku dokladu, takže zaokrouhlení se neotáčí.
     *
     * @param array<string,mixed> $inv výstup IsdocParser
     */
    public static function fromIsdoc(array $inv, float $computedWithVat): float
    {
        $rounding = $inv['payable_rounding'] ?? null;
        if (is_numeric($rounding)) {
            $rounding = round((float) $rounding, 2);
            return self::isPlausible($rounding) ? $rounding : 0.0;
        }
        $payable  = $inv['payable_amount'] ?? null;
        $deposits = is_numeric($inv['paid_deposits_amount'] ?? null) ? (float) $inv['paid_deposits_amount'] : 0.0;
        if (!is_numeric($payable) || abs((float) $payable) < 0.005 || abs($deposits) >= 0.005) {
            return 0.0;
        }
        $diff = round(abs((float) $payable) - abs($computedWithVat), 2);
        if ($computedWithVat < 0) {
            $diff = -$diff;
        }
        return self::isPlausible($diff) ? $diff : 0.0;
    }

    private static function isPlausible(float $rounding): bool
    {
        return abs($rounding) >= 0.005 && abs($rounding) < 1.0;
    }
}
