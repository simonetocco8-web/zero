# Aree riservate marketplace

> Riallineamento successivo: [audit economico marketplace](marketplace-payments.md) e [ADR funds flow](adr/marketplace-funds-flow.md) prevalgono sui limiti storici qui descritti (fee scambio, TTL, trasporto e rimborso senza restock). I risultati di verifica riportati sotto descrivono la fase precedente.

## EXISTING

Cliente e retailer compratore condividono I miei ordini, Pagamenti, Consegne, Indirizzi e Profilo. Le query sono filtrate dal server per cliente; le policy proteggono anche i dettagli, le modifiche e le conferme di ricezione. Il retailer conserva il proprio ruolo. Gli indirizzi sono personali: modificarli o eliminarli non modifica gli snapshot degli ordini.

Vendite mostra soltanto i sottoordini del retailer, le relative righe, gli importi e l'indirizzo necessario alla consegna. Non espone gli altri venditori né il totale complessivo dell'ordine cliente. Scambi e Conguagli mostrano soltanto le proprie pratiche. Credito e richieste bonifico riutilizzano la contabilità esistente, senza duplicare movimenti o accreditarvi automaticamente ordini marketplace.

L'admin dispone di categorie e bonifici esistenti, più elenchi di ordini, sottoordini, scambi, pagamenti, rimborsi, commissioni e riserve. Filtri per stato, ID, cliente, retailer e periodo, ordinamento stabile e paginazione avvengono nel database. Le commissioni mostrano snapshot marketplace e registrazioni legacy: un importo non definito resta tale, senza introdurre aliquote commerciali.

Navigazione mobile raggruppata in Marketplace, Vendite e Scambi, menu espandibile e barra inferiore limitata a quattro/cinque destinazioni. Le pagine private hanno noindex.

## Consegne e rimborsi simulati

Solo un sottoordine pagato può passare da `paid` a `preparing`, quindi a `shipped` oppure `ready_for_pickup` secondo la modalità concordata, infine `completed`. Cliente proprietario, venditore approved e admin possono confermare la ricezione nelle rispettive aree. Tracking e timestamp non cambiano gli snapshot economici. Transizioni serializzate riutilizzano i lock del dominio stock/ordini; un evento append-only registra ogni passaggio. Il padre aggrega lo stato dei sottoordini.

L'admin può registrare un rimborso integrale **esclusivamente simulato** per un sottoordine con pagamento fake riuscito e totali definiti. L'importo viene dallo snapshot server, con un solo rimborso per sottoordine. Non viene trasferito denaro, riassortito stock o creato credito automaticamente. Pagamento padre `refunded` solo dopo il rimborso di tutti i sottoordini. Rimborsi parziali, restituzioni fisiche, provider reale e contabilità commerciale definitiva restano PLANNED/OPEN BUSINESS DECISION.

## Notifiche persistenti e email asincrone

`MarketplaceNotices` pubblica, nella transazione del dominio, una notifica Laravel database e una riga outbox con chiave univoca evento/destinatario. L'email Laravel Mail viene eseguita da `DeliverMarketplaceNotice` dopo il commit. La request non contatta SMTP. Database ed email sono canali configurabili in `config/marketplace.php`; l'utente può disattivare le email senza perdere l'inbox. Il worker ricontrolla la preferenza prima dell'invio.

Destinatari:

- ordine ricevuto: ciascun venditore, con link al proprio sottoordine;
- pagamento confermato e annullamento ordine: cliente e venditori interessati;
- spedizione, pronto al ritiro, completamento e rimborso: cliente e solo il venditore coinvolto;
- nuova proposta: controparte; controproposta: destinatario della modifica;
- doppia accettazione, rifiuto, scadenza, annullamento e completamento scambio: entrambe le parti;
- conguaglio da pagare: debitore; conguaglio ricevuto: creditore; conferma pagamento: debitore.

Payload ed email contengono titolo, riferimento pratica e link autenticato. Nessuna coordinata bancaria, indirizzo, nota libera o dettaglio degli altri venditori viene incluso. Inbox e marcatura come letta sono limitate al destinatario.

Avvio worker:

```sh
php artisan queue:work database --queue=marketplace-notifications --sleep=3 --timeout=60 --tries=5
```

La connessione dedicata `MARKETPLACE_NOTIFICATION_QUEUE_CONNECTION=database` resta asincrona anche se altri flussi usano `QUEUE_CONNECTION=sync`; `sync` è rifiutata per l'email marketplace. Configurare il mailer e supervisionare worker/scheduler in produzione. In sviluppo usare mailer `log`/`array`, senza consegna esterna.

`marketplace:dispatch-notifications`, schedulato ogni minuto, recupera fino a 100 notifiche incomplete non accodate negli ultimi cinque minuti. Il worker reclama la riga con lock DB, lease di due minuti, massimo cinque tentativi e backoff. Inbox deduplicata tramite UUID; completamento impedisce nuovi invii. Un crash dopo l'accettazione SMTP e prima della registrazione dell'esito può duplicare l'email: consegna almeno una volta, non garanzia SMTP exactly-once. Monitorare `failed_jobs` e outbox incomplete/esaurite; le righe non vengono cancellate automaticamente. I tempi di recupero sono parametri tecnici iniziali.

## Migration e verifica

La migration `2026_10_13_000006` aggiunge inbox, outbox, eventi di consegna, rimborsi simulati, preferenza email e campi tracking nullable; nessuna conversione distruttiva. Eventi e rimborsi hanno protezioni append-only e vincoli monetari. Migrare con `php artisan migrate`; mai fresh, refresh o reset.

Test delle aree verificano ownership, isolamento multi-vendor, transizioni, destinatari, eventi ripetuti, opt-out, Mail fake e Queue fake. Test con coda database reale verificano commit/rollback, worker, recupero e lease. Suite MySQL include concorrenza checkout/scambi/stock; SQLite copre le restanti funzionalità. Build e verifiche browser responsive/accessibilità completano la validazione.

Verifica eseguita il 13 ottobre 2026: MySQL 332 test superati / 1.920 assertion, SQLite 317 superati / 1.817 assertion e 15 test di concorrenza MySQL saltati. Build Vite e Pint riusciti. Browser Chromium: 120 controlli su 30 pagine a larghezze 320/375/768/1280 px, nessuna violazione axe WCAG A/AA e nessuno scroll orizzontale. Upgrade del database applicativo verificato tramite hash delle colonne originali di 18 tabelle: dati preservati; seconda migration senza operazioni. Coda database, worker, scheduler, homepage e health check avviati nell'ambiente cloud; istruzioni `start_skill` salvate in bozza per i riavvii, senza pubblicazione automatica.
