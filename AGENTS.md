# Regole permanenti — ZeroMagazzino

## Perimetro e stack

- Gestionale B2B per giacenze edili; il negozio online è un sistema esterno sostituibile. Non creare un ecommerce completo senza un requisito esplicito.
- PHP >= 8.3, Laravel stabile compatibile con il runtime scelto, MySQL >= 8.0, Blade, Tailwind CSS, Alpine.js e Vite.
- Scegliere e fissare la versione Laravel all'inizializzazione dopo verifica dei requisiti ufficiali e dell'ambiente. Non presumere che tutte le versioni stabili supportino PHP 8.3.
- Livewire solo con vantaggio concreto documentato; React/Vue richiedono una decisione documentata.
- Usare il checkout esistente: le attività cloud sono già isolate; non creare worktree salvo richiesta esplicita.

## Codice e architettura

- Seguire convenzioni Laravel, PSR-12 e Laravel Pint. Nomi del codice in inglese; interfaccia e documentazione in italiano.
- Controller sottili: validazione nei Form Requests, autorizzazione nelle Policies, business logic in Actions/Services.
- Usare Models, Enums, Events/Listeners e Jobs quando utili. Evitare repository generici, framework interni e astrazioni speculative.
- Applicare Policies a tutte le operazioni sui dati, comprese letture, download e azioni eseguite da Livewire; filtrare sempre per ownership.
- Transazioni e vincoli database per invarianti; lock quando concorrenza e disponibilità lo richiedono.
- Importi monetari mai in float: unità minori intere e valuta ISO 4217; percentuali in basis point o decimali esatti. Arrotondamento esplicito, testato e centralizzato.
- Modifiche piccole, coerenti e facilmente revisionabili. Aggiornare documentazione e migration con i cambiamenti di modello.

## Sicurezza

- Mai hardcodare o committare secrets: password, credenziali DB, Stripe secret, token ecommerce, API key e webhook secrets.
- Configurazione tramite `.env`, escluso da Git; `.env.example` contiene solo nomi, placeholder e valori non sensibili. Leggere `env()` nei file di configurazione, usare `config()` nel codice.
- Non registrare secrets, payload personali completi o coordinate bancarie nei log; minimizzare e proteggere i dati sensibili.
- CSRF sui flussi browser; validazione server-side; hashing Laravel per password; rate limiting su login, registrazione, richieste pubbliche e integrazioni.
- HTTPS in produzione, cookie Secure/HttpOnly/SameSite, rigenerazione sessione al login e invalidazione al logout.
- Webhook: verificare firma sul corpo originale, timestamp secondo provider e destinazione; esenzione CSRF solo per endpoint dedicati autenticati dalla firma.
- Webhook idempotenti: chiave evento univoca per provider, elaborazione transazionale, retry sicuri, nessun doppio movimento monetario o di stock.
- Upload: validare MIME reale, dimensione e tipo; nomi generati, niente contenuti eseguibili; file privati fino ad autorizzazione alla pubblicazione.

## UX

- Mobile-first: card anziché tabelle larghe, azioni evidenti, form semplici, target tattili almeno 44×44 px, badge con testo e feedback immediati.
- Desktop: sidebar, dashboard sintetiche e tabelle solo quando più efficienti. Navigazione inferiore quando appropriato.
- Accessibilità: label, errori associati ai campi, focus visibile, tastiera, contrasto; stati mai comunicati solo con colore.
- Foto da fotocamera con fallback alla selezione file. Feedback client non sostituisce validazione server.

## Test e verifica obbligatori

- Usare PHPUnit come default; adottare Pest solo con scelta esplicita e coerente nel progetto.
- Ogni modifica applicativa deve includere test significativi per il comportamento introdotto o modificato, con esecuzione della suite pertinente prima della consegna.
- Testare Policies e accesso tra rivenditori, transizioni di stato, importi/arrotondamenti, concorrenza stock, firma e duplicazione webhook, retry integrazioni.
- Test unitari per calcoli; feature test per richieste e autorizzazione; adapter con fake e contract test. Usare MySQL per verifiche dipendenti da lock/vincoli, senza assumere equivalenza SQLite.
- Eseguire build Vite e verifiche responsive/accessibilità per modifiche UI. Non chiamare verificato un test non eseguito; distinguere blocchi ambientali da difetti applicativi.
- Per sole modifiche documentali, verificare coerenza, collegamenti e diff; non creare test artificiali.

Consultare `docs/requirements.md`, `docs/architecture.md`, `docs/database.md`, `docs/ui-ux.md` e `docs/integrations.md` prima di sviluppare.
