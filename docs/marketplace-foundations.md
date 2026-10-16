# Fondamenta marketplace — 13 ottobre 2026

## Funzioni e limiti

EXISTING: categorie amministrabili in `/admin/categories`, registrazione cliente `/customer/register`, area cliente base accessibile anche ai retailer, anagrafica User/CustomerAddress, categoria referenziata e consegna/IVA su InventoryItem. La registrazione cliente non crea Retailer/Subscription, forza il ruolo customer e usa hashing/password confirmation/session regeneration; registration retailer precedente conservata. Non creare account admin dal form.

Acquisti: capacità customer/retailer, senza mutazione del ruolo; admin escluso. Scambi: capacità retailer approved + PRO attivo + benefit del piano. PLANNED: catalogo interno, carrello, ordini, reservation, workflow scambio e pagamenti; nessuno è implementato in questo incremento.

CustomerAddress usa user_id, label, recipient_name, company, righe indirizzo, CAP/città/provincia/paese/telefono e default spedizione/fatturazione. SaveCustomerAddress valida dati, autorizza owner e serializza cambi default sul lock User; unique DB impedisce doppi default. Non esiste ancora un'interfaccia indirizzi o checkout.

Categorie: nome/slug/descrizione/stato/ordine liberi all'admin. Slug unico; cancellazione solo senza referenze approvate o in proposta. Preferire disattivazione se già in uso; nessun prodotto o storico viene cancellato. La UI rende selezionabili solo le attive o quelle già assegnate alla giacenza che si modifica.

Costo consegna: input decimale fino a due cifre, memorizzato in centesimi interi con cast esatto e vincolo non negativo. Null = da definire, 0 = gratuito. Delivery notes descrittive. Config `marketplace.vat_treatments` permette included/excluded/not_applicable e futuri codici, con etichette; null = da definire. Nessun prezzo ricalcolato/aliquota/IVA presunta. Cambiare/eliminare codici già usati richiede conservare le etichette o un piano di migrazione, non attribuire silenziosamente una nuova semantica.

## Upgrade sicuro di un database esistente

1. Fare backup coerente DB/upload/APP_KEY e provarne il restore su copia isolata; fermare nuove scritture inventario durante il backfill. Verificare PHP/MySQL/TRIGGER/CHECK e privilegi migration come in deployment.md.
2. Distribuire codice e asset della stessa release, mantenendo .env/storage/chiave e dipendenze fissate.
3. Eseguire **soltanto** `php artisan migrate --force`; nessun fresh/refresh/reset o modifica delle migration precedenti. Le quattro categorie iniziali vengono inserite dalla nuova migration, non da un seed che sovrascriva personalizzazioni.
4. Verificare conteggi/ID/prezzi/quantità/ledger invariati, category_id e categorie delle proposte, customer registration/admin CRUD e form prodotto. Ricostruire cache e riavviare worker secondo deployment.md.

Il backfill confronta nomi legacy esatti (compresi spazi, accenti e stringhe vuote), conserva la stringa category e crea slug distinti in caso di collisione. Non interpreta semanticamente nomi simili. La migration legge a blocchi e non altera dati finanziari; aggiunge category_id alle proposte conservando il resto del JSON. I nuovi campi fiscali/consegna/anagrafici non inventano valori legacy.

MySQL DDL non è atomicamente rollbackabile: dopo interruzione controllare schema/migration/backfill prima di riprovare; non cancellare tabelle o marcare la migration completata senza verifica. Il metodo down rifiuta cancellazioni: il rollback applicativo conserva lo schema additivo/dati nuovi. Sul cPanel l'installer web rimane solo prima installazione; un upgrade esistente richiede il canale migration del provider, non reinstallazione/reset.

## Test senza reset

La suite usa AdditiveRefreshDatabase: normali migrate su un DB nuovo, poi isolamento transazionale. Per MySQL concurrency AdditiveDatabaseMigrations crea un database nuovo per caso, senza rollback di schema. Le verifiche di upgrade ricostruiscono lo schema precedente su una connessione nuova, inseriscono record legacy e applicano solo la nuova migration; non toccano DB applicativi e non eliminano database esistenti. I database di verifica creati restano identificati da prefissi zero_suite_, zero_test_ e zero_upgrade_.

Per eseguire MySQL provisionare un **nuovo database vuoto** e usare `DB_CONNECTION=mysql DB_DATABASE=NOME_NUOVO php artisan test --compact --no-ansi`; i casi concurrency/upgrade richiedono CREATE DATABASE per l'utente del solo ambiente test. Non usare il database applicativo o un database test con dati da conservare.

Verifica finale: SQLite 231 passati, 6 skip MySQL, 1204 asserzioni; MySQL 237 passati, 1240 asserzioni. Nuovi casi: backfill legacy/proposte e collisioni slug, conservazione ledger/trigger, ruoli/capacità/registrazione, CRUD/referenze/disattivazione, ownership/default indirizzi, consegna esatta e IVA configurabile, dati approvati/proposti. Build Vite, Pint e git diff --check verificati. Controlli Chromium/axe su categorie (lista/nuovo/modifica), registrazione cliente e form giacenze a 320/375/768/1280 px, con limiti di un controllo automatico locale: nessuna certificazione WCAG o test hosting reale.

OPEN BUSINESS DECISION: incasso/trasferimenti, IVA/fatture, rimborsi, commissioni conguaglio e durata reservation restano aperti. Le fondamenta non risolvono o implementano queste scelte.
