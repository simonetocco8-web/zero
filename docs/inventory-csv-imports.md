# Caricamento Massivo Giacenze — EXISTING

La voce **Vendite → Caricamento Massivo Giacenze** è disponibile ai soli rivenditori autenticati e approvati, con la stessa Policy `operate` delle giacenze. Non sostituisce il form singolo, non importa fotografie e non contatta gestionali o provider esterni. Le cinque fasi sono caricamento, associazione colonne, valori/categorie, anteprima/conferma, elaborazione/report. I collegamenti ai passaggi precedenti conservano il file e permettono di rileggerlo cambiando delimitatore, codifica e intestazioni; ogni modifica invalida l’anteprima precedente.

## File e valori

Limiti iniziali: 10 MB, 10.000 record dati, 256 colonne, 40.000 byte per cella prima dei limiti applicativi. Delimitatori ASCII a un byte (anche TAB) e carattere di testo distinto; CSV con doppi apici raddoppiati, delimitatori e newline nei campi quotati. Il parser rifiuta apici malformati, colonne incoerenti, binari e controlli non ammessi. Le righe vuote sono saltate esplicitamente; il report indica la riga fisica originale, anche per record multilinea.

UTF-8/BOM, Windows-1252 e ISO-8859-1 sono selezionabili. Il rilevamento propone UTF-8 se valido, altrimenti Windows-1252: non può distinguere infallibilmente tutte le codifiche legacy; correggerlo dal primo passaggio quando necessario. Il modello salvato ripropone le opzioni di lettura, ma una struttura di intestazioni differente invalida le associazioni.

I suggerimenti dei campi devono essere confermati. Una colonna può alimentare un solo campo. Nome, descrizione, condizione, unità, provincia, quantità e prezzo Zero richiedono un’origine esplicita. Categorie selezionate fra quelle attive o associazioni esplicite dei valori CSV, senza creazione automatica. La provincia mantiene la validazione del form esistente (testo massimo 80 caratteri, accetta sigla o nome), le unità testo massimo 32; non è introdotto un elenco incompatibile con i dati esistenti.

Numeri: scelta esplicita italiano/internazionale, con assistenza sui campioni. Separatore migliaia in gruppi di tre e decimali fino a tre per quantità e due per prezzi. Mai float nei calcoli: `InventoryRules` converte in millesimi/centesimi e `InventoryCapacity` usa BigInteger per le quote, anche per frazioni di centesimo del valore di magazzino. Il totale visualizzato è arrotondato solo per presentazione, non per decidere la quota. SKU/EAN restano stringhe, gli zeri iniziali sono preservati; codici in notazione scientifica sono errori, non vengono ricostruiti. Booleani ammessi sì/si/no, yes/no, true/false, 1/0. I valori fissi di condizioni/booleani/trattamenti IVA hanno menu con etichette italiane; dati fiscali, descrizioni e costi non sono inventati.

## Scrittura e invarianti

`InventoryRequest::productRules` è la fonte comune delle regole del form e dell’import. `ImportWorkflow` applica inoltre consegna obbligatoria e categoria attiva. L’anteprima registra soltanto sessione/righe d’importazione: nessuna scrittura di prodotti. Per 500 o più record, la validazione avviene in un job; il rivenditore deve ancora esaminare e confermare l’anteprima risultante.

Ogni conferma richiede checkbox, intenzione draft/pending e token casuale dell’anteprima corrente. Le associazioni non sono più modificabili dopo l’avvio. Nessun identificativo retailer, stato amministrativo o flag di approvazione è accettato dal CSV. Le scritture usano `SaveInventoryItem`, Policies e `InventoryRules`; gli articoli pubblicati generano `proposed_data`/`change_pending`, preservando versione approvata, foto e stock effettivo fino alla revisione. Anche scegliendo “bozze”, una modifica a un articolo pubblicato segue questa procedura di proposta esistente.

Quote ricavate dai piani persistiti e proiettate nell’ordine delle righe: le righe oltre quota sono escluse. Il calcolo comune `InventoryCapacity` conserva l’uso più alto fra proposta e versione pubblicata. L’anteprima riusa una snapshot di piano e categorie per evitare letture ridondanti, senza cambiare le regole; una mappa delle categorie CSV evita ricerche quadratiche per riga. In scrittura la proiezione è costruita una volta per blocco sotto lock del rivenditore; ogni riga ricontrolla piano, categoria, autorizzazioni, SKU e prenotazioni. Tutti i prodotti dello stesso rivenditore condividono il lock già usato dal form, dalle reservation e dalla moderazione. Le righe scritte e il rispettivo esito/ID sono nella stessa transazione; savepoint per gli errori di validazione e commit per blocchi da 50. Un errore infrastrutturale annulla il blocco e viene ritentato, senza duplicare righe già commesse.

SKU già presenti, archiviati, soft deleted o riservati da proposte sono riconosciuti. Il confronto protegge anche varianti maiuscole/accenti senza modificare il codice originale; è mantenuta la validazione unique del database con la sua collation. In modalità aggiornamento un solo articolo attivo e autorizzato deve corrispondere allo SKU; uno SKU mancante crea un nuovo articolo. EAN e nome non sono chiavi univoche. SKU ripetuti nel file: viene considerata la prima occorrenza, le successive sono duplicate (anche se la prima contiene altri errori).

La conferma riserva un hash SHA-256 del file per rivenditore, con vincolo unico DB. Un file identico già confermato non può essere eseguito come una nuova operazione, anche senza SKU o cambiando mappatura/modalità. Consultare il report originale e usare **Riprendi** per operazioni fallite. Per un aggiornamento intenzionale successivo esportare un CSV aggiornato. Il vincolo non pretende di identificare file semanticamente uguali con byte differenti: per questi file l’identificazione resta lo SKU; senza SKU non esiste una chiave prodotto universale.

## Coda, ripresa e cPanel

Configurazione opzionale (default funzionanti):

| Variabile | Default | Uso |
| --- | --- | --- |
| `INVENTORY_IMPORT_MAX_KB` | `10240` | Massimo file, in KiB |
| `INVENTORY_IMPORT_MAX_ROWS` | `10000` | Massimo record dati |
| `INVENTORY_IMPORT_QUEUE_CONNECTION` | `database` | Connessione Laravel asincrona |
| `INVENTORY_IMPORT_RETENTION_DAYS` | `7` | Scadenza file temporanei e payload completati |

La coda si chiama `inventory-imports`. Aggiungere un worker persistente, separato da quelli finanziari:

```sh
php artisan queue:work database --queue=inventory-imports --sleep=2 --timeout=130 --tries=5
```

Adattare `database` se si configura un’altra connessione. Non usare sync/null. La connessione deve avere `retry_after` **maggiore di 130 secondi** (database nel progetto: 150); il job di validazione ha timeout 120 e quello di scrittura 90. Per Redis configurare equivalentemente visibilità/retry; restart dei worker dopo il rilascio e cache condivisa fra processo HTTP e worker, come già richiesto dal progetto. Configurazioni cache `array` per processi distinti non consentono il rilevamento. `queue:listen` è utilizzabile se il provider cPanel lo supporta e rispetta questi tempi; il percorso verificato è `queue:work`.

Il worker registra heartbeat sulla coda corretta; senza heartbeat recente la conferma e la validazione di file grandi sono bloccate con un messaggio comprensibile. Un arresto appena successivo alla conferma può comunque lasciare il job in attesa: la pagina mostra coda/progresso reale, non un falso completamento. Il polling avviene ogni 5 secondi. Cron Laravel ogni minuto avvia `inventory-imports:recover` per riaccodare lavori in attesa/interrotti da oltre 5 minuti. Esauriti i tentativi, lo stato diventa fallito; il proprietario può riprendere mantenendo gli esiti già scritti. La ripresa di una validazione fallita ritorna all’anteprima, non avvia l’importazione.

`inventory-imports:cleanup`, schedulato giornalmente, elimina CSV e celle/payload scaduti di sessioni non in esecuzione. Sessioni fallite restano recuperabili e non vengono eliminate automaticamente. Cronologia, SKU/nome limitati, errori ed ID articolo restano disponibili al proprietario; non è prevista una retention dei dati di audit in questa modifica. File privati sotto `storage/app/private/inventory-imports` con nomi UUID, download autorizzato e risposta attachment/no-store/nosniff. Mai copiarli in `public`. Report UTF-8 BOM con delimitatore `;`, quoting CSV e neutralizzazione di formule (=, +, -, @ e controlli iniziali), sempre autorizzato anche dopo la pulizia del sorgente. I log dei job non includono celle né binding SQL del CSV.

I limiti HTTP/PHP del provider devono consentire file da 10 MB (upload_max_filesize almeno 10M, post_max_size superiore). Il parser/upload sono sincroni e limitati; validazione grande e scrittura avvengono in coda. PHP CLI >=8.4.1, mbstring e gli altri requisiti già documentati; nessuna nuova dipendenza Composer/npm.

## Aggiornamento sicuro

1. Backup verificato di DB, upload privati, `.env` e APP_KEY; nessuna rigenerazione chiave.
2. Distribuire il pacchetto con vendor e asset compilati, usando il manifest generato dal commit pulito. La nuova migration `2026_11_06_000001_create_inventory_imports` è inclusa nell’allowlist delle migration additive. Non modificare migration storiche né usare fresh/refresh/reset.
3. Applicare il normale updater cPanel documentato o `php artisan migrate --force`; crea soltanto tre nuove tabelle e relativi indici/FK. Non cambia piani, pagamenti, stock, ordini o dati preesistenti.
4. Avviare il worker dedicato e confermare cron/cache condivisa; ricostruire config/route/view cache secondo la procedura di rilascio. Riavviare i worker esistenti per caricare il codice nuovo.
5. Collaudo con rivenditore di prova: CSV piccolo, anteprima, bozza, report, poi proposta modifica di articolo pubblicato e file più grande. Nessun articolo deve risultare pubblicato automaticamente. Verificare rilevamento worker e log senza esportare dati personali.
6. In caso di rollback del codice conservare le tabelle d’importazione e le giacenze già create: non eseguire down della migration in produzione. Disabilitare la coda/voce funzionale tramite rilascio controllato, mantenendo lo storico; nessuna cancellazione automatica di prodotti.

## Verifiche

`InventoryImportTest` copre parser/codifiche, numeri/codici, file e proprietà, mappatura/categorie, duplicati/replay/retry, quote, proposta moderata, prenotazioni successive all’anteprima, batch da 1.001 righe, validazione asincrona, modelli/rilettura/pulizia/report. `InventoryImportConcurrencyTest` richiede MySQL e avvia processi paralleli per lo stesso job e per due importazioni sull’ultimo posto FREE. Le suite usano DB nuovi/isolati e non resettano il database applicativo. Eseguire anche la suite esistente (regressione quote/moderazione/reservation), Pint e build Vite. Il collaudo browser verifica procedura completa e larghezze 1440/390 px.

### Esiti effettivi del collaudo (6 novembre 2026)

- Regressione completa SQLite: **661 passati, 30 saltati**, 4.613 asserzioni. I salti includono i test che richiedono lock MySQL.
- Regressione completa MySQL: **689 passati**, 4.895 asserzioni.
- Dopo l’ottimizzazione della snapshot di anteprima: suite pertinenti SQLite **85 passati, 2 saltati**, 430 asserzioni; MySQL **107 passati**, 625 asserzioni. Incluse importazioni, giacenze, accessi, fondamenta marketplace, quote e concorrenza/reservation.
- PHPUnit esercita anche importazione a blocchi di **1.001 prodotti**, retry e ripresa dopo un blocco già commesso.
- Prova aggiuntiva con worker reale e MySQL: **10.000 righe validate**, nessun prodotto creato/modificato durante l’anteprima; circa **30,4 secondi** nell’ambiente di sviluppo. Il tempo non è uno SLA per altri server; i limiti vanno adeguati alle risorse del provider mantenendo timeout inferiori a retry_after.
- Browser Chromium: percorso upload → mapping → configurazione → anteprima → conferma → report con una riga creata e una errata, a **1440×1000 e 390×844**, senza errori JavaScript o overflow della pagina. Tabelle scorrevoli e campi con label; screenshot locali di collaudo fuori dal repository.
- Laravel Pint, build Vite, route/config/view cache e `git diff --check` riusciti. Nessuna nuova dipendenza, lockfile e migration storiche invariati. Nessun deploy né test sul database di produzione.

### File coinvolti

- `.env.example`
- `README.md`
- `app/Actions/SaveInventoryItem.php`
- `app/Http/Controllers/InventoryImportController.php`
- `app/Http/Requests/InventoryImportOptionsRequest.php`
- `app/Http/Requests/InventoryImportUploadRequest.php`
- `app/Http/Requests/InventoryRequest.php`
- `app/Jobs/ProcessInventoryImport.php`
- `app/Jobs/ValidateInventoryImport.php`
- `app/Models/InventoryImport.php`
- `app/Models/InventoryImportRow.php`
- `app/Models/InventoryImportTemplate.php`
- `app/Policies/InventoryImportPolicy.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/InventoryCapacity.php`
- `app/Services/InventoryImport/CsvReader.php`
- `app/Services/InventoryImport/ImportFields.php`
- `app/Services/InventoryImport/ImportWorkflow.php`
- `app/Services/InventoryImport/ValidationSnapshot.php`
- `app/Services/InventoryRules.php`
- `config/inventory_imports.php`
- `config/navigation.php`
- `database/migrations/2026_11_06_000001_create_inventory_imports.php`
- `deployment/additive-migrations.json`
- `docs/inventory-csv-imports.md`
- `resources/views/inventory-imports/index.blade.php`
- `resources/views/inventory-imports/options.blade.php`
- `resources/views/inventory-imports/rows.blade.php`
- `resources/views/inventory-imports/show.blade.php`
- `resources/views/inventory-imports/status.blade.php`
- `resources/views/inventory-imports/summary.blade.php`
- `routes/console.php`
- `routes/web.php`
- `tests/Feature/InventoryImportConcurrencyTest.php`
- `tests/Feature/InventoryImportTest.php`
