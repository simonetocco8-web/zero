# Checklist pre-produzione

Da completare dal responsabile del rilascio su staging e sul server destinazione. Caselle non spuntate: le verifiche cloud/CI non attestano la configurazione di produzione. Procedure in [deployment.md](deployment.md), variabili in [environment.md](environment.md).

## Release e hosting

- [ ] Release/commit selezionato, revisione completata e CI GitHub verde sul commit da distribuire.
- [ ] PHP web e CLI >= 8.4.1, Composer 2, piattaforma verificata senza ignorare requisiti; Node 24 solo dove si compila.
- [ ] MySQL >= 8.0.16, InnoDB/strict/utf8mb4, CHECK e trigger attivi; privilegi migration/runtime concordati con DBA/hosting.
- [ ] ImageMagick aggiornato con JPEG/PNG/WebP, proc_open consentito, pcntl disponibile sulla CLI worker, directory temporanea e limiti upload PHP/proxy verificati con foto reali.
- [ ] Document root sul solo public; .env, vendor, storage privato, log e backup inaccessibili via HTTP.
- [ ] Asset public/build della stessa release; assenza di public/hot e Vite dev server; permessi owner/gruppo senza 777.
- [ ] Stato delle migration controllato; migrate --force e PlanSeeder eseguiti solo sul DB corretto, nessun seed demo.
- [ ] Admin nominale autorizzato, password privata; nessun account/password amministrativa condiviso o hardcoded.

## Configurazione e sicurezza

- [ ] .env fuori da Git/document root, APP_ENV=production, APP_DEBUG=false, APP_URL HTTPS.
- [ ] APP_KEY esistente preservata e backup segreto recuperabile; eventuale rotazione pianificata con chiavi precedenti.
- [ ] HTTPS/certificato/redirect, proxy fidati, cookie Secure/HttpOnly/SameSite, pagine private no-store verificati dal browser.
- [ ] Foto private non esposte da storage:link; IBAN cifrato, mascherato e assente dai log/flash.
- [ ] Login/logout/reset, rate limit, CSRF e accessi fra rivenditori/admin verificati su staging; errori 403/404/419/429/500 senza dati sensibili.
- [ ] SMTP/TLS/mittente configurati, SPF/DKIM/DMARC e consegna reset reali provati; MAIL_MAILER diverso da log.
- [ ] Informativa privacy definitiva/versione/consenso, retention dei contatti e gestione dei diritti approvati dal responsabile.
- [ ] Log web/app/worker/cron privati e ruotati; niente token reset, payload webhook, credenziali o IBAN; accessi limitati.

## Piani, pagamenti e provider

- [ ] Piani FREE/PRO nel DB corretti: limiti, commissioni 200/50 basis point, 6900 centesimi/mese e 49900/anno.
- [ ] IVA/fatturazione, maturazione credito, insoluti/grace period, downgrade oltre quota, rimborso e saldo negativo approvati come regole operative.
- [ ] Stripe test/live separati, credenziali in ambiente corretto, Price ID ricorrenti/currency/importi validati.
- [ ] Webhook Stripe HTTPS registrato per tutti gli eventi documentati, firma raw e NTP verificati; test duplicati/retry/insoluto/cancellazione su sandbox.
- [ ] Pagamento PRO pending non abilita funzioni aziendali; ritorno browser non attiva piano senza conferma provider.
- [ ] Store reale scelto, adapter/gateway/mapping e firma collaudati; STORE_DRIVER=fake riconosciuto come simulazione se il negozio reale non è ancora pronto.
- [ ] Fonte autorevole stock, tasse, spedizioni, ordini fuori ordine, rimborsi e riconciliazione concordati col negozio; nessun ecommerce completo implicito.
- [ ] Procedura bancaria esterna, verifica IBAN su canale autorizzato e riferimento di pagamento realizzato definiti; credito al checkout non promesso come disponibile.

## Processi, osservabilità e dati

- [ ] Queue durevole e cache/lock condivisi; timeout 45s, retry_after 150s, lease 120s coerenti.
- [ ] Worker supervisionato oppure bounded cPanel collaudato; reboot/restart del processo verificati, connessione e code integrations/default corrette.
- [ ] Cron schedule:run ogni minuto su un nodo; schedule:list verificato, recuperi ogni minuto e riconciliazione oraria osservati.
- [ ] Dopo rilascio config/route/view cache ricostruite e worker riavviati; accesso storage persistente coerente fra web/CLI.
- [ ] Monitoraggio DB, /up, mail, queue/backlog, receipt failed/esaurite, pubblicazioni e spazio disco; alert con responsabile operativo.
- [ ] Backup DB con trigger/vincoli e upload privati/pubblici coordinato; copia cifrata off-site, RPO/RTO/retention stabiliti.
- [ ] Backup APP_KEY/configurazione separato e restore isolato riuscito con verifica saldi, foto e dati cifrati; provider/mail disabilitati durante restore.
- [ ] Piano rollback e replay/riconciliazione dopo downtime pronti; receipt/ledger/audit non cancellati per retry.

## Collaudo finale

- [ ] Suite MySQL completa senza skip, suite SQLite con soli sei skip previsti, formatter e build superati sul commit scelto.
- [ ] Composer audit e npm audit rivalutati con feed raggiungibili; nessuna verifica saltata descritta come superata.
- [ ] Verifica mobile 360/390/430 px, tablet/desktop, tastiera/focus e azioni distruttive; test su dispositivi fisici con fotocamera/galleria e screen reader.
- [ ] Flusso end-to-end staging: iscrizione → approvazione → giacenza → pubblicazione → richiesta/ordine simulato → credito → payout/rigetto.
- [ ] Go/no-go concordato; attività esterne incomplete rendono il rilascio una simulazione, non una produzione economica collaudata.
