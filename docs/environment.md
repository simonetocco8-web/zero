# Variabili d’ambiente

`.env.example` è un template **di sviluppo**, privo di credenziali reali. `.env` deve restare ignorato da Git e fuori da public. Non stampare il file, non usare secrets nelle build frontend e non condividere log mail/reset. Dopo ogni modifica ricostruire config cache e riavviare worker. Le variabili opzionali `null` non sono password: consentono i default Laravel; una stringa vuota può avere un significato diverso.

## Applicazione, sicurezza e log

| Variabili | Scopo / produzione |
| --- | --- |
| APP_NAME | Nome interfaccia e mittente; ZeroMagazzino |
| APP_ENV, APP_DEBUG, APP_URL | production, false, URL canonico HTTPS |
| APP_KEY | Generata una sola volta, secret da conservare/backup; non rigenerare ad ogni rilascio |
| APP_PREVIOUS_KEYS | Chiavi precedenti separate da virgola per rotazione pianificata; secret |
| APP_LOCALE, APP_FALLBACK_LOCALE, APP_FAKER_LOCALE | it, fallback en; Faker riguarda test/factory |
| APP_MAINTENANCE_DRIVER, APP_MAINTENANCE_STORE | file; store database solo se si sceglie manutenzione cache condivisa |
| SESSION_DRIVER, SESSION_LIFETIME, SESSION_ENCRYPT | database, minuti validità, payload sessione cifrato nel template |
| SESSION_PATH, SESSION_DOMAIN | /; null per cookie limitato all’host, dominio solo se necessario |
| SESSION_SECURE_COOKIE, SESSION_HTTP_ONLY, SESSION_SAME_SITE | true in produzione, true, lax; Secure false solo su HTTP locale |
| CACHE_STORE, CACHE_PREFIX | database oppure Redis condiviso; prefisso distinto per installazione; array solo test |
| BCRYPT_ROUNDS | Costo hashing; default 12 |
| LOG_CHANNEL, LOG_STACK, LOG_LEVEL, LOG_DAILY_DAYS | stack/daily, livello/retention concordati; no dati personali o secrets |
| LOG_DEPRECATIONS_CHANNEL | Canale deprecazioni, null nel template |
| BROADCAST_CONNECTION | log; nessun broadcaster reale configurato |
| VITE_APP_NAME | Valore pubblico; **mai** VITE_STRIPE_SECRET/VITE_* con secrets |

## Database

| Variabili | Scopo |
| --- | --- |
| DB_CONNECTION | mysql in ambiente applicativo; sqlite solo suite rapida |
| DB_URL | Alternativa DSN alla configurazione separata; può contenere credenziali e sovrascriverla; lasciare vuota/non configurata se non usata |
| DB_HOST, DB_PORT, DB_DATABASE | Host, porta e database; cPanel può applicare prefisso al nome |
| DB_USERNAME, DB_PASSWORD | Credenziali private di un utente dedicato; distinguere migration/runtime/test |
| DB_SOCKET | Socket Unix opzionale; vuoto usa host/porta |
| DB_CHARSET, DB_COLLATION | utf8mb4 e utf8mb4_unicode_ci; non cambiare senza piano di migrazione |
| MYSQL_ATTR_SSL_CA | File CA del DB remoto per TLS verificato; non disabilitare verifica certificati |
| MYSQL_ROOT_PASSWORD | Solo bootstrap del container MySQL **locale**, non richiesta per DB gestito e mai usata dalla CI/produzione |

Per la suite MySQL impostare DB_DATABASE a un database sacrificabile come zeromagazzino_test. `phpunit.xml` imposta SQLite in assenza di override di processo. DB_FOREIGN_KEYS è l’opzione SQLite Laravel e non sostituisce i vincoli MySQL; non disabilitarli nei test.

## Mail

| Variabili | Scopo |
| --- | --- |
| MAIL_MAILER | log solo sviluppo; smtp in produzione oppure driver realmente provisionato |
| MAIL_SCHEME | null nel template; smtps per TLS implicito, smtp per SMTP/STARTTLS secondo provider; MAIL_ENCRYPTION non è letta da questo progetto |
| MAIL_HOST, MAIL_PORT | Endpoint SMTP e porta del provider |
| MAIL_USERNAME, MAIL_PASSWORD | Credenziali private; null se trasporto non autenticato locale |
| MAIL_URL | DSN alternativo sensibile; lasciare null se si usano le variabili separate |
| MAIL_EHLO_DOMAIN | FQDN presentato in EHLO, se richiesto; non impostato deriva dall’URL applicativo |
| MAIL_FROM_ADDRESS, MAIL_FROM_NAME | Mittente autorizzato e nome |
| MAIL_SENDMAIL_PATH | Percorso/comando sendmail solo per quel driver, non serve a SMTP |
| MAIL_LOG_CHANNEL | null usa log predefinito; solo sviluppo, perché contiene link reset |

Configurare consegna, TLS, SPF/DKIM/DMARC e provare reset password dal server reale. Nessun test fake dimostra la raggiungibilità/consegna SMTP esterna.

## Stripe

| Variabili | Scopo |
| --- | --- |
| STRIPE_KEY | Publishable key dell’account/ambiente; prevista ma Checkout attuale usa redirect server, non Stripe.js |
| STRIPE_SECRET | Secret SDK ufficiale; obbligatoria per Checkout/riconciliazione |
| STRIPE_WEBHOOK_SECRET | Secret dell’endpoint webhook dello stesso ambiente; non è la secret API |
| STRIPE_PRO_MONTHLY_PRICE_ID | Prezzo ricorrente EUR 6900 centesimi/mese |
| STRIPE_PRO_YEARLY_PRICE_ID | Prezzo ricorrente EUR 49900 centesimi/anno |

Staging usa valori di test, produzione live dopo collaudo e decisioni fiscali. Tutti i valori reali si inseriscono privatamente; i secret restano blank nel template. Pagamento e approvazione rivenditore restano indipendenti.

## Store

| Variabili | Scopo |
| --- | --- |
| STORE_DRIVER | fake finché manca un provider implementato; valore sconosciuto fallisce senza fallback implicito |
| STORE_WEBHOOK_SECRET | Secret HMAC del **solo adapter fake**; blank disabilita il relativo endpoint |

Non esistono URL/API key di ecommerce reale da compilare adesso. Quando viene scelto, aggiungere driver/adapter in config/store.php e documentarne nuove STORE_* leggendo env solo in config. Il provider non deve accettare un retailer_id arbitrario senza mapping verificato.

## Queue, cache e servizi opzionali

| Variabili | Scopo |
| --- | --- |
| QUEUE_CONNECTION | database consigliato per le normali queue Laravel |
| INTEGRATIONS_QUEUE_CONNECTION | database, oppure backend durevole configurato; mai sync/deferred/null per webhook |
| DB_QUEUE_CONNECTION | Connessione DB dei jobs, null riusa quella predefinita; non un nome vuoto |
| DB_QUEUE_TABLE, DB_QUEUE | jobs e default; eventi integrazione usano queue integrations |
| DB_QUEUE_RETRY_AFTER | 150s nel template, superiore a timeout 45s e lease 120s |
| QUEUE_FAILED_DRIVER | database-uuids per failed_jobs persistenti |
| REDIS_CLIENT, REDIS_HOST, REDIS_PORT | phpredis e endpoint se si sceglie Redis; richiede estensione/server |
| REDIS_USERNAME, REDIS_PASSWORD | Credenziali Redis private, null se non provisionato |
| REDIS_DB, REDIS_CACHE_DB | Database logici separati, se supportati dal servizio |
| REDIS_QUEUE_CONNECTION, REDIS_QUEUE, REDIS_QUEUE_RETRY_AFTER | default/default/150s per queue Redis, se scelta |

CACHE_STORE deve consentire lock condivisi Stripe e cache restart dei worker. Per SQS/Beanstalkd servono provisioning e impostazioni aggiuntive di config/queue.php: non sono prerequisiti del percorso database documentato. Non cambiare backend senza verificare reservation timeout, retry, worker e monitoraggio.

## Filesystem, foto e dati di dominio

| Variabili | Scopo |
| --- | --- |
| FILESYSTEM_DISK | local per il default Laravel; le foto sono esplicitamente sul disco local **privato**, indipendentemente dal default |
| INVENTORY_IMAGE_BINARY | Percorso convert ImageMagick aggiornato e autorizzato, default /usr/bin/convert |
| AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY | Credenziali S3 opzionali, blank; non un’integrazione upload S3 pronta |
| AWS_DEFAULT_REGION, AWS_BUCKET | Regione/bucket solo se il disco s3 viene usato esplicitamente |
| AWS_URL, AWS_ENDPOINT, AWS_USE_PATH_STYLE_ENDPOINT | URL/endpoint opzionali e modalità S3 compatibile; null mantiene default SDK |
| PRIVACY_POLICY_URL, PRIVACY_POLICY_VERSION | Informativa definitiva pubblicata e versione del consenso; configurare prima di raccogliere dati reali |
| CREDIT_MATURATION_DAYS | Giorni prima che il credito sia disponibile; default 0, da confermare nel processo commerciale |

Nessun prezzo, commissione o limite piano si configura con nuovi env sparsi: usare config/plans.php per seed iniziale e righe plans per configurazione runtime. Il cambio di piano non riscrive commissioni già registrate.
