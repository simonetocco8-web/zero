# Regole permanenti — ZeroMagazzino

- DECISIONE ACCESSO 18/10/2026: marketplace riservato, homepage guest solo ingresso/registrazione. Customer/admin autenticati; rivendite approved. Articoli eliminabili da owner/admin con soft delete, storico preservato e blocco reservation attive. Foto gestibili singolarmente, moderazione versione pubblicata conservata. Quantità UI italiane senza zeri superflui; dati millesimi invariati. Vedere docs/inventory-access.md; prevale sui riferimenti storici al marketplace pubblico.

- DECISIONE CORRENTE: correzione vincolante utente 16/10/2026 (credito retailer spendibile e checkout 30m), poi PDF pagamenti v1.0 del 14/10/2026 ricevuto il 16/10. Stripe Connect separate charges and transfers, vendite FREE/PRO 200/50 bp, conguaglio 50 bp al ricevente. Checkout 30m, conguaglio 1440m, proposta 48h. Credito retailer maturato spendibile su acquisti e conguagli, anche insieme alla carta (correzione vincolante del committente del 16/10, prevale sul PDF per questi punti); bonus customer separato 12 mesi/max10% merce. Questa decisione e docs/marketplace-payments.md prevalgono sui riferimenti storici sotto. Implementazione provider autorizzata dalla richiesta utente, attivazione live subordinata a verifica Stripe/fiscale. Nessun incasso reale durante sviluppo; fixture fake solo nei test.

## Perimetro e stack

- Destinazione: marketplace B2B/B2C integrato in un unico monolite Laravel, un solo database e lo stesso dominio, con PUBLIC MARKETPLACE, CUSTOMER AREA, RETAILER AREA e ADMIN AREA. Distinguere sempre EXISTING, PLANNED e OPEN BUSINESS DECISION nei documenti.
- Il catalogo pubblico usa InventoryItem e dati locali approvati, senza dipendenza da StoreGatewayInterface; conservare StoreGatewayInterface/FakeStoreGateway/StorePublication come integrazione esterna opzionale. SubscriptionBilling PRO esistente è separato da MarketplacePayments futuro; PSP marketplace come Stripe Connect ora approvato come modello futuro; modalità tecnica ancora da scegliere, nessun adattatore/incasso reale autorizzato.
- Marketplace autorizzato: fondamenta dati, homepage/catalogo/schede pubbliche, immagini autorizzate, carrello e ordini multi-vendor con payment gateway fake separato dal billing PRO. Migration solo additive, preservando dati e categoria testuale legacy; nessun provider reale, Stripe Connect, incasso reale in questa fase. Scambi PRO autorizzati: versioni immutabili, doppia accettazione, conguaglio esclusivamente fake e riserve condivise con ordini; trasporto, IVA e commissioni separati. StockReservation autorizzato: transazioni e lock DB, scadenza configurabile di sviluppo, consumo/rilascio idempotenti e receipt evento separati dal billing PRO. IVA non definita resta provvisoria e nullable, senza simulare un incasso. Vietati migrate:fresh, migrate:refresh, reset DB e rimozioni distruttive di dati esistenti, anche durante la verifica; usare database nuovi e isolati per i test.
- Aree riservate marketplace implementate: cliente/retailer compratore, vendite con isolamento per sottoordine, registri admin, consegne e rimborsi integrali fake. Notifiche persistenti SQL ed email asincrone su coda dedicata dopo commit; riutilizzare credito/bonifici senza accrediti automatici marketplace. Contratti in `docs/marketplace-private-areas.md`.
- Decisioni economiche approvate e audit: `docs/adr/marketplace-funds-flow.md` e `docs/marketplace-payments.md`. Aliquote vendite FREE/PRO 200/50 bp snapshot; scambi 50 bp della sola differenza al ricevente, nessuna fee sui valori merci. Due cessioni fiscali e consegne versionate; checkout 30m/conguaglio 1440m configurabili. Ledger fake escluso dal credito prelevabile; resi dopo spedizione senza policy automatica.
- Runtime corrente: PHP >= 8.4.1, Laravel 13 dal lockfile, MySQL >= 8.0.16 (consigliato 8.4), Blade, Tailwind CSS, Alpine.js e Vite; Node 24 per build asset. Preservare versioni fissate e verificare compatibilità prima di aggiornare.
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
