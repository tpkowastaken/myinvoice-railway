# Automatická synchronizace forku

Workflow `Sync upstream` kontroluje jednou denně v 04:17 UTC upstream tohoto forku
(`radekhulan/myinvoice`) a slučuje jeho větev `master` do místní větve `master`.
Oba repozitáře používají `master`, nikoli `main`. GitHub může plánované běhy
zpozdit; nejde o okamžitou reakci na push v upstreamu. Každá kontrola spustí
runner, i když v upstreamu nejsou žádné změny.

Synchronizace začne fungovat po začlenění `.github/workflows/sync-upstream.yml`
do výchozí větve `master` a povolení GitHub Actions ve forku. Lze ji spustit také
ručně přes **Actions → Sync upstream → Run workflow**. Používá vestavěný
`GITHUB_TOKEN`, bez dalšího secretu.

Vlastní commity forku zůstávají zachované. Konflikt nebo zákaz zápisu ochranou
větve ukončí běh chybou; konflikt je nutné vyřešit ručně. Po změně větve workflow
výslovně spustí `CI`, protože zápisy přes `GITHUB_TOKEN` nespouštějí další push
workflow. CI běží až po sloučení a není podmínkou automatické synchronizace.

Pokud GitHub odmítne aktualizaci upstream workflow kvůli oprávněním tokenu,
je nutné tuto aktualizaci sloučit ručně. Neaktivní veřejné repozitáře mohou mít
plánované workflow automaticky vypnuté po 60 dnech; znovu se povoluje v Actions.
