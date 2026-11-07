# Sendcloud API v3 — quotazioni informative

Stato del 7 novembre 2026, PR #28, branch `feat/paccofacile-shipping-quotes`, base di questo intervento `e0c4868056bf65fe568fc237195b7fecef308d35`.

**EXISTING:** adapter HTTP Sendcloud v3 e integrazione con il flusso della PR #28. **DISABLED per default:** `SHIPPING_QUOTES_ENABLED=false`. **BLOCKED:** pagamento del trasporto, creazione etichette, prenotazioni e nuovi incassi; `shipping.checkout_verified=false` resta invariato nel codice.

**Non collaudato con un account autentico:** l’ambiente non dispone di chiavi Sendcloud autorizzate. Tutti i test dell’adapter e il browser usano HTTP fake e fixture conformi allo schema ufficiale. Nessuna tariffa mock viene usata come fallback operativo. Il codice è predisposto per richieste informative autentiche quando verranno configurati e verificati account e credenziali; non si dichiara verificata l’operatività commerciale delle origini dei rivenditori.

## Fonti ufficiali effettivamente consultate

- [Quick Start](https://support.sendcloud.com/hc/en-us/articles/360024967012-Sendcloud-API-documentation-and-Quick-Start-Guide)
- [POST Shipping Options v3](https://sendcloud.dev/api/v3/shipping-options/return-a-list-of-available-shipping-options)
- [Shipping options & quotes](https://sendcloud.dev/docs/shipments/shipping-options-and-quotes)
- [Autenticazione](https://sendcloud.dev/docs/getting-started/authentication)
- [Marketplace centralizzato](https://sendcloud.dev/docs/marketplaces/centralized-integration-guidelines)
- [Marketplace decentralizzato](https://sendcloud.dev/docs/marketplaces/decentralized-integration-guidelines)
- [OpenAPI Shipping Options v3](https://sendcloud.dev/.openapi/v3/shipping-options/openapi.yaml)
- [Rate limits](https://sendcloud.dev/docs/getting-started/rate-limits)
- [Multicollo](https://sendcloud.dev/docs/shipments/multicollo)

OpenAPI recuperato il 7 novembre 2026: SHA-256 `69208e9720cc3ccb1adf44c03e8bea6a640c27be9426eb70321eebea5ce5c4ff`. Percorso studiato: `paths./shipping-options.post`, schema `shipping-option-filter`, `shipping-option`, `shipping-quote`, `shipping-option-address`, `str-dimensions`, `str-weight`, `shipping-price-breakdown`.

Alcune guide introduttive/marketplace contengono esempi legacy: vengono utilizzate per autenticazione e modello commerciale, **mai per chiamare API v2**. Nessun endpoint v2 viene invocato dal nuovo adapter.

## Contratto implementato

Unica destinazione HTTP: **`POST https://panel.sendcloud.sc/api/v3/shipping-options`**. URL costante nel codice, non accettato dal browser e non configurabile tramite input utente. Laravel HTTP Client, `withBasicAuth(public_key, private_key)`, JSON e `Accept: application/json`, TLS verificato, nessun redirect, connessione massimo 5 secondi, risposta 15 secondi per default. Timeout configurabile tra 1 e 20 secondi per restare entro il lock di deduplicazione di 30 secondi. Nessun retry POST automatico.

```json
{
  "from_address": {
    "country_code": "IT",
    "postal_code": "00100",
    "city": "Roma",
    "state_province_code": "IT-RM",
    "address_line_1": "Via Esempio 1"
  },
  "to_address": {
    "country_code": "IT",
    "postal_code": "20100",
    "city": "Milano",
    "state_province_code": "IT-MI",
    "address_line_1": "Via Esempio 2"
  },
  "parcels": [{
    "dimensions": {"length": "30.1", "width": "20", "height": "15", "unit": "cm"},
    "weight": {"value": "1.234", "unit": "kg"}
  }],
  "calculate_quotes": true
}
```

Nessun filtro `carrier_code`, `contract_id` o assicurazione viene aggiunto. L’API confronta i corrieri abilitati nell’account; secondo la guida ogni chiamata usa **un contratto predefinito per corriere**, non tutti i contratti contemporaneamente. Confrontare contratti alternativi dello stesso corriere richiederebbe richieste esplicite distinte, fuori da questa fase.

Gli oggetti indirizzo sostituiscono completamente i campi legacy piatti. Sono inviati soltanto campi supportati dallo schema: Paese, CAP, città, provincia e indirizzo. L’eventuale complemento indirizzo viene unito alla prima riga. Nome destinatario, telefono e altre informazioni non necessarie alla quotazione non vengono trasmessi.

La provincia viene normalizzata al formato ISO 3166-2 dichiarato nello schema: `RM` con Paese `IT` diventa `IT-RM`; un codice già completo e coerente è conservato. Nomi estesi o codici di un altro Paese sono rifiutati, senza dedurre sigle. Nota documentale: uno degli esempi dell’OpenAPI mostra `RM-IT`, in contrasto con la descrizione ISO 3166-2; l’adapter usa la forma ISO `IT-RM`, da confermare durante il collaudo autenticato.

## Colli, precisione e limiti

`ShippingPackaging` e `ShippingParcelBuilder` restano invariati: millimetri/grammi interi, quantità in millesimi e regole di confezionamento esplicite. Le conversioni avvengono tramite `Brick\Math`, con stringhe decimali esatte: 301 mm → `30.1` cm, 1234 g → `1.234` kg. Nessun float per importi o misure.

Una richiesta contiene tutti i colli del gruppo di uno stesso rivenditore, stessa origine e destinazione. L’adapter impone un limite prudenziale di **50 colli**, coerente con il massimo riportato nel nodo parcels dell’OpenAPI (il documento colloca min/max nel nodo item). Restano anche i limiti applicativi esistenti: 10.000 mm per lato e 1.000.000 g per collo. Il limite comune del builder resta invariato per fake/storico; Sendcloud applica il proprio limite prima di inviare HTTP.

Le opzioni restituite devono essere compatibili con peso minimo/massimo e dimensioni massime, quando forniti. Le conversioni dei limiti sono esatte anche per unità alternative documentate. Opzioni esplicitamente non multicollo per richieste multicollo, resi e consegne a service point che richiedono una selezione non presente nel calcolatore vengono escluse. La compatibilità effettiva di ogni origine/contratto resta da validare con Sendcloud.

## Mappatura della risposta, imposte e prezzi mancanti

La risposta deve essere JSON con `data` array o `null`, come da schema. `data: []` e `data: null` producono nessuna tariffa e un messaggio comprensibile nell’interfaccia. Risposte malformate non vengono trasformate in prezzi.

| Sorgente v3 | Dato interno persistito |
| --- | --- |
| `data[].code` | `rate.shipping_option_code` |
| `carrier.code/name` | `carrier_code` / `carrier` |
| `name` | `service` |
| `quotes[].price.total.value/currency` | `original_cents`, `final_cents`, `currency` |
| `quotes[].price.breakdown[]` | `price_breakdown[]`: tipo, etichetta, centesimi, valuta |
| `quotes[].estimated_surcharges[]` | `estimated_surcharges[]`, separati dal totale |
| `quotes[].lead_time` | `lead_time_hours`, indicazione in ore non garantita |
| `contract.id`, `quote_source` | Identificativo contratto e provenienza prezzo, senza client ID o credenziali |
| `quote_error` | Opzione non utilizzabile: esclusa, nessun fallback |

Si usa soltanto **il totale della spedizione**, mai la somma/moltiplicazione dei prezzi per collo o delle quote. Il servizio accetta fino a 1.000 tariffe normalizzate, senza tagliare silenziosamente le alternative a 100; risposte oltre i limiti difensivi di dimensione/quantità sono rifiutate esplicitamente. Gli oggetti `quotes` possono essere array con più preventivi: ciascuno valido diventa una tariffa selezionabile, con deduplicazione delle alternative identiche. Codice opzione e metadati rimangono server-side nel record selezionato e negli snapshot ordine/versione esistenti.

Valuta gestita: EUR. `converted_price` non viene usato per inventare un cambio. Importo mancante, nullo, negativo, numero JSON float, valuta non EUR o valore non rappresentabile esattamente in centesimi: tariffa scartata. Uno zero **esplicitamente restituito** è ammesso, distinto da un prezzo mancante. Componenti di prezzo invalidi rendono inutilizzabile la relativa tariffa. Nessun body d’errore del provider viene ritrasmesso all’utente.

Il totale ufficiale è mostrato come **indicativo**. I supplementi stimati restano separati, visibili e non sommati: l’OpenAPI li descrive come stime che possono cambiare il futuro importo fatturato. L’IVA non è esplicitata come trattamento globale verificato nello schema: `tax_treatment` e aliquota rimangono `null`, nessun 22% implicito e nessuna pretesa di totale IVA inclusa. Anche una componente denominata “incl. taxes” non verifica l’IVA dell’intera spedizione.

Il calcolatore conserva popup, rubrica/default, indirizzo alternativo, quantità, selezione e carrello; aggiunge codice opzione, componenti e supplementi distinti. L’ordinamento è stabile su importo e identità del servizio anche nelle risposte lette dalla cache; non dipende da `created_at` o dall’ordine SQL.

## Autorizzazioni, cache e budget API

`ShippingQuoteService`, ownership/Policies, throttle per utente, cifratura snapshot, scadenza e pulizia restano attivi. Il servizio gestisce anche la contesa sul lock di deduplicazione con un messaggio applicativo, evitando richieste duplicate.

`SendcloudAccountResolver::credentials(retailerId)` usa oggi le chiavi centralizzate. Il parametro retailer consente una futura implementazione con credenziali cifrate o OAuth senza cambiare il calcolatore. Un contesto opaco derivato dalle credenziali entra nel fingerprint cifrato: la rotazione o il cambio account invalida cache e uso dei preventivi precedenti. Nessun valore della credenziale viene registrato, salvato nel preventivo o restituito al browser.

Sendcloud documenta per POST **100 richieste/minuto e burst 15/secondo**. L’adapter applica un budget locale prudenziale condiviso per account di **80/minuto e 8/secondo**, oltre al throttle per utente già esistente e alla cache per parametri effettivi. Le richieste servite dalla cache non consumano il budget esterno. La risposta 429 imposta un cooldown condiviso rispettando `Retry-After` (secondi/data, limite operativo 1–3600 secondi; in assenza di valore valido, 60 secondi). Nessuna raffica di retry.

Cache database/Redis condivisa richiesta tra processi/worker; array soltanto nei test. Gestiti 400/422 per dati non quotabili, 401/403 configurazione/autorizzazione, 429, 5xx, redirect rifiutati, timeout, JSON e dimensione della risposta. Errori sanitizzati, nessun logging di chiavi o indirizzi. L’adapter rifiuta HTTP quando esiste una transazione DB aperta: quotazione prima dei lock, validazione finale sotto lock senza HTTP.

## Marketplace centralizzato e decentralizzato

| Modello | Account e contratti | Implicazioni di fatturazione |
| --- | --- | --- |
| Centralizzato, implementato per la quotazione | Un account della piattaforma, corrieri abilitati e contratti predefiniti di quell’account; ogni retailer invia la propria origine | In futuro Sendcloud addebita all’account centrale le etichette acquistate per i venditori. ZeroMagazzino dovrebbe definire chi compra/fattura/incassa/rimborsa il trasporto. Non viene implementato alcun automatismo finanziario. |
| Decentralizzato, futuro | Account, chiavi cifrate/OAuth, indirizzi e contratti specifici per retailer | Le etichette e i relativi costi sono attribuiti all’account/contratto del merchant; i contratti diretti possono essere fatturati dal corriere al titolare. Riduce la necessità di riaddebito della piattaforma, ma richiede onboarding, consenso, isolamento e gestione connessioni. |

Le API accettano `from_address`, ma questo non dimostra che ogni origine abbia diritto alle stesse condizioni contrattuali. Occorre verificare su un account autorizzato origini italiane differenti, eventuali indirizzi mittente registrati, corrieri e contratti disponibili, supplementi e limitazioni territoriali. Il resolver è il punto di estensione; nessun onboarding decentralizzato, nuova tabella o raccolta di chiavi dai retailer è introdotto adesso.

## Configurazione e diagnostica manuale

```dotenv
SHIPPING_PROVIDER=sendcloud
SHIPPING_QUOTES_ENABLED=false
SENDCLOUD_API_PUBLIC_KEY=
SENDCLOUD_API_PRIVATE_KEY=
SENDCLOUD_API_TIMEOUT=15
SHIPPING_QUOTE_TTL_SECONDS=300
SHIPPING_QUOTE_CACHE_SECONDS=60
```

Creare un account Sendcloud e un’integrazione API, abilitare i corrieri o collegare contratti con prezzi disponibili. Basic Auth richiede che l’integrazione non imponga OAuth2. Impostare le chiavi solo tramite impostazioni sicure dell’ambiente; non comunicarle nel prompt, non inserirle in command line, repository, frontend o file di input diagnostico. La configurazione di un mezzo di pagamento per future etichette è distinta da questa integrazione di quotazione.

`php artisan shipping:diagnose-sendcloud --input=/percorso/locale/prova.json`

Comando solo manuale, non schedulato e non esposto via route. Il JSON locale contiene `retailer_id`, `origin`, `destination` nei campi normalizzati esistenti e `parcels` con millimetri/grammi interi. Esempio di collo: `{"length_mm":300,"width_mm":200,"height_mm":150,"weight_g":1000}`. Non include chiavi API. Il comando legge le credenziali dalla configurazione e chiama **solo** l’endpoint di quotazione, anche quando il flag del calcolatore è spento: è destinato al collaudo manuale prima dell’attivazione UI. Non persiste preventivi o indirizzi e stampa soltanto opzione, corriere, servizio, importo e numero di supplementi; errori sanitizzati. Proteggere e rimuovere il file locale di indirizzi dopo la prova.

Nell’ambiente corrente il comando è stato eseguito soltanto con HTTP fake. Non sono state effettuate chiamate autenticate reali.

## Carrello, checkout, scambi e storico

Restano invariati il preventivo unico per gruppo retailer, la distinzione tra origini, le invalidazioni su prodotti/quantità/indirizzi/imballaggi e la destinazione conservata nell’ordine. Il pagamento resta bloccato. Le commissioni continuano a escludere il trasporto; stock, reservation e idempotenza non sono modificati. Gli scambi quotano A→B e B→A separatamente; snapshot e condizioni payer/settlement restano nelle versioni immutabili.

Nessuna migration nuova o storica modificata: i nuovi metadati usano il JSON `rate` esistente. Paccofacile resta codice inattivo e non è selezionabile dal binding; nessuna quotazione/storia precedente viene cancellata. I riferimenti a un provider differente vengono rifiutati alla validazione, richiedendo una nuova scelta. Fake resta riservato ai test. Modalità legacy esplicita a flag spento, senza fallback silenzioso per carrelli dinamici.

## Attivazione progressiva e rollback

1. Revisionare codice, test e ZIP in staging; preservare `.env`, APP_KEY, storico e migration.
2. Configurare un account autorizzato e corrieri/contratti/prezzi; verificare Basic Auth, caching condiviso e cron di pulizia.
3. Eseguire manualmente la diagnostica verso almeno due origini retailer, più colli e destinazioni differenti. Verificare codici provincia ISO, supplementi, limiti dei corrieri e condizioni economiche reali.
4. Attivare eventualmente **solo il calcolo informativo** dopo il collaudo; nessuna API al caricamento della pagina e nessun acquisto etichette.
5. Prima di qualsiasi pagamento/etichetta serve una revisione separata del modello fiscale, di acquisto/incasso e di rimborso. `checkout_verified=false` non cambia impostando le chiavi.
6. Rollback operativo: disabilitare `SHIPPING_QUOTES_ENABLED`, preservando dati e schema. Carrelli dinamici sospesi per shipping; ritiro ancora disponibile. Non riattivare Paccofacile né sostituire silenziosamente i prezzi con quelli manuali.

Esiti e file modificati in [sendcloud-v3-validation.md](sendcloud-v3-validation.md). Nessun merge, deploy, acquisto etichetta o pagamento reale autorizzato/eseguito da questo lavoro.
