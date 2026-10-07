# Architettura proposta

## Impostazione

Monolite Laravel standard con rendering Blade. Due aree autenticate, rivenditore e amministratore, condividono dominio e database; il cliente usa il negozio esterno. Nessun microservizio o frontend SPA previsto.

PHP >= 8.3 e MySQL >= 8.0 sono requisiti minimi. Prima dello scaffold scegliere una versione Laravel stabile supportata dal runtime effettivo, fissare dipendenze e lockfile, e verificare estensioni PHP e versione Node richiesta da Vite. Non è ancora dichiarata una versione Laravel verificata.

## Organizzazione prevista

| Posizione Laravel | Responsabilità |
| --- | --- |
| `app/Models` | Persistenza, relazioni, cast; niente chiamate al negozio |
| `app/Enums` | Stati e valori di dominio chiusi |
| `app/Policies` | Ruolo, ownership e permessi sulle risorse |
| `app/Http/Requests` | Validazione e autorizzazione dell'input |
| `app/Http/Controllers` | Coordinare richiesta, Action e risposta |
| `app/Actions` | Casi d'uso transazionali: approvare, inviare, riservare credito |
| `app/Services` | Calcoli condivisi e adapter ecommerce |
| `app/Events`, `app/Listeners`, `app/Jobs` | Effetti asincroni, sincronizzazione e notifiche |
| `resources/views` | Blade e componenti UI condivisi |
| `routes/web.php` | Browser con sessione e CSRF |
| Route API dedicate | Webhook firmati e integrazioni; niente sessioni browser implicite |

Le directory sono una proposta, non file già implementati. Crearle solo quando necessarie. Binding del contratto ecommerce tramite container e configurazione Laravel.

## Stati e invarianti

- Rivenditore: `pending → approved/rejected`; sospensione amministrativa prevista separatamente. Reinvio dopo rifiuto soggetto a nuova verifica.
- Giacenza: `draft → pending → published/rejected`, con `change_pending` e `archived`; una modifica commerciale dopo approvazione richiede nuova verifica. Prima della modifica definire se ritirare immediatamente l'offerta precedente o mantenerne una versione approvata immutabile.
- Pubblicazione: `not_published → pending → published/failed`, con ritiro e sincronizzazione trattati separatamente dalla moderazione.
- Bonifico: `pending → paid/rejected`; riserva del credito all'invio, rilascio al rifiuto e consumo alla conferma del pagamento. Annullamenti, errori bancari e rimborsi richiedono transizioni esplicite prima dell'implementazione.
- Stato vendita derivato dal provider e normalizzato; non equiparare creazione ordine, incasso e maturazione credito.

Ogni transizione passa da una Action autorizzata, con transazione e audit. Vincoli e lock difendono gli invarianti anche con richieste concorrenti. I controller non accettano dal client proprietario, saldo o stato approvato come dati fidati.

## Esecuzione e consistenza

HTTP applicativo, worker queue e scheduler sono processi separati. Per il primo deployment queue database Laravel, senza obbligo Redis; rivalutare Redis su misure operative. I job vengono dispatchati dopo commit.

Per la pubblicazione usare una semplice tabella di operazioni di integrazione: record scritto nella transazione di approvazione, job asincrono e scheduler che recupera operazioni rimaste in attesa. Evita perdere una pubblicazione se il processo termina tra commit e invio del job. Nessun bus distribuito necessario.

Non eseguire chiamate remote dentro transazioni con lock. Timeout, retry limitati, idempotenza e riconciliazione gestiscono il fallimento parziale. Disponibilità e overselling richiedono un accordo con il provider: un lock locale non protegge il checkout remoto.

## Sicurezza e operatività

Policies per ogni risorsa, middleware di autenticazione e stato aziendale, query limitate al proprietario. Configurare HTTPS, sessioni sicure, rate limit e storage immagini privato; pubblicazione delle immagini approvate tramite un meccanismo controllato compatibile con il provider.

Audit strutturato per moderazione, modifiche commerciali, credito e bonifici; log tecnici con correlation ID senza secrets. Backup MySQL e storage con prova di ripristino, monitoraggio failed jobs e ritardi di sincronizzazione sono requisiti del deployment.

## Verifica futura

PHPUnit: unit test calcoli e transizioni; feature test accessi e Policies; test MySQL per vincoli/concorrenza; test adapter/webhook con fake, firma e duplicati. Build Vite e smoke test HTTP per il workflow web. Nessuna suite esiste ancora e nessun comportamento applicativo è dichiarato funzionante.

## Bootstrap implementato (Prompt 1)

La root ora contiene Laravel 13 con Breeze Blade; il runtime verificato è PHP 8.4, con requisito PHP >= 8.3. Lockfile Composer/npm inclusi. Sono implementati solo autenticazione e struttura UI: nessuna Action di giacenze, pubblicazione, credito o bonifico.

`UserRole` e `UserPolicy` separano le due aree. `RegisterRequest` e le altre Form Requests di autenticazione validano input; Actions dedicate gestiscono creazione account e scrittura password. Il cast `hashed` di `User` applica l'hashing Laravel. Il ruolo non è assegnabile tramite input pubblico.

Sessioni, cache e queue sono configurate su database; migration standard Laravel più ruolo utente. La suite rapida usa SQLite e viene verificata anche su un database MySQL dedicato. Dettagli di avvio, email locali e bootstrap amministratore in `../README.md`.

## Domain model implementato (Prompt 2)

Lo schema effettivo è descritto in `database.md` e sostituisce i nomi e gli stati della proposta iniziale quando divergenti. Models/Enums/Policies/Factories sono presenti; `MoneyMath` e `WalletBalance` offrono calcoli esatti e letture del ledger. `RecordAdministrativeAction` è la primitiva di audit per le future Actions, che dovranno chiamarla nella propria transazione.

L'associazione aziendale attuale è `User hasOne Retailer`, con `retailers.user_id` univoco. Una subscription attiva per rivenditore, con storico mantenuto. Nessun controller commerciale, quota, bonifico o webhook è ancora implementato. I limiti dei piani sono dati centralizzati, non condizioni sparse nei controller.
