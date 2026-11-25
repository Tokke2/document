# Arbetsregler för Dokumentportalen

Dessa regler gäller alltid, i varje chatt och varje session, tills användaren uttryckligen ändrar dem. Läs filen innan arbete påbörjas.

## 1. En enda branch – hård regel
- Det får finnas **exakt en** branch att arbeta mot: `arena/a79f0d9d-document`.
- **Arbeta aldrig mot `main`** – inte vid uppdateringar, inte vid fixar, inte vid push. Commita, pusha och öppna PR endast från arbetsbranchen.
- Andra grenar tas bort. `main` på GitHub är kvar tills default-branch kan bytas (kontotoken saknar ännu behörighet); rör den inte.

## 2. Release vid varje kodförändring – hård regel
- **Alltid** publicera/uppdatera GitHub-releasen efter varje ändring av kod, och **markera den som Latest**.
- Appens uppdaterare hämtar `releases/latest`, resolverar taggen till en commit och **jämför filer** mot den – utan aktuell Latest-release ser appen inga uppdateringar.
- Tagga från arbetsbranchens commit, **aldrig från `main`**. Nästa tagg efter `0.0.1` är `0.0.2`, sedan `0.0.3`, …
- Exempel: `gh release create 0.0.2 --target arena/a79f0d9d-document --latest --title "0.0.2" --notes "…"`.
- Krävs av alla som ändrar kod i detta repo, inklusive agenter.

## 3. Skadeblankettens validering
- Valideringsfel får **aldrig tömma eller återställa formuläret**. Felen markeras med röd ram + fokusmarkör på första fältet, och **all inmatad data behålls** (klient, 422 och CSRF-väg).
- CSRF/säkerhetsfel visar formuläret med ny token i stället för att dölja fliken; återförsök kräver ingen omladdning.
- Återhämtningscopy: "Dina uppgifter är kvar i formuläret – tryck på Skicka igen…".

## 4. Förslagslistor
- Ett förslag får **aldrig återanvändas** i någon lista (`FORSLAG-25.md`, `FORSLAG-26.md`, batchar).
- **Efter var 10:e chattmeddelande:** visa en ny lista med **10 nya** förslag från reserverade batchar i `FORSLAG-26.md`, och bokför batchen under *Redovisade batchar* med datum.
- Är batcharna förbrukade skrivs 10 helt nya förslag först.

## 5. Uppdateringar och backup
- En lyckad update tar **alltid bort sin backup/transaktionskatalog** direkt; bara misslyckad återställning behåller kopian.
- Kvarlämnade transaktionskataloger från avbrutna install städas efter ett dygn vid nästa install.

## 6. Loggretention
- Arkiverade/roterade loggar (`logs/*.jsonl.1–3`) rensas **efter 7 dagar** via `TadegaPrivacyLogger::cleanupArchivedLogs(7)`. Levande loggfiler rörs inte.

## 7. Logotyp och ikoner
- `assets/tadega-icon.svg` och `assets/tadega-logo.svg` är mästare med **platta färger** (filrutten sanerar bort gradienter/`defs`) och ordmärket som **banor utan fontberoende**.
- Raster byggs alltid om från mästarna: `npm run build:icons` (PWA/apple-touch/favicon/FPDF-PNG) och `npm run build:logo`.

## 8. Arbetsrutin vid var 10:e session
- Städa workspace enligt README-DEPLOY: behåll aktiva projektfiler, den senast valda ikon-/designresursen, aktuell leverans-ZIP och specifikationen. Radera aldrig kunddata, originalmallar eller produktionsberoenden utan backup och klartecken.
