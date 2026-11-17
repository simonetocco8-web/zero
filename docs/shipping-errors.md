# Errori del calcolo spedizioni — 16 novembre 2026

## EXISTING — analisi e correzioni

La scheda prodotto, il carrello, la revisione spedizioni e il checkout usano `components/shipping-calculator.blade.php`, `ShippingQuoteRequest`, `ShippingQuoteController` e `ShippingQuoteService`. Proposte e controproposte scambio usano `ExchangeProposalRequest`, `ExchangeShippingQuoteController` e lo stesso servizio. Le selezioni delle tariffe restano POST browser con CSRF e validazione finale degli snapshot. La diagnostica CLI usa lo stesso adapter, senza nuovi endpoint.

Cause riscontrate nel codice precedente:

- `catch` JavaScript visualizzava direttamente `error.message`: un rifiuto di `fetch` diventava letteralmente `Failed to fetch`; non è prova di una causa specifica di rete.
- `response.json()` era incondizionato: HTML, redirect verso login o risposte non JSON diventavano eccezioni tecniche di parsing.
- Mancava un timeout del browser. Nei soli scambi mancavano abort/versionamento e blocco dei clic ripetuti: risposte vecchie potevano ripopolare tariffe dopo modifiche del modulo.
- Il backend raggruppava timeout, problemi DNS/TLS e connessione in una sola spiegazione di timeout; gli HTTP del provider diventavano tutti `ValidationException` 422. Peso e dimensioni mancanti condividevano un errore di imballaggio.
- La validazione del mittente poteva indicare al compratore di correggere il proprio CAP, benché il dato mancante appartenesse al rivenditore.
- Un errore `quote_error` dell'opzione Sendcloud era sempre scartato: senza alternative valide, anche un guasto del corriere appariva come assenza di tariffe.

Non sono stati osservati né provati guasti CORS, SSL/TLS o di produzione. L'URL del browser è generato da una route sullo stesso dominio; Sendcloud è chiamato soltanto dal backend all'endpoint costante v3, con TLS verificato e redirect disabilitati. Il browser non distingue sempre DNS, blocchi, CORS, TLS e interruzioni: per questi casi rimane il messaggio prudente `SHIPPING_NETWORK`. Un redirect bloccato usa la stessa spiegazione. HTTP 401/419 identificano sessione/CSRF e richiedono ricaricamento/accesso.

## Contratto e interfaccia

Gli errori delle sole tre route di quotazione (più l'alias amministrativo esistente) sono normalizzati anche quando originano da middleware o eccezioni impreviste. Struttura: `success:false`, `error:{code,message,fields,retryable,reference}`. Per compatibilità Laravel restano `message` e `errors`, sanitizzati. Le risposte riuscite conservano `quotes`/`informational` o `sides`; nessuna tariffa quotabile restituisce ora 422 `SHIPPING_NO_RATES`. Nessun endpoint rinominato. `ShippingFailure` estende `ValidationException`, preservando form e chiamanti CLI.

Errori vicino al calcolo, regione `role=alert`, campi con `aria-invalid` ed evidenziazione, riferimento per l'assistenza e pulsante Riprova soltanto per errori temporanei. I campi alternativi conservano i testi associati. Importi e dati inseriti non vengono cancellati. Lo stato vuoto esplicita che la spedizione non è ancora calcolata. Abort e numero di versione proteggono entrambi i calcolatori; il pulsante si sblocca in `finally` per la sola richiesta corrente. Le tariffe vecchie vengono rimosse quando cambiano i dati.

Timeout browser 55 secondi, per comprendere le due quotazioni sequenziali degli scambi; backend connessione fino a 5 secondi e singola risposta fino a 20, secondo la configurazione esistente. Nessun retry automatico aggiunto. Restano cache, lock per utente/fingerprint, budget condiviso e cooldown `Retry-After`. I clic duplicati durante il calcolo sono ignorati. Il backend può terminare una richiesta già avviata dopo l'abort browser, ma il risultato vecchio non viene applicato.

## Codici e messaggi

I codici sono condivisi da tutte le risposte backend; i casi senza risposta HTTP hanno messaggi equivalenti nel modulo JavaScript comune. Il CAP specifico riguarda il formato validato localmente, senza sostenere che CAP e città corrispondano.

| Codice | Messaggio utente | Riprova | HTTP |
| --- | --- | --- | --- |
| `SHIPPING_UNEXPECTED` | Non siamo riusciti a calcolare la spedizione. Riprova; se il problema persiste, contatta l’assistenza. | Sì | 500 |
| `SHIPPING_NETWORK` | Non è stato possibile comunicare con il servizio di calcolo delle spedizioni. Riprova. Se il problema persiste, contatta l’assistenza. | Sì | 503 |
| `SHIPPING_TIMEOUT` | Il calcolo della spedizione sta impiegando troppo tempo. Riprova tra qualche istante. | Sì | 504 |
| `SHIPPING_INVALID_POSTAL_CODE` | Il formato del CAP inserito non è valido. Verifica il CAP e riprova. | No | 422 |
| `SHIPPING_INCOMPLETE_ADDRESS` | Per calcolare la spedizione è necessario completare l’indirizzo di consegna. Controlla i campi indicati. | No | 422 |
| `SHIPPING_INVALID_DATA` | I dati della spedizione non sono validi. Verifica indirizzi, quantità e imballaggi prima di riprovare. | No | 422 |
| `SHIPPING_MISSING_WEIGHT` | Uno o più articoli non hanno un peso configurato. Contatta l’assistenza per calcolare la spedizione. | No | 422 |
| `SHIPPING_MISSING_DIMENSIONS` | Mancano le dimensioni necessarie per calcolare questa spedizione. Contatta l’assistenza. | No | 422 |
| `SHIPPING_PACKAGING` | La quantità non è compatibile con i colli dichiarati oppure i dati di imballaggio sono incompleti. Verifica la quantità o contatta il rivenditore. | No | 422 |
| `SHIPPING_NO_RATES` | Al momento non sono disponibili tariffe per questa spedizione. Verifica i dati o prova un altro indirizzo. | No | 422 |
| `SHIPPING_CONFIGURATION` | Il servizio di spedizione è temporaneamente non disponibile per un problema di configurazione. Contatta l’assistenza. | No | 503 |
| `SHIPPING_RATE_LIMITED` | Sono state richieste troppe tariffe. Attendi prima di riprovare. | Sì | 429 |
| `SHIPPING_UNAVAILABLE` | Il servizio di calcolo delle spedizioni è temporaneamente non disponibile. Riprova più tardi. | Sì | 503 |
| `SHIPPING_INVALID_RESPONSE` | Il servizio di spedizione ha restituito una risposta non utilizzabile. Riprova più tardi. | Sì | 502 |
| `SHIPPING_IN_PROGRESS` | Un calcolo per questi dati è già in corso. Attendi qualche istante e riprova. | Sì | 409 |
| `SHIPPING_SESSION_EXPIRED` | La sessione è scaduta. Ricarica la pagina e accedi nuovamente prima di calcolare la spedizione. | No | 419 |
| `SHIPPING_FORBIDDEN` | Non puoi calcolare questa spedizione con l’account attuale. Verifica l’accesso o contatta l’assistenza. | No | 403 |

## Sendcloud: evidenze, diagnosi e privacy

Consultato il 16 novembre 2026 [OpenAPI ufficiale Shipping Options v3](https://sendcloud.dev/.openapi/v3/shipping-options/openapi.yaml), SHA-256 `c585a54c824ac54b0801816c2c6635d75d0ca68127fbb4ba8209ce5c7b128d42`. Riferimenti: `errors`, `ErrorObject`, `quote-error` e risposta POST 400. Non vengono dedotti codici da `detail` o testi liberi.

- 400/422: dati non quotabili, senza incolpare il CAP. Solo puntatori JSON:API documentati `/to_address/...` evidenziano campi della destinazione; `required` identifica dati mancanti. I puntatori dell'origine non evidenziano i campi del compratore.
- 401/403 provider: configurazione/autorizzazione, distinti dal 403 dell'account applicativo.
- 429: limite richieste, conservando cooldown e budget; 5xx/redirect provider: indisponibilità.
- Timeout: classi tipizzate `ConnectTimeoutException`, `NetworkTimeoutException`, `ResponseTimeoutException` della dipendenza Guzzle 8.2 fissata nel lockfile; le altre connessioni restano ambigue. Nessun controllo basato su frammenti del messaggio tecnico.
- JSON invalido/schema radice errato: risposta inutilizzabile. `data:[]`, `data:null`, prezzi mancanti o servizi incompatibili: nessuna tariffa, senza prezzi inventati.
- In assenza di tariffe valide, `carrier_api_error`, `carrier_api_unavailable`, `internal_error` identificano indisponibilità; `carrier_auth_failed` identifica configurazione. Le alternative valide restano utilizzabili. Le altre opzioni scartate conservano le regole precedenti di compatibilità/prezzo.

Log Laravel minimizzati: UUID di riferimento, codice locale, classe eccezione, eventuali stato HTTP/provider e codici opzione esplicitamente riconosciuti. Nessun body provider o richiesta, messaggio eccezione, indirizzo, header, credenziale o stack trace. Anche con debug attivo le risposte di quotazione vengono sanitizzate.

Tariffe, supplementi, fiscalità, commissioni e flusso dei pagamenti non sono modificati. Nessuna migration. `shipping.checkout_verified` rimane bloccato; il calcolo informativo non abilita acquisti, etichette o prenotazioni. La verifica delle tariffe obbligatorie e la validazione finale degli snapshot restano server-side.

## File modificati

- `app/Services/Shipping/ShippingErrors.php`, `ShippingFailure.php`: catalogo, contratto, log e compatibilità con validazione.
- `app/Services/Shipping/SendcloudShippingProvider.php`: classificazione HTTP/trasporto e codici documentati Sendcloud.
- `app/Services/Shipping/ShippingParcelBuilder.php`, `ShippingQuoteService.php`: errori specifici delle misure e degli indirizzi, mittente separato.
- `app/Http/Controllers/ShippingQuoteController.php`, `ExchangeShippingQuoteController.php`: assenza di tariffe uniforme.
- `bootstrap/app.php`: intercettazione circoscritta alle quotazioni, inclusi middleware.
- `resources/js/shipping.js`, `resources/js/app.js`: trasporto comune, timeout, campi, abort e protezione dalle risposte obsolete.
- `resources/views/components/shipping-calculator.blade.php`, `resources/views/exchanges/form.blade.php`: interfaccia uniforme e stato non calcolato.
- `tests/Feature/ShippingErrorsTest.php`, `SendcloudShippingProviderTest.php`, `SendcloudShippingIntegrationTest.php`, `ShippingQuoteTest.php`, `PurchaseSummaryTest.php`, `tests/js/shipping.test.mjs`: regressioni e nuovi casi.
- `package.json`, `.github/workflows/ci.yml`: test JavaScript eseguibili anche in CI senza dipendenze aggiuntive.
- `docs/shipping-errors.md`, `docs/sendcloud-v3.md`: contratto e verifica.

## Verifica

- Suite completa SQLite: **757 superati, 32 saltati, 5334 asserzioni**, nessun fallimento. I casi di concorrenza specifici MySQL non vengono dichiarati verificati da SQLite.
- Suite spedizioni MySQL 8.4, database nuovi isolati: **91 superati, 643 asserzioni**, inclusa concorrenza checkout con consumo unico del preventivo e della riserva stock. Nessun fallimento.
- Suite mirata finale (`ShippingErrorsTest|ShippingQuoteTest|SendcloudShipping|PurchaseSummaryTest`): **88 superati, 588 asserzioni**. Eseguita anche dopo il rafforzamento della classificazione delle risposte del provider.
- JavaScript (`npm run test:shipping`): **13 superati**, esecuzione aggiunta alla CI.
- `npm run build`, `vendor/bin/pint --test`, `git diff --check` e compilazione/sgombero cache Blade: superati.
- Chromium sul Blade reale con HTTP intercettato: **375 e 1536 px**, rete/HTML/CAP, conservazione dati, evidenziazione accessibile, Riprova condizionale, pulsante sbloccato, risposta obsoleta ignorata, assenza overflow orizzontale nel dialog e restituzione del focus all'apertura/chiusura. Nessuna eccezione JavaScript della pagina.

| Requisito | Evidenza automatizzata |
| --- | --- |
| Successo | Adapter v3, payload e importi esatti; integrazione prodotto/carrello, cache e scambi |
| CAP formalmente errato | `ShippingErrorsTest`, validazione locale e campi identificati |
| Indirizzo incompleto | `ShippingErrorsTest`, `PurchaseSummaryTest`, JSON:API `required` |
| Peso/dimensioni mancanti | `ShippingErrorsTest`, `ShippingQuoteTest` |
| Nessuna tariffa | Adapter vuoto/null/non prezzato; integrazione `SHIPPING_NO_RATES` |
| 400/422 | Dataprovider HTTP e puntatori documentati della destinazione |
| 401/403 provider | Codice configurazione distinto dal divieto applicativo |
| 429 | Codice locale, budget condiviso e cooldown Retry-After |
| 500/503 | Codice indisponibilità senza body tecnico né retry POST |
| Timeout | Eccezione tipizzata Guzzle e timer browser |
| Failed to fetch | Test JavaScript e browser con errore di rete |
| HTML/JSON invalido | Adapter, trasporto browser e schema di successo |
| Sessione/CSRF | Renderer con AuthenticationException/TokenMismatchException; HTTP 401/419 anche HTML nel browser |
| Richieste ripetute/modifiche | Clic duplicati ignorati, abort/versione, risposta obsoleta; cache e lock esistenti |
| Invarianti economiche | Carrello identico dopo errore, nessun ordine creato; snapshot, scadenze e checkout informativo bloccato nelle suite esistenti |

I test usano HTTP fake e database isolati; nessuna chiamata autenticata reale o reset del database esistente.

## Criticità residue

Il collaudo autenticato Sendcloud, delle origini e delle condizioni contrattuali resta necessario prima dell'attivazione operativa. Non è possibile verificare qui eventuali problemi CORS/TLS/proxy specifici dell'installazione di produzione. Una sessione scaduta richiede ricaricamento, senza tentativi automatici con token scaduti. I log minimizzati favoriscono la correlazione, ma non conservano payload per diagnosi contenenti dati personali. Il trasporto rimane informativo e gli acquisti attualmente disabilitati restano disabilitati.
