# Riepilogo scambio — presentazione orientata al rivenditore

## EXISTING — 24 ottobre 2026

La pagina `/retailer/scambi/{exchange}` usa una testata di stato, un blocco «Prossima azione» e KPI separati per valore offerto, ricevuto e conguaglio. La scadenza è formattata giorno/mese/anno e ora; il badge distingue azioni richieste, conferma/completamento e chiusure. Lo stato effettivo scaduto continua a essere soltanto una rappresentazione della scadenza già usata dalla vecchia view: una GET non modifica lo scambio.

La prospettiva parte sempre dall’azienda autenticata, anche quando è il lato B: «Tu offri» precede «Tu ricevi» nei KPI, nelle accettazioni, nella versione corrente e nei dettagli delle versioni precedenti. Importi, quantità e IVA sono letti esclusivamente dagli snapshot e formattati con le primitive esistenti; nessun calcolo economico nuovo.

Desktop: contenitore limitato a `max-w-6xl`, confronto affiancato con indicatore di scambio. Mobile: confronto verticale nella stessa sequenza, KPI su due colonne con conguaglio evidenziato, testi che vanno a capo e nessuna tabella orizzontale. Le card prodotto separano nome/SKU, quantità, valore unitario, totale merce e IVA. Consegna, destinazione, costo trasporto e pagatore sono un blocco distinto. Non vengono caricate foto o giacenze per decorazione: la pagina usa i dati già caricati dal controller.

Le accettazioni mostrano nome e stato testuale con icone. La versione corrente è isolata dallo storico. Le precedenti sono `<details>` chiusi di default, con riepilogo economico nella summary e dettagli ancora interamente consultabili. Commissione, cessioni, trasporto A/B, note e stato del pagamento fake sono conservati nei dettagli economici/fiscali collassabili. La timeline presenta ora, data, azione, autore e versione, mantenendo tutti gli eventi nell’ordine esistente.

## Azioni e confini

Accettazione, simulazione/collegamento Stripe esistente e conferma ricezione sono azioni primarie. Controproposta resta secondaria; rifiuto e annullamento sono separati dalla primaria con variante danger. Modalità simulazione e assenza di pagamento reale sono esplicite quando indicate dal pagamento corrente.

Predicati originali preservati: negoziazione solo con `negotiate`, pagamento solo con `simulatePayment`, versioni aperte e non scadute, accettazione solo se non già registrata, zero provvisorio non accettabile e pagamento provvisorio disabilitato. La ricezione resta disponibile nello stato confirmed anche dopo downgrade, esattamente come prima. Tutti i form mantengono POST, CSRF, `version_id` e `summary_hash` correnti. Nessuna nuova route o modifica a controller, policy, stati, workflow, scadenze, stock, pagamenti, imposte, commissioni, database, migration o `.env`.

## File e verifiche

- `resources/views/exchanges/show.blade.php`: gerarchia, orientamento, testi prossima azione e versioni.
- `resources/views/exchanges/partials/actions.blade.php`: gli stessi form, organizzati per priorità.
- Componenti `exchange.party-card`, `exchange.financial-details`, `exchange.timeline`: confronto, dettagli secondari e cronologia.
- `ExchangePresentationTest`: nove test per prospettiva A/B, storico e accettazioni della versione corrente, payload e POST reali di accettazione/simulazione/ricezione, provvisorietà, conguaglio zero, completamento, downgrade, chiusure, lettura senza mutazioni, assenza di query decorative e collegamento Stripe esistente.
- `ExchangeDomainTest`: aggiornate soltanto due aspettative testuali per le nuove intestazioni; asserzioni di dominio invariate.

Browser Chromium: otto situazioni (negoziazione A/B, pagamento A/B, confermato, attesa ricezione, completato e provvisorio), larghezze 320/375/768/1280/1536 px. Controllati ordine delle parti, affiancamento desktop, assenza di overflow, target interattivi minimi da 44 px e apertura/chiusura dello storico con Enter. Non è una certificazione WCAG completa.

Risultati locali: suite pertinente 48 passati; SQLite completa 511 passati, 0 falliti e 28 saltati; MySQL completa 539 passati, 0 falliti e nessuno saltato. I nove test di presentazione passano su entrambi i database. Pint, Vite e compilazione delle view superati. `.env` verificato invariato.
