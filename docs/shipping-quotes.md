# Quotazioni spedizioni — stato della release

## Stato e confini

Branch `feat/paccofacile-shipping-quotes`, base `main` `2170a40b89caeca8fb952803a75da3b091d8c8ba`.

**EXISTING:** architettura, persistenza cifrata, imballaggi esatti, import, calcolatore, selezione delle tariffe, collegamenti a carrello/checkout e preventivi distinti negli scambi. Percorso economico dinamico verificabile esclusivamente con fixture e autorizzazione fiscale esplicita nei test.

**BLOCKED:** provider Paccofacile reale e attivazione economica. Non è una integrazione live funzionante e non viene presentata come tale. Il provider reale non effettua HTTP e risponde con un errore applicativo esplicito. Non acquista etichette e non prenota corrieri.

**OPEN BUSINESS DECISION:** chi fattura/incassa il trasporto e chi paga il provider; trattamento IVA effettivo delle tariffe e dei supplementi; eventuale maggiorazione e gestione del rimborso trasporto. Nessuna nuova movimentazione wallet/Stripe. `shipping.checkout_verified` è deliberatamente `false` nel codice, senza variabile di attivazione economica. Occorre una modifica revisionata dopo verifica del contratto e del modello fiscale.

## Audit preliminare

| Componenti verificati | Invariante e applicazione |
| --- | --- |
| `InventoryItem`, `InventoryRequest`, `InventoryRules`, `SaveInventoryItem`, `InventoryController` | Quantità in millesimi; nuove dimensioni nullable nel DB, conversione esatta al salvataggio; lettura dei dati approvati nel marketplace. Nessun backfill inventato. |
| `proposed_data`, `change_pending`, `ReviewInventoryItem` | Misure/regola di imballaggio entrano nello stesso array commerciale moderato; la versione pubblicata resta attiva fino all’approvazione. |
| `InventoryImport`, `ImportFields`, `ImportWorkflow` | Mappatura colonna/valore fisso, normalizzazione numeri italiana/internazionale, anteprima e validazione per riga, conferma esplicita esistente. Obbligo imballaggio fissato nelle impostazioni all’upload; import già registrati senza marker continuano senza dati inventati. |
| `Retailer`, `RetailerCompanyRequest`, `UpdateRetailerProfile` | CAP stringa e Paese ISO nullable aggiuntivi; mutazioni dell’origine scadono i preventivi aperti sotto lock retailer. Il fingerprint rileva anche modifiche fuori dall’action. Provincia normalizzata, sigla obbligatoria per indirizzi italiani; nessuna conversione arbitraria dai nomi estesi. |
| `CustomerAddress`, `SaveCustomerAddress`, rubrica | Modello e ownership esistenti riutilizzati. Default precompilato nel popup; altri indirizzi selezionabili. Alternativo temporaneo nel preventivo cifrato e sessione; salvataggio in rubrica solo su consenso, senza cambiare i default. Nessun uso implicito del profilo retailer come destinazione cliente. |
| `MarketplaceController`, `marketplace.show` | Accesso riservato e versione approvata invariati; pulsante solo con flag, spedizione disponibile e capacità acquisto; nessuna API al caricamento. |
| `MarketplaceCart`, `MarketplaceQuote`, `CheckoutController`, `CreateMarketplaceOrder` | Owner, stock, lock e idempotenza conservati. Quote riferita all’intero gruppo del venditore, destinazione identica al riepilogo e ordine. Nessun HTTP nei lock. |
| `ExchangeRules`, `ExchangeWorkflow`, versioni sigillate | Due contesti indipendenti, tariffe associate alla chiave della proposta e al lato, origine/destinazione reciproche. Controproposta scade i riferimenti vecchi senza modificare versioni/acceptance. Tariffe rivalidate all’accettazione; percorso dinamico resta informativo. |
| IVA, commissioni, Connect, credito, bonus, reservation, refund | IVA esplicita attuale in `MarketplaceTaxSnapshot`; commissione sulla sola merce netta. Bonus esclude trasporto. Nessun cambiamento a ledger, Connect o rimborsi. Ordini/reservation già creati continuano sugli snapshot storici. |
| Deployment ZIP | Una migration nuova additiva, inclusa nella registry dopo revisione; migration storiche inalterate. Nessun `.env` reale, APP_KEY, seed o DB di produzione modificato. |

## Contratto Paccofacile: evidenza del 7 novembre 2026

Fonti ufficiali effettivamente recuperate via HTTPS:

- https://www.paccofacile.it/integrazioni/paccofacile-api
- https://www.paccofacile.it/integrazioni/sandbox

È stato esaminato anche lo script pubblico `assets/standard/js/api.js` collegato dalle pagine: non fornisce il contratto di quotazione. Le pagine non autenticate mostrano introduzione, accesso/registrazione e gestione delle chiavi. La pagina API nomina Token, API Key e Account Number; questo **non** verifica come trasmetterli. Nessun contratto completo di quotazione è esposto nelle pagine recuperate. Non sono state generate credenziali né effettuate operazioni su un account Paccofacile.

| Informazione | Stato |
| --- | --- |
| URL endpoint sandbox/produzione della quotazione | Non verificato: nessun URL API inventato o configurabile dal browser |
| Metodo HTTP, autenticazione, header, schemi/errori | Non verificati |
| Colli multipli, dimensioni, precisioni, peso, imballaggio, indirizzi | Schema e limiti provider non verificati |
| Corriere, servizio, importo, valuta, imposte, supplementi, identificativo, tempi | Schema risposta non verificato |
| Quota/rate limit, validità, territori e condizioni vincolanti/indicative | Non verificati |
| IVA inclusa/esclusa e fatturazione/incasso | Non verificati: checkout dinamico bloccato |

`ShippingQuoteProviderInterface` e `FakeShippingProvider` definiscono un **contratto interno di test**, non lo schema wire Paccofacile. Le tariffe TEST non sono prezzi reali. Non esiste un collaudo sulla sandbox ufficiale. I test Laravel HTTP mocking verificano che l’adapter chiuso non invii richieste; non simulano un protocollo Paccofacile inventato.

Prima di completare l’adapter ottenere documentazione tecnica ufficiale/versionata e credenziali sandbox mediante impostazioni sicure. Implementare Laravel HTTP Client con TLS, host ufficiale fisso, timeout e gestione 401/403/429/timeout/risposte malformate; retry soltanto se la quotazione è documentata come sicura/idempotente. Servono contract test sullo schema autentico e collaudo sandbox per attivare il provider.

## Modello e imballaggi

Dimensioni in millimetri, peso lordo in grammi e capacità in millesimi dell’unità di vendita: interi, conversioni `Brick\Math`. UI cm/kg; CSV cm/mm e kg/g con scelta esplicita (anche valore fisso). Precisioni non rappresentabili rifiutate. Limiti **applicativi**, non Paccofacile: 10.000 mm per lato, 1.000.000 g, 100 colli per richiesta. Un provider verificato dovrà applicare anche i propri limiti documentati.

- `pieces_capacity`: solo unità `piece`, capacità intera e quantità acquistata intera; arrotondamento superiore del numero di colli. Il venditore dichiara esplicitamente che anche il collo parzialmente pieno usa queste dimensioni e questo peso lordo.
- `whole_parcels`: quantità acquistata multiplo esatto della capacità. Vale anche per kg, metri e frazioni. Resto non nullo: nessun preventivo automatico.
- Un collo per ogni blocco dichiarato; nessuna aggregazione fisica arbitraria di prodotti diversi. I colli dei prodotti dello stesso venditore vengono inviati insieme alla singola richiesta di gruppo.

Nuovi salvataggi/modifiche richiedono tutti i dati quando `SHIPPING_QUOTES_ENABLED=true`. Durante lo staging con flag disattivato il comportamento precedente rimane disponibile e i campi sono facoltativi; ciò permette di completare gradualmente i dati prima della transizione. La migration da sola non invalida gli articoli storici. Con flag attivo, articoli legacy incompleti restano visibili/modificabili ma non generano tariffe. Import creati prima dell’attivazione mantengono le proprie regole; i prodotti incompleti risultanti richiedono completamento per quotare.

## Flusso, cache, sicurezza

`ShippingPackaging` converte/valida; `ShippingParcelBuilder` costruisce i colli; `ShippingQuoteService` normalizza gli indirizzi e prepara il fingerprint; il provider restituisce solo tariffe normalizzate. La risposta viene verificata prima di persistere le tariffe.

Ogni tariffa ha UUID, utente, origine, contesto, fingerprint, snapshot cifrato di indirizzi/righe/colli, importi originali/finali, servizio, valuta, trattamento fiscale disponibile e scadenza. Selezione riferita al record del proprietario; nessun costo accettato dal frontend. Cache e lock sono per provider/utente/fingerprint, con verifica della scadenza del record. Stesse richieste ravvicinate riusano i record; prodotti, quantità, dati di imballaggio e indirizzi diversi cambiano il fingerprint. Cambio servizio significa selezionare un altro UUID. Scadenza conservativa **applicativa**, non garantita dal provider; tariffa indicativa da riconfermare.

Endpoint autenticati, CSRF, autorizzazione acquisto/negoziazione, throttle 10/minuto per utente autenticato, accesso indirizzi per ownership, risposte private/no-store. Nessun URL del provider o credential nel browser, nessun log di payload personali. La rubrica viene interrogata solo per l’utente corrente. Negli scambi solo partecipanti autorizzati PRO; la destinazione è il profilo aziendale della controparte, dichiarato nell’interfaccia.

`shipping-quotes:prune`, schedulato giornalmente, scade i record scaduti e rimuove dopo sette giorni quelli temporanei scaduti, anche negli scambi. Nei record usati lo snapshot temporaneo cifrato viene svuotato dopo sette giorni; importi e riferimenti restano per audit. Gli snapshot necessari degli ordini e delle versioni restano soggetti alla retention storica del progetto. Le tariffe di vendita scadute ancora referenziate dal carrello diventano “da ricalcolare”, senza fallback economico. Impostare cache condivisa database/Redis in deployment; array solo per test. Il lock sul DB rimane la verifica finale indipendentemente dalla cache.

## Carrello, checkout, IVA e incassi

Flag attivo: tariffa per rivenditore, colli di tutti i suoi prodotti, nessuna somma delle tariffe individuali. Attribuzione esatta dell’intero costo alla prima riga per ID prodotto, zero sulle altre; totale gruppo preservato senza arrotondamenti di allocazione. Metodo, modalità di tariffazione `legacy/dynamic` e riferimento sono conservati sulle righe carrello. L’indicazione dinamica sopravvive alle invalidazioni del riferimento, impedendo fallback anche dopo modifiche alla quantità. Le mutazioni quantità/modalità invalidano tutto il gruppo; rimozioni e altri cambi vengono comunque rilevati dal fingerprint.

Il popup di prodotto quota il carrello risultante, includendo la quantità da aggiungere e gli altri prodotti di quel rivenditore, e lo dichiara. Un carrello cambiato nel frattempo viene respinto alla selezione. Il checkout offre un calcolatore per rivenditore per cambiare servizio/destinazione e mostra un nuovo riepilogo prima della conferma. Un indirizzo alternativo non salvato viene ricostruito dallo snapshot posseduto e trasferito nella sessione del riepilogo; resta nell’ordine, senza creazione implicita in rubrica. Destinazioni diverse tra gruppi o rispetto alla selezione del checkout vengono rifiutate.

`final_cents` è l’importo finale lordo cliente del contratto interno. IVA inclusa verificata: scorporo tramite `MarketplaceTaxSnapshot`, senza secondo addebito. IVA non applicabile: zero. Trattamento ignoto/escluso non riconciliato al finale: blocco del percorso economico. Aliquota merce indipendente. Commissioni sulla sola base imponibile merci, invariata; trasporto escluso da bonus, nessun nuovo movimento fondi. I test abilitano **solo in memoria** `shipping.checkout_verified` per verificare gli snapshot e le somme con fixture fiscali note.

Non c’è HTTP sotto lock. Riepilogo usa preventivi già acquisiti; conferma rilegge prodotti, retailer, cart, destinazione e tariffa sotto i lock esistenti, confronta fingerprint/scadenza/importo e hash del riepilogo, crea snapshot e marca usati i riferimenti nella stessa transazione delle reservation. Una tariffa scaduta richiede una nuova richiesta esplicita. Il retry idempotente dell’ordine restituisce l’ordine già creato.

Flag disattivato: percorso storico **legacy esplicito**, `shipping_cost_cents` e fiscalità storica conservati. Il costo manuale non viene mai usato come fallback dopo errore API. Un carrello già dinamico non passa silenziosamente a tariffa manuale se il flag viene spento: il checkout shipping viene sospeso. Ordini/reservation già creati non vengono riscritti.

## Scambi

Contesti `exchange:{request_key}:a/b`, due origini/destinazioni e due insiemi di colli. Riferimento, importo, rate e snapshot sono dentro `delivery_a/b` immutabili, con `payer_side` e `settlement` esistenti. Nessuna modifica automatica al conguaglio merci o alle commissioni; `external` e rimborso al mittente restano distinti e separati. Una controproposta richiede nuove quote con nuova chiave e scade le precedenti. All’accettazione si confrontano i parametri attuali; scadenza o modifiche richiedono una nuova versione. Nessun acquisto spedizione automatico. Accettazioni dinamiche restano bloccate fino alla verifica economico/fiscale; scambi storici già accettati continuano sullo storico esistente.

## Configurazione e attivazione progressiva

```dotenv
SHIPPING_QUOTES_ENABLED=false
SHIPPING_PROVIDER=paccofacile
PACCOFACILE_ENVIRONMENT=sandbox
PACCOFACILE_API_KEY=
PACCOFACILE_TOKEN=
PACCOFACILE_ACCOUNT_NUMBER=
SHIPPING_QUOTE_TTL_SECONDS=300
SHIPPING_QUOTE_CACHE_SECONDS=60
```

Nomi delle credenziali sono segnaposti dalla richiesta, non prova dell’autenticazione API. Nessun URL API finché non verificato. Fake disponibile solo in `testing`, mai come fallback operativo.

1. Backup verificato; revisionare migration additiva/registry e manifest ZIP; distribuire prima in staging con flag spento. Non eseguire comandi distruttivi o cambiare APP_KEY.
2. Applicare soltanto migration autorizzate tramite il workflow ZIP/preflight esistente. Completare CAP/Paese/provincia e dati di imballaggio degli articoli.
3. Ottenere contratto e credenziali sandbox, completare adapter e test ufficiali. Verificare limiti e colli multipli, IVA/oneri, scadenze, disponibilità territoriale e natura indicativa del prezzo.
4. Attivare in staging la sola quotazione informativa, verificando errori, indirizzi temporanei, accessi e rate limit. Cache condivisa, scheduler e cifratura operativi.
5. Approvare modello fiscale/fatturazione/incasso e relativa implementazione prima di cambiare `checkout_verified`. Nuova revisione per fondi/trasferimenti eventualmente necessari; non basta impostare credenziali.
6. Collaudare multi-vendor, merce/spedizione/IVA/commissioni, concurrency e scambi nelle due direzioni; attivare gradualmente in produzione solo dopo autorizzazione separata.

Rollback operativo: spegnere flag e invalidare quote aperte se necessario, preservare schema/snapshot/storico; carrelli dinamici shipping sospesi, ritiro disponibile. Non eseguire down/rollback distruttivi; ordini già acquisiti restano gestiti dai flussi esistenti. Un eventuale ripristino di codice precedente va revisionato perché non deve leggere carrelli dinamici come spedizioni legacy.

## Verifica e limiti residui

Vedere [risultati di verifica](shipping-quotes-validation.md) per comandi, esiti e distinzione fake/sandbox. L’attivazione reale è bloccata deliberatamente; il criterio cliente tariffa autentica → pagamento autentico non può essere dichiarato soddisfatto senza contratto e verifiche ufficiali. I passaggi tecnici sono coperti con fake e fixture; nessun merge o deploy effettuato.
