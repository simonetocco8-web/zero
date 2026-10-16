# Audit finale marketplace ZeroMagazzino — 16 ottobre 2026

## Base verificata e decisione

Audit ripreso su **main**, base `5fd789a1b3401bc0ca5d62984fecf3c4e7879234` (PR #7 consolidata). Prima di modificare codice sono stati verificati HEAD, branch, working tree pulito e presenza di Cart, Order, VendorOrder, StockReservation, ExchangeVersion, ExchangeAcceptance, MarketplacePayment, StockReservations e MarketplaceCart.

Il lavoro interrotto su `4d9fa52` è preservato nello stash `audit-on-obsolete-4d9fa52-preserved-before-main`. Il relativo report non è un audit del marketplace consolidato. Nessun cherry-pick e nessun recupero dei vecchi OperationalLifecycle/OrderStock o delle loro migration. Sono stati riutilizzati selettivamente soltanto strumenti CLI/packaging, riesaminati e testati sullo schema reale di main.

**Esito: staging tecnico verificato; lancio commerciale live ancora subordinato ai blocchi operativi sotto.** Il marketplace esiste ed è coperto dai test. Rimangono da completare/validare settlement reale, trasferimenti/reversals, payout, consegna documentata/OTP, contestazioni e fiscalità. Non sono semplici valori .env mancanti.

Source of truth economica: istruzione vincolante successiva del committente sul credito retailer e checkout 30m, poi `marketplace-payments.md` e `adr/marketplace-funds-flow.md`, PDF v1.0 per i punti confermati. Connect separate charges and transfers; vendite FREE/PRO 200/50 bp, conguaglio 50 bp al ricevente; checkout 30m, conguaglio 1440m, proposta 48h. **Credito retailer maturato spendibile per acquisti e conguagli, anche con carta**; bonus customer personale separato, 12 mesi, massimo 10% della merce e mai sui conguagli.

## Verifiche eseguite

| Verifica | Esito |
| --- | --- |
| Suite completa MySQL 8.4, processi concorrenti e DB nuovi | 407 passati, nessuno skip; 2459 asserzioni |
| Suite completa SQLite | 380 passati, 27 esclusi perché richiedono MySQL; 2192 asserzioni |
| Browser Chromium / axe / focus | 190 controlli della base su38 pagine +20 nuovi controlli sulle schermate credito, a360/390/430/768/1366px; nessun overflow o violazione axe rilevata |
| Build frontend | Vite riuscito |
| Composer | validate --strict e requisiti piattaforma riusciti; vendor produzione no-dev verificato separatamente |
| Controlli statici | Pint completo, lint PHP, sintassi Bash e parsing YAML riusciti. PHPStan/Larastan non configurati |
| npm audit | 0 vulnerabilità nel feed consultato |
| Composer advisory | **Non completato**: proxy CONNECT 403 verso packagist.org/api/security-advisories; non interpretato come assenza di vulnerabilità |
| Pacchetto reale | zeromagazzino-deploy.zip creato con vendor no-dev e asset compilati; archivio integro e contenuti update/initial-install verificati |
| GitHub Actions | YAML e meccanica locale verificati; nuova esecuzione remota sul commit finale da effettuare dopo pubblicazione |

Le prove usano dati sintetici e trasporto PSP fake/mocked. Non costituiscono un collaudo Stripe live, una prova su cPanel reale, un load test o una certificazione WCAG/dispositivi fisici. Nessun database esistente è stato resettato o eliminato: suite e upgrade usano nuovi database isolati trattenuti.

## Matrice dei 24 scenari richiesti

| # | Scenario | Risultato / evidenza |
| --- | --- | --- |
| 1 | Acquisto singolo rivenditore | PASS feature: MarketplaceCartOrderTest; prezzi/quantità/consegna e checkout HTTP |
| 2 | Carrello multi-rivenditore | PASS: sottoordini, snapshot venditori/indirizzi, trasporto e totali; singolo charge piattaforma simulato in MarketplaceStripeConnectTest |
| 3 | Pagamento riuscito | PASS fake e SDK mock: firma/importo/valuta/sessione, consumo stock/bonus e ledger una volta; nessun incasso reale |
| 4 | Pagamento fallito | PASS: StockReservationTest e MarketplaceCartOrderTest; rilascio terminale, retry ambiguo con chiave stabile |
| 5 | Pagamento scaduto | PASS: sessione scaduta libera bonus/stock; late capture va in riconciliazione e non rivende unità già prenotate da altri |
| 6 | Reservation scaduta | PASS: TTL autorevole DB anche prima del cleanup, comando idempotente e race con pagamento |
| 7 | Stock ripristinato | PASS: full refund pre-consegna, una sola restituzione; provider refund pending non ripristina prematuramente |
| 8 | Doppia vendita concorrente | PASS MySQL: StockReservationConcurrencyTest/MarketplaceCheckoutConcurrencyTest; clienti diversi competono per l’ultima unità |
| 9 | Scambio senza differenza | PASS: doppia accettazione, niente pagamento/fee, consumo atomico, completamento distinto |
| 10 | Scambio con conguaglio | PASS: versioni, pagante/ricevente, riserve e commissione 50 bp solo differenza |
| 11 | Controproposta | PASS: nuova versione immutabile, hash/termini e nuove accettazioni; versioni precedenti non valgono |
| 12 | Doppia accettazione | PASS: una parte non conta due volte; processi MySQL concorrenti producono una sola riserva per prodotto |
| 13 | Conguaglio fallito | PASS: fallimento/scadenza rilascia entrambe le parti senza confermare lo scambio |
| 14 | Conguaglio riuscito | PASS fake e SDK mock: conferma una volta, ledger distinto dalle vendite, consegna ancora da completare |
| 15 | Annullamento | PASS: ownership customer, rilascio riserve, cancel scambio e isolamento sibling/notifiche |
| 16 | Rimborso | PASS: integrale pre-consegna e late capture con intent PSP idempotente, pending/success distinti; partial PSP e post-spedizione restano workflow assistiti da completare |
| 17 | Pagamento con credito | PASS: acquisto e conguaglio solo credito retailer maturato, senza chiamata PSP, consumo una sola volta e refund/reversal append-only |
| 18 | Credito + carta | PASS: credito retailer + carta su acquisti e conguagli; allocazione multi-vendor deterministica, failure/expiry/retry e race MySQL. Bonus customer + carta separato, max10% merce |
| 19 | Commissione FREE | PASS: 200 bp snapshot e rimborsi senza repricing storico |
| 20 | Commissione PRO | PASS: 50 bp snapshot, indipendente da successive modifiche piano |
| 21 | Ownership customer | PASS: carrello/ordini/pagamenti/consegne/indirizzi/profilo/notifiche e accesso incrociato negato |
| 22 | Ownership retailer | PASS: soltanto propri sottoordini, scambi e foto; nessuna visibilità di articoli/totali sibling |
| 23 | Admin permissions | PASS: registri amministrativi protetti, ruolo customer/retailer non promuovibile dal form, no impersonazione acquisto |
| 24 | Notifiche | PASS: SQL atomico, outbox/email asincrone after-commit, retry/opt-out e ownership inbox; consegna SMTP reale da configurare |

Le suite ExchangeDomainTest, ExchangeConcurrencyTest, MarketplaceFundsFlowAuditTest, MarketplacePrivateAreasTest, MarketplaceStripeConnectTest, MarketplaceNotificationQueueTest, StockReservationTest e relative concurrency sono state rieseguite sul codice consolidato. I risultati precedenti del ramo obsoleto non sono conteggiati.

## Security audit

| Controllo | Esito e limite |
| --- | --- |
| CSRF | Browser protetto; nuovo test forza il middleware reale e verifica 419 su checkout, preferenze e login. Webhook dedicati autenticati dalla firma |
| XSS | Escaping Blade e input/script nei test public marketplace/richieste; URL e markup catalogo non determinano prezzi o permessi |
| IDOR / authorization | Policies e scope customer/retailer/admin; dettaglio seller non mostra altri vendor. Immagini pubbliche rivalidano pubblicazione e approvazione ad ogni richiesta |
| Mass assignment | Form Requests/allowlist; test su ruolo, owner, indirizzi, prezzi, importi e timestamp impediscono controllo client |
| SQL injection | Catalogo con binding ed escaping dei wildcard; sort/status/filtri validati; nessuna interpolazione di input come identificatore SQL |
| Stock races | Lock ordinati, somme autorevoli sotto lock, FK/CHECK e receipt; test MySQL su ultima unità, pagamento/cancel/expiry e scambi concorrenti |
| Money / quantity manipulation | Centesimi/millesimi esatti e Brick Math, arrotondamenti/snapshot e riallocazione senza perdita centesimi; float/negativi/precisione invalida rifiutati |
| Webhook spoofing / replay | Corpo originale, tolleranza, ambiente test/live, identità piattaforma/sessione/importo/valuta e chiave richiesta; receipt/hash e idempotenza. Firma scaduta/alterata e fake su pagamento Connect respinti |
| Duplicate charge | Marketplace usa chiave stabile e riconciliazione late capture. **Corretto anche un rischio nel billing PRO separato:** TTL locale non libera più automaticamente un nuovo intento; open/creating scaduti richiedono verifica provider |
| Wallet double spending | Credito retailer riservato sotto lock ordinati condivisi col payout; test MySQL due acquisti, stesso retry e acquisto contro payout; bonus customer separato |
| Image access | Foto approvate pubbliche e proposte private distinte, traversal/MIME/ricodifica, Policy e no-store verificati |
| Customer data exposure | Dati customer/indirizzi e pagamenti limitati al proprietario; notifiche minimizzate e pagine private no-store |
| IBAN exposure | Cifratura, hidden, mascheratura e assenza in flash/email; test ledger/contatti bancari passati |

Per il billing PRO la protezione aggiunta è conservativa: le sessioni open/creating oltre TTL restano bloccate finché l’operatore verifica su Stripe che non siano pagate. Riconciliare anche eventuali record già expired generati da versioni precedenti. Nessuna modifica al funds flow Connect o al saldo retailer è stata importata dal vecchio ramo.

## Performance

- **Catalogo:** query locale con select esplicita, immagini/category/retailer eager loaded e reserved_milliunits nella SELECT tramite subquery indicizzata. Test esistente confronta 2 e 20 prodotti con numero query identico, massimo 9. Paginazione 24.
- **Ricerca/faccette:** wildcard SQL escapati e parametri bindati. `%term%` e distinct di brand/province restano scansioni da misurare con volumi reali: nessun SLA/p95 dichiarato da fixture piccole.
- **Indici:** presenti marketplace_recent/price/category_price/brand/ean; stock_item_active_expiry e stock_expiry_scan; ownership ordini e vendor; indice morph inbox e indice dispatch outbox. Non sono stati aggiunti indici speculativi o migration del ramo obsoleto.
- **Ordini/dashboard/notifiche:** nuovo test 1→20 ordini/notifiche verifica query costanti per lista ordini, dashboard customer e inbox. Limiti 15 ordini e 20 notifiche; eager loading delle consegne/righe e scope owner. Storico scambi ha già regression test N+1.
- **Reservations:** disponibilità deriva da active+expires_at; cleanup e riconciliazione a batch, lock e comandi idempotenti. Scheduler e backlog devono essere misurati sul provider.
- **Da osservare in staging:** inventari PRO grandi, faccette/catalogo, ordinamento inbox su storico ampio, registri admin con UNION, deadlock retry, cron e latenze PSP. Verificare EXPLAIN/slow log con dati rappresentativi prima di eventuali ulteriori indici.

## Mobile / browser

190 controlli a 360, 390, 430, 768 e 1366 px: pubblico/catalogo/scheda/login/registrazione, carrello e checkout; dashboard/ordini/pagamenti/consegne/indirizzi/profilo customer; vendite/conguagli/scambi/controproposta/credito retailer; registri e dettagli admin, inbox per ruolo.

Risposte attese 200, nessun overflow orizzontale o violazione axe WCAG 2 A/AA e 2.1 AA rilevata; focus da tastiera presente. Login/logout usano form reali con sessione. Prove con fixture nuove su SQLite isolato, simulazione esplicita e nessun PSP reale. Dispositivi fisici, screen reader, fotocamera e test di carico restano verifiche esterne.

## Update cPanel: cosa cambia

Procedura completa in [cpanel-no-ssh-deployment.md](cpanel-no-ssh-deployment.md), checklist in [pre-production-checklist.md](pre-production-checklist.md).

- Preferenza PHP CLI da cPanel Cron Jobs verificabile con cron temporaneo. Runner privato CLI-only, non installer web: preflight manifest/hash, pending allowlist additive di **main**, backup reference, maintenance e flock.
- Claim persistente del commit prima degli effetti; replay bloccato anche dopo errore. Comandi fissi: solo pending migrate --force, pulizia/ricostruzione cache e queue:restart. Nessun fresh/reset/rollback/drop/seed/key:generate.
- Journal privato con release/commit/pending/hash/esito; errori redatti, nessuna riapertura automatica. Tutti i cron temporanei e il runner vanno rimossi; receipt preservate. Crash/DDL parziale richiedono analisi provider senza forzare replay.
- Test MySQL upgrade dal DB legacy popolato: utenti, retailer, categorie testuali, quantità/prezzi, vendite/righe e ledger originali confrontati sulle colonne originarie. Record preservati, più test lock, replay, hash/allowlist, backup/maintenance e failure persistente.
- Workflow mantiene **zeromagazzino-deploy.zip**, vendor no-dev e asset; modalità update default esclude install-zero.php. Prima installazione solo con scelta esplicita. Manifest/checksum aggiunti; CI dello stesso commit obbligatoria prima del job package.
- Se Cron Jobs non può eseguire PHP CLI, è documentato il design di fallback HTTPS POST-only con token digest/scadenza/binding commit, consumo persistente prima degli effetti, fail-closed e rimozione immediata. Nessun updater web attivo senza verifica di quel prerequisito e test dedicati. Il vecchio install-zero.php non viene riutilizzato.

Il pacchetto locale da staging fuori Git è solo prova tecnica (`source_dirty=true`): il runner lo rifiuta. L’artifact distribuibile deve provenire dal workflow sul commit finale revisionato. La nuova run GitHub e l’esecuzione sul vero cPanel non sono dichiarate effettuate da queste prove locali.

## Pronto / configurazione esterna / sviluppo residuo

| Stato | Area |
| --- | --- |
| Verificato localmente | Marketplace customer/retailer/admin, carrello e snapshot multi-vendor, scambi versionati, riserve, credito retailer solo/misto carta, incasso/refund mock, bonus separato, ownership e notifiche |
| Implementato e testato | Runner additivo su schema legacy popolato, packaging update/initial-install, guardie replay/maintenance/manifest e cron procedure |
| Configurazione/collaudo esterno | PHP CLI cPanel, permessi CREATE/ALTER/INDEX/TRIGGER, backup restore, APP_KEY/storage, HTTPS/proxy, SMTP, worker integrations/default/marketplace-notifications e scheduler |
| Configurazione/collaudo esterno | Account Connect/capabilities, chiavi/webhook distinti, testmode ufficiale, fiscalità merci/servizio/bonus e riconciliazione esiti ambigui |
| Sviluppo operativo ancora necessario per live completo | Fee PSP/balance transaction, trasferimenti e reversal, payout del lunedì/anticipi, prova consegna/OTP+72h e gestione contestazioni prima di sbloccare crediti reali |
| Sviluppo/operatività assistita | Partial refund PSP, resi post-consegna B2B/B2C, recovery dopo trasferimenti reali e amministrazione campagne bonus |
| Verifica incompleta | Feed Composer advisory bloccato; ripetere quando consentito, senza dichiarare assenza di vulnerabilità PHP |

Go per staging controllato; **no-go per attivazione economica live completa** finché i limiti operativi sopra non sono chiusi. Il requisito vincolante successivo del committente sul credito retailer è implementato; il bonus customer non lo sostituisce.

## Correzione prima della pubblicazione — credito retailer

Base della correzione: commit locale `7664d2e444110c0e44c3bafee313118e57215845` su main. La nuova istruzione del committente prevale sul divieto di spesa inizialmente attribuito al PDF. Nessun push, deploy o incasso reale durante questa correzione.

`RetailerCredit` usa esclusivamente disponibilità maturata dal ledger, con lock Retailer condivisi col payout, hold univoco per ordine/versione e movimenti append-only distinti reservation/release/purchase spend/difference spend/refund. Nessun balance aggiornato. Disponibilità e limiti rimborso vengono letti sulle righe correnti sotto lock, anche in REPEATABLE READ; fondi simulati isolati dai fondi reali.

Acquisto 500€ con credito200€: riserva200€, carta300€, consumo al successo, release su fallimento/scadenza/cancel. Nel multi-vendor `credit_by_vendor` e `card_by_vendor` sono snapshot immutabili ripartiti con resti maggiori e spareggio ID. Refund per vendor restituisce soltanto la sua quota di credito e carta; nessun doppio rimborso. Conguaglio300€ con credito100€: carta200€, accredito economico300€ al ricevente, commissione150 centesimi sul totale differenza. Solo credito non invoca il gateway, usa receipt interna idempotente e conferma senza completare la consegna.

CustomerPromotionalBonus resta separato, solo customer, max10% merce e mai conguagli. `MARKETPLACE_RETAILER_CREDIT_ENABLED=true`, `MARKETPLACE_EXECUTION_MODE=disabled` resta bloccante per nuove operazioni e addebiti. Reservation checkout30m da config/.env.example, senza costanti nella business logic o aggiornamenti delle scadenze storiche.

Migration additiva 000005: hold/colonne/indici/ownership/immutabilità; CHECK MySQL esteso senza cambiare importi storici. ALTER nativo SQLite conserva i trigger wallet preesistenti. Inclusa nell'allowlist cPanel; packaging/workflow restano quelli già verificati.

Test specifici: `RetailerCreditTest` (17 casi), `RetailerCreditConcurrencyTest` (5 gare MySQL: due ordini, stesso retry, payout concorrente, reversal concorrenti, retry reversal); `MarketplaceStripeConnectTest` ampliato per quota carta, rimborso fonti originali, solo credito senza SDK e late capture. Trasporto SDK soltanto mock, nessun PSP reale. Altri limiti live elencati sopra restano aperti; il credito spendibile non viene più elencato come requisito escluso.

Browser aggiuntivo: **20 controlli** sulle quattro schermate modificate (checkout retailer, riepilogo200€+300€, ordine/snapshot, termini credito scambio) a360/390/430/768/1366px. Form reali di login/riepilogo/conferma, nessun overflow né violazione axe selezionata. I190 controlli del precedente audit restano evidenza della base, distinti da queste nuove prove.

### Verifica definitiva della correzione

- MySQL8.4: **407 passati**, zero esclusioni, **2459 assertions**, 401,92s; database nuovo isolato conservato.
- SQLite: **380 passati**, **27 esclusi** perché richiedono MySQL, **2192 assertions**, 38,07s.
- Frontend build, Pint completo, Composer validate --strict e platform requirements passati; lint31 file PHP,70 link documentali validi, git diff --check pulito.
- Evidenze locali in `/workspace/work/retailer-credit/`: mysql-verified.log, sqlite-verified.log, build.log, pint-verified.log, composer-verified.log, platform-verified.log, php-lint.json, docs-links.json e browser.json. Nessun feed advisory PHP aggiuntivo consultato: blocco Composer già documentato sopra resta distinto dalla validazione.
- Nessuna pubblicazione GitHub o esecuzione cPanel/produzione; il nuovo commit include la correzione sopra il precedente audit locale.

### File della correzione

```text
.env.example
AGENTS.md
app/Actions/CreateMarketplaceOrder.php
app/Http/Controllers/CheckoutController.php
app/Http/Requests/CheckoutRequest.php
app/Models/ExchangeDifferencePayment.php
app/Models/MarketplacePayment.php
app/Models/WalletTransaction.php
app/Services/ExchangeStock.php
app/Services/ExchangeWorkflow.php
app/Services/Payments/ApplyExchangePaymentResult.php
app/Services/Payments/ApplyMarketplacePaymentResult.php
app/Services/Payments/FakeMarketplacePaymentGateway.php
app/Services/Payments/ReceiveMarketplaceStripeEvent.php
app/Services/Payments/ReconcileMarketplacePayments.php
app/Services/Payments/RefundMarketplaceVendor.php
app/Services/Payments/StartExchangeDifferencePayment.php
app/Services/Payments/StartMarketplacePayment.php
app/Services/Payments/StripeConnectMarketplaceGateway.php
app/Services/RetailerCredit.php
app/Services/StockReservations.php
app/Services/VendorOrderWorkflow.php
app/Services/WalletBalance.php
config/marketplace.php
database/migrations/2026_10_16_000005_add_retailer_credit_funding.php
deployment/additive-migrations.json
docs/adr/marketplace-funds-flow.md
docs/architecture.md
docs/cpanel-no-ssh-deployment.md
docs/database.md
docs/exchanges.md
docs/final-marketplace-audit.md
docs/marketplace-cart-orders.md
docs/marketplace-payment-readiness.md
docs/marketplace-payments.md
docs/pre-production-checklist.md
docs/requirements.md
docs/stock-reservations.md
resources/views/cart/checkout.blade.php
resources/views/cart/order.blade.php
resources/views/cart/review.blade.php
resources/views/exchanges/show.blade.php
tests/Feature/MarketplaceEconomicRulesTest.php
tests/Feature/MarketplaceStripeConnectTest.php
tests/Feature/RetailerCreditConcurrencyTest.php
tests/Feature/RetailerCreditTest.php
```
