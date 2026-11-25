# AGENTS.md – hårdregler för Dokumentportalen

Läs denna fil **innan** du ändrar något i repot. Reglerna gäller varje session och varje ny chatt, tills användaren uttryckligen ändrar dem. Bryter du mot en hårdregel: stanna, rätta och informera användaren.

## Hårdregler

1. **En enda branch.** Arbeta endast mot `arena/a79f0d9d-document`. **Arbeta aldrig mot `main`** – inte vid vanliga ändringar och **speciellt inte vid appupdateringar**. Andra grenar tas bort. `main` på GitHub kvarstår bara tills default-branch kan bytas (kontotoken saknar behörighet); rör den inte.
2. **Aldrig merga.** Ingen merge av PR:er, inga merge-commits, ingen sammanslagning av brancher – `gh pr merge` eller motsvarande får aldrig köras.
3. **Release vid varje kodförändring – publicera och markera som Latest.** Appens uppdaterare hämtar `releases/latest`, resolverar taggen till en commit och **jämför filer** mot den. Utan aktuell Latest ser appen inga uppdateringar. Efter varje push: skapa/uppdatera releasen från **arbetsbranchens** commit (aldrig från `main`) och markera den Latest. Nästa tagg efter `0.0.2` är `0.0.3`, sedan `0.0.4`, …
   ```bash
   gh release create 0.0.3 --target arena/a79f0d9d-document --latest --title "0.0.3" --notes "…"
   ```
4. **Inga tysta beroende- eller gränssnittsbrott.** Nya beroenden, filer, rutter, JSON-fält eller CSS-kontrakt som test pekar på ska vara **uttryckliga och dokumenterade**. Bryt aldrig ett gränssnitt (API-fält, routes, tillåtna element i sanerare, testkontrakter) i tysthet – säg till användaren och uppdatera dokumentation + tester samtidigt.
5. **Test krävs innan klar.** Kör testsviten före att du kallar något klart (se *Tester* nedan). Nytt beteende får **nya regressionstester**; alla gamla måste fortfarande vara gröna. `php -l`/syntaxkontroll på ändrade PHP-filer.
6. **Validering tömmer aldrig formulär.** Skadeblankettens fel visas med röd ram + fokus på första ogiltiga fältet och **all inmatad data behålls** (klient, 422, CSRF). CSRF-fel visar formuläret med ny token, inte en tom flik.
7. **Förslag återanvänds aldrig – och skrivs alltid i chatten.** Ingen idé får visas två gånger (FORSLAG-25, FORSLAG-26, batchar). **Efter var 10:e chattmeddelande visas 10 nya förslag** från reserverade batchar i `FORSLAG-26.md`; bokför batchen under *Redovisade batchar* med datum, skriv ny batch om tarpan tar slut. **25-förslagslistan och varje rotationsbatch skrivs dessutom alltid ut i chatten** (inte bara i filen).
8. **Update-backup:** en lyckad uppdatering tar alltid bort sin transaktionskatalog (stage + backup) direkt; bara misslyckad återställning behåller kopian. Kvarlämnade `tadega-release-txn-*` städas efter ett dygn vid nästa install.
9. **Arkiverade loggar** (`logs/*.jsonl.1–3`) rensas efter **7 dagar** via `TadegaPrivacyLogger::cleanupArchivedLogs(7)`. Levande loggfiler rörs inte.
10. **Logotyp och ikoner** byggs från mästrarna `assets/tadega-icon.svg` och `assets/tadega-logo.svg` (platta färger – filrutten sanerar bort gradienter/`defs`; ordmärket är banor utan fontberoende). Raster: `npm run build:icons`, logotyp: `npm run build:logo`. Använd mästarna överallt, även för tryck.
11. **REQ-K4:** användarens kravreferens. Texten finns ännu inte i detta repo – komplettera den här raden när användaren lämnar kravtexten; tysta gissningar om innehållet är förbjudna.
12. **Komplett fellogg som töms vid åtgärd:** `storage/active_errors.json` är den aktiva felloggen (fylls av `TadegaPrivacyLogger::diagnostic()`). Den innehåller alla aktiva fel tills admin markerar dem som åtgärdade i Fakturaunderlag → Felsökning – då tas de bort ur både den listan och råloggen (`resolve_active_errors`-flödet). Glöm aldrig bort `upsertActiveError`-kopplingen i `diagnostic()`.
13. **Gårdagens loggar arkiveras:** `TadegaPrivacyLogger::archiveYesterdayLogs()` roterar audit/diagnostics/requests till `.1–.3` en gång per dygn; arkiven raderas efter 7 dagar (regel 9).
14. **Inga synliga terminalfönster:** startskript och verktyg ska alltid köra minimerat/i bakgrunden utan uppstartat terminalfönster (gäller lokal desktop-start; portalen själv körs i webbläsare).
15. **Kolumner ska kunna sorteras** där appen har tabeller (arkivet har klickbara rubriker via `assets/table_sort.js`). Ny tabellfunktion = test + dokumentation; flytt av kolumner (drag) kräver användarens kravspec före bygge.
16. **Anpassa efter varje app:** reglerna gäller alla appar i monorepot (portalen, skadekunden, faktura-/protokoll-/kalender-PWA:erna) – kontrollera alltid vilken app en ändring rör innan du rör gränssnitt, åtgärder eller lagring (se avsnittet *Per app* nedan).
17. **Nya regler skrivs alltid till GitHub:** skriver användaren **"- hård regel"** eller **"- regel"** i chatten ska den nya regeln **omedelbart läggas in i denna fil (AGENTS.md), commitas och pushas** – och enligt regel 3 även publiceras som Latest-release. Glöm aldrig bort det.
18. **Profiler i Inställningar:** underfliken *Profiler* (`?tab=settings&sub=profiler`) listar **endast appar med eget profilsystem** (Fakturaunderlag, Serviceprotokoll) och syns **endast för faktura-admin**. Den skapar profiler via de befintliga serveråtgärderna (`invoice_admin_create_user`, `protocol_admin_create_user`) – aldrig nya parallella lagringar.

## Per app

| App | Entry | Särskilt |
|---|---|---|
| Portalen (personal) | `index.php` | Endast plattform för alla flikar; central admin-styr release och inställningar. |
| Skadekund (kiosk) | `skadeblankett-kund.php` | Kundläge, ingen inloggning, validering utan dataförlust, signaturkrav. |
| Faktura-PWA | `pwa-underlag.php` + `manifest-underlag` | Scoped: bara `pwaAllowedActions`-åtgärder; ingen navigering ut. |
| Protokoll-PWA | `pwa-protokoll.php` + `manifest-protokoll` | Egen session och konton. |
| Kalender-PWA | `pwa-kalender.php` + `manifest-kalender` | Egna åtgärder och inloggning. |

## Verktyg och tester

- `npm run test:skade-customer` · `test:ui` · `test:voice` · `build:icons` · `build:logo`
- PHP-regressionstester: `npx --yes @php-wasm/cli tests/php/<test>_regression.php` (ingen system-PHP finns; kör alla `tests/php/*_regression.php` vid större ändringar).
- PDF: `npx --yes @php-wasm/cli tests/php/skadeblankett_pdf_regression.php`
- Playwright: `npx playwright test --list` (listning krävs vid gränssnittsändringar; full körning krävs före release när browsers finns).
- Testserver: `npx @php-wasm/cli -S 0.0.0.0:8091 -t .`

## Relaterade dokument

- `REGLER.md` – kort pekare hit.
- `README-DEPLOY.md` – driftsättning, releaseflöde, loggarkitektur, skadekiosk.
- `FORSLAG-26.md` – förslagslistor och rotationsregel (aldrig återbruk).
