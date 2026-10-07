# Requisiti di ZeroMagazzino

## Stato iniziale e ambito

Analisi del 6 ottobre 2026: checkout con `README.md` e `textElimina.txt`, entrambi contenenti `Test`; nessuna applicazione, dipendenza, migration, CI o suite di test. Nell'ambiente osservato sono assenti PHP, Composer e client MySQL; Node.js è disponibile (24.19.0). La compatibilità del runtime Laravel e la disponibilità del server MySQL devono essere verificate nella successiva inizializzazione.

Questa fase produce esclusivamente documentazione e regole. Il prodotto è un gestionale web B2B che collega rivenditori con giacenze edili a clienti interessati. Catalogo pubblico, checkout e pagamento del cliente restano nel negozio esterno.

## Attori e capacità

| Attore | Capacità previste |
| --- | --- |
| Rivenditore | Registrare azienda, scegliere piano, attendere verifica, gestire profilo e giacenze, inviare giacenze in approvazione, consultare richieste e vendite, visualizzare credito e richiedere bonifico |
| Amministratore | Approvare/rifiutare rivenditori e giacenze, pubblicare prodotti sul negozio, gestire richieste di bonifico, supervisionare piattaforma |
| Cliente | Consultare prodotti approvati e acquistare nel negozio esterno; inviare richiesta di disponibilità riferita a un prodotto |

Il cliente non richiede inizialmente un account nel gestionale. La posizione del form di disponibilità (negozio o endpoint del gestionale) resta da definire; in entrambi i casi validazione, consenso/privacy applicabile e protezione antiabuso sono necessari.

## Flussi e criteri di accettazione futuri

1. Registrazione: azienda e referente salvati, piano selezionato, stato in attesa; solo l'amministratore può approvare o rifiutare con motivazione. Un rivenditore non approvato non può inviare giacenze alla pubblicazione.
2. Giacenze: il proprietario crea bozze e foto, poi invia alla verifica. Solo giacenze approvate possono essere esportate. Un rifiuto deve mostrare motivazione e consentire correzione/reinvio.
3. Pubblicazione: approvazione locale e conferma remota sono stati distinti; errori e retry sono visibili. Il rivenditore non può forzare pubblicazione o autoapprovazione.
4. Richieste: riferite a un prodotto e al rivenditore competente; altri rivenditori non possono leggerle. Non costituiscono un ordine o una prenotazione automatica.
5. Vendite: eventi autenticati del negozio generano una rappresentazione locale di ordine e righe; duplicati non duplicano vendite, stock o credito. Gestire annullamenti e rimborsi secondo regole da confermare.
6. Credito: saldo derivato da movimenti tracciabili, con distinzione tra maturato, disponibile e riservato. La disponibilità dipende da una regola di maturazione esplicita.
7. Bonifico: richiesta ammessa solo entro il saldo disponibile, importo riservato atomicamente, revisione amministrativa e conferma dell'effettivo pagamento. Un'approvazione non equivale al trasferimento bancario.

## Requisiti trasversali

- Separazione dei dati per rivenditore, audit delle azioni sensibili, sicurezza descritta in `../AGENTS.md`.
- Mobile-first, accessibilità e feedback secondo `ui-ux.md`.
- Integrazione asincrona, osservabile e sostituibile secondo `integrations.md`.
- Nessuna promessa di consistenza immediata tra gestionale e negozio; mostrare stato e timestamp di sincronizzazione.
- Nessun pagamento, abbonamento Stripe o bonifico automatico da implementare prima della definizione delle relative regole.

## Assunzioni da confermare

- Nella versione attuale un profilo rivenditore appartiene a un solo utente e un utente può avere un solo profilo, come stabilito nel Prompt 2. Eventuali utenti aziendali aggiuntivi richiederanno un'estensione esplicita.
- EUR come valuta iniziale, con valuta comunque memorizzata; quantità anche frazionarie per materiali venduti a metri, peso o superficie.
- Una vendita può contenere righe di rivenditori diversi, con credito attribuito a ciascuna riga.
- Approvazione per giacenza/offerta del rivenditore; catalogo comune deduplicato non richiesto inizialmente.

## Decisioni ancora aperte

Provider ecommerce, API/webhook disponibili, titolare della vendita e incasso, gestione IVA/fatture, piani e pagamento dei piani, commissioni, spedizione/ritiro, resi e contestazioni, finestra di maturazione credito, soglie bonifico, verifica azienda e coordinate bancarie, stock autorevole e prenotazioni, conservazione dati e notifiche. Queste decisioni precedono le funzionalità coinvolte; non bloccano questa documentazione.

## Modello implementato (Prompt 2)

Schema, enum, relazioni, factory e piani FREE/PRO sono implementati secondo `database.md`. Le schermate rimangono placeholder. I limiti dei piani sono memorizzati e configurabili; enforcement delle quote, moderazione, prenotazioni di credito e pagamento sono workflow futuri.

## Flusso rivenditore implementato (Prompt 3)

Registrazione atomica di account, azienda e subscription con scelta FREE/PRO. Partita IVA italiana a 11 cifre univoca; la verifica fiscale effettiva resta amministrativa. FREE è attivo immediatamente; PRO è richiesto/pending, senza addebiti o benefit attivi finché il futuro flusso pagamento non lo attiva. Approvazione aziendale indipendente dal piano: pending, rejected e suspended mantengono accesso a dashboard/profilo, mentre stock, vendita, richieste e credito richiedono approvazione tramite middleware e Policy. Nessuna gestione delle giacenze è introdotta in questa fase.

Il profilo modifica esclusivamente i dati aziendali validati del proprietario, senza accettare stato, ownership o piano. IBAN cifrato, validazione checksum mod97 e visualizzazione limitata alle ultime quattro cifre: campo vuoto conserva il valore, nuovo valore lo sostituisce; escluso dai dati riproposti dopo errori. Le modifiche aziendali conservano lo stato di verifica attuale; una politica di nuova verifica per cambi di ragione sociale/IVA potrà essere introdotta esplicitamente.

Dashboard con dati del solo proprietario: piano attivo/richiesto, commissione del piano attivo, numero/valore delle giacenze EUR non archiviate, ultime cinque richieste e credito disponibile dal ledger. Somme e formattazione monetaria senza float.

Amministratore: elenco a card paginato, approvazione/rifiuto dei soli profili pending; rifiuto con motivazione obbligatoria. Action con lock, transazione, Policy e audit append-only; revisione duplicata rifiutata. Nessuna modifica al pagamento/attivazione subscription. Reinvio di iscrizioni rifiutate e sospensione sono fasi successive.

## Richieste di disponibilità (Prompt 6)

Form pubblico GET/POST `/products/{item}/request`, senza autenticazione ma con sessione e CSRF. Il negozio può collegare questa URL, inclusa nei nuovi snapshot gateway come availability_request_url; usare un link alla pagina, senza disabilitare CSRF per POST cross-origin. Nessuna API di raccolta pubblica o esposizione dei contatti.

Disponibile solo per published/change_pending con published_at e rivenditore approved; anche change_pending usa esclusivamente la versione approvata. POST ricontrolla prodotto/rivenditore sotto lock, ricava l’ownership dal database, forza stato new e data consenso server-side. Richiesta non equivale ad acquisto o prenotazione e può chiedere più della quantità esposta.

Campi aggiunti: customer_company, quantity_milliunits interi, privacy_accepted_at e privacy_policy_version. I record storici mantengono null per quantità/consenso, senza inventare evidenze retroattive. Quantità strettamente positiva, fino a tre decimali; nome/email obbligatori; campi testo senza markup e con limiti di lunghezza; Blade esegue escaping. Honeypot contact_website obbligatoriamente vuoto; 5 POST/minuto e 20/ora per IP, condivisi fra prodotti, includendo tentativi non validi. Nessun IP memorizzato nella richiesta. Configurare trusted proxy solo quando verificato dal deployment.

Privacy: consenso obbligatorio e versione dell’informativa configurabile tramite PRIVACY_POLICY_VERSION. PRIVACY_POLICY_URL sostituisce la pagina informativa interna: l’informativa definitiva deve indicare identità del titolare, recapiti, conservazione e diritti per il deployment effettivo. Dati personali nascosti dalla serializzazione generica, consultabili solo nella lista autenticata del proprietario; header private/no-store. Nessuna comunicazione o marketing automatico.

Pagina rivenditore paginata da 15 richieste, card mobile e tabella desktop; modifica consentita soltanto allo stato (new/contacted/closed) con Policy, middleware, CSRF e lock. Timestamp contatto conservato come storico; chiusura impostata entrando in closed e rimossa riaprendo. Dashboard conta richieste nuove e mostra le ultime cinque del solo proprietario.

## Dominio economico implementato (Prompt 7)

Vendite confermate normalizzate in EUR tramite SaleAccounting, senza ecommerce reale. Lordo = prezzo unitario × quantità, arrotondato HalfUp al centesimo, meno sconto riga. Commissione dal piano valido al timestamp della vendita; percentuale in basis point, commissione e netto salvati su ogni riga. Cambio piano e replay non ricalcolano le righe esistenti.

Rimborsi totali/parziali con identificativo esterno stabile: restituiscono proporzionalmente anche la commissione. Il calcolo cumulativo elimina scarti fra più rimborsi; non è possibile rimborsare più del lordo originale. Movimenti originali conservati, nessun aggiornamento o cancellazione del ledger.

Pagina Credito: disponibile, netto maturato storico (al netto dei rimborsi, indipendente dai bonifici), riserve pendenti, commissione corrente, storici paginati di vendite/movimenti/bonifici. Solo il proprietario approved accede; IBAN mascherato. Richiesta server-side dell’intero credito EUR, IBAN valido, una richiesta pending per rivenditore/valuta, riserva immediata sotto lock. Rifiuto rilascia la riserva; pagamento registra rilascio e uscita, senza doppio addebito.

Assunzioni attuali: credito disponibile immediatamente, configurabile con CREDIT_MATURATION_DAYS (default 0); vendita normalizzata significa pagamento confermato. Un rimborso dopo il pagamento del bonifico può produrre debito: resta registrato e impedisce ulteriori prelievi. Un rimborso su credito riservato può rendere insufficiente il saldo per segnare paid; l’amministratore deve rifiutare e rilasciare la riserva prima di una nuova richiesta. L’esecuzione bancaria resta esterna. “Tienili per acquistare prodotti” è solo informativo; nessun credito al checkout. Stock, imposte, costo trasporto, resi logistici e provider reale richiedono il successivo contratto ecommerce.

## Stripe e adapter (Prompt 8)

Checkout PRO ricorrente mensile/annuale, prezzi derivati dai piani e verificati con Stripe, nessuna credenziale reale. Stato browser non concede benefit. Checkout completato, active/updated/cancelled e insoluti sono acquisiti con firma, idempotenza, inbox, worker e retry. Active + ultima fattura paid abilita PRO; insoluti ripristinano FREE senza eliminare giacenze. Annullamento a fine periodo mantiene i benefit fino alla scadenza pagata; cancellazione effettiva ripristina FREE. La verifica aziendale rimane amministrativa e indipendente.

Gateway fake predefinito e provider futuri esplicitamente configurabili. Ingresso order.paid/refund con firma dell’adapter e DTO validati; nessun checkout ecommerce nuovo o credito automatico per acquisti. Prima della produzione confermare grace period insoluti, fiscalità/proration e contratto stock/provider; collaudare una sandbox Stripe reale.
