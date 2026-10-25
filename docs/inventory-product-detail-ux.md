# Dettaglio giacenza — presentazione

La pagina `/retailer/stock/{item}` usa un contenitore `max-w-6xl`, header con stato esistente e azioni autorizzate, gallery e KPI affiancati su desktop e impilati su mobile. Prezzo ZeroMagazzino dominante, listino secondario, quantità disponibile tramite l'accessor esistente (quantità registrata indicata se diversa), condizione e disponibilità espresse anche in testo.

Consegna, fiscalità, codici, descrizione completa e pubblicazione hanno sezioni dedicate. Il costo spedizione mantiene il valore memorizzato e `null` resta «Da definire». Le etichette fiscali provengono esclusivamente da `vatLabel()` e `shippingVatLabel()`. La data è quella di prima pubblicazione già presente, senza spacciarla per ultimo aggiornamento.

`inventory.product-gallery` usa URL della route privata esistente e mantiene le posizioni originali anche se non contigue. Prima immagine visibile, miniature solo con più foto, selezione Alpine tramite pulsanti accessibili da tastiera e placeholder senza immagini. Nessun percorso di storage viene esposto.

Le proposte, comprese quelle con sole foto o con rimozione di tutte le foto, hanno un avviso e una sezione distinta. `inventory.product-version` confronta versione attuale e proposta: una copia del modello riceve i campi proposti solo in memoria, senza salvataggi. Le foto proposte usano il parametro autorizzato `proposed=1`; quelle approvate restano sopra. Le descrizioni sono integralmente consultabili.

Le azioni del dettaglio hanno una composizione dedicata: nessun «Visualizza» verso la pagina corrente. `inventory-actions`, `inventory-status` e le liste rimangono invariati. Link marketplace e richiesta utilizzano `viewPublic` e `PublicInventory` esistenti; eliminazione e archiviazione mantengono policy, route e conferme originali nella zona pericolosa.

## Verifica

`InventoryProductPresentationTest` copre stati, autorizzazioni, gallery 0/1/più foto e posizioni non contigue, consegna, scambio, fiscalità nota/indefinita, listino, proposte, immutabilità alla lettura, rifiuto, riserve e azioni nelle liste. Il test preesistente sulle quantità italiane segue la nuova etichetta senza cambiare le aspettative numeriche.

Verifica browser con fixture isolate SQLite, immagini sintetiche intercettate soltanto nel browser di test, cinque stati a 320/375/768/1280/1536 px: overflow, target tattili, selezione da tastiera e separazione foto proposte. Nessuna modifica a logica applicativa, database, migration, `.env`, flussi economici o pagamenti.

Esito della verifica locale (PHP 8.4, Node 24, MySQL 8.4): SQLite completa 530 passati e 28 skip già previsti; MySQL completa 556 passati, seguita dai 19 test dedicati finali tutti passati (inclusi i due casi aggiunti dopo l'avvio della suite completa). `ReleaseUpdateTest`: 5/5 su MySQL. Pint, build Vite e compilazione Blade superati. Nessun fallimento finale.
