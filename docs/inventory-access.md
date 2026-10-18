# Articoli, foto e accesso riservato — 18 ottobre 2026

Decisione corrente del committente, prevalente sui precedenti riferimenti al catalogo pubblico.

- `/` per gli ospiti mostra Accedi e Registrati (rivenditore o cliente), senza dati prodotto. Catalogo, categorie, schede, immagini e richieste informazioni richiedono autenticazione. Customer e admin accedono; per i retailer serve stato approved. La registrazione customer esistente non introduce una nuova verifica amministrativa: la validazione consiste nell'autenticazione, con approvazione aggiuntiva per le rivendite.
- Il menu rivenditore contiene **Vai al marketplace**, collegato al catalogo. Le risposte riservate non sono cacheabili e il catalogo ha direttive noindex/nofollow.
- Owner approved e admin possono eliminare articoli in tutti gli stati. L'azione è un soft delete con stato archived, deleted_at e deleted_by: nessuna rimozione fisica dello storico, dei riferimenti finanziari o dei file privati. Le prenotazioni attive non scadute bloccano l'eliminazione. Gli articoli scompaiono da catalogo e liste operative; il carrello eventualmente esistente li segnala come indisponibili. Gli snapshot ordine restano leggibili e i rimborsi possono ripristinare quantità sul record storico senza ripubblicarlo.
- Gestisci foto permette eliminazione singola o totale, sostituzione singola e aggiunta. Ownership, numero massimo e MIME/dimensioni sono verificati sul server. Un hash del gruppo impedisce modifiche su un elenco foto superato. Scegliere eliminazione e sostituzione della stessa foto è un errore.
- Foto di bozze/pending/rejected aggiornate immediatamente. Per gli articoli pubblicati, anche la gestione foto conserva la versione approvata e crea una proposta change_pending; l'admin applica la modifica mediante la review esistente. I vecchi file rimangono privati a tutela dei riferimenti di pubblicazione/storico; non esiste un nuovo URL pubblico o symlink.
- Quantità in archivio sempre intere in millesimi, senza modifica dei dati. La presentazione usa DisplayQuantity: 1000 → `1`, 1250 → `1,25`, 1 → `0,001`. Nessun float o separatore delle migliaia ambiguo. Il form articolo usa un valore decimale esatto senza zeri inutili.

Migration additiva: `2026_10_18_000001_add_inventory_soft_deletion.php`. In SQLite le colonne vengono aggiunte senza ricostruire inventory_items, preservando i trigger di integrità esistenti. Nessun reset o eliminazione di tabelle/colonne.

Test: accesso guest e retailer non approvati; customer/admin ammessi; delete owner/admin e isolamento fra retailer; blocco reservation; foto singole/totali e conflitto versione; formato quantità; ordine e rimborso dopo eliminazione logica. Le verifiche precedentemente denominate PublicMarketplaceTest ora eseguono le letture come utenti autenticati.

## Verifiche

Integrazione con `main` del 18 ottobre (`a7c3ac0`): conservati credito retailer e checkout 30m introdotti sul remoto. Migration aggiunta anche a `deployment/additive-migrations.json` per l'updater cPanel.

- Suite completa MySQL: 420 test passati; SQLite: 393 passati e 27 esclusi perché richiedono MySQL.
- Ulteriore verifica permessi, inclusa sospensione durante una richiesta con relazione retailer già caricata: 9 test passati su entrambi i database.
- Build Vite, Pint e diff/whitespace passati.
- Chromium/axe: 32 verifiche a 320/375/768/1280 px, nessuna violazione rilevata o overflow. Verificati salvataggio foto e conferma DELETE.
- Hash dei dati preesistenti conservati su 49 tabelle del database cloud durante l'aggiornamento additivo; nessun reset.

Per aggiornare un'installazione esistente applicare il normale deploy e la migration additiva tramite updater approvato oppure `php artisan migrate --force`, quindi aggiornare le cache delle viste secondo la procedura di deploy. Nessun comando fresh/refresh/reset.
