# Fixture Sendcloud v3

`shipping-options.json` segue lo schema ufficiale recuperato da https://sendcloud.dev/.openapi/v3/shipping-options/openapi.yaml il 7 novembre 2026 (SHA-256 `69208e9720cc3ccb1adf44c03e8bea6a640c27be9426eb70321eebea5ce5c4ff`).

L’opzione PostNL deriva da `paths./shipping-options.post.responses.200.content.application/json.examples.ShippingOptions`, con un supplemento stimato aggiunto secondo lo schema `shipping-quote.estimated_surcharges`. L’opzione DPD Test è sintetica, costruita sul medesimo schema per verificare più corrieri e prezzi. I test mutano copie della fixture per errori, importi mancanti, limiti e parità di prezzo.

Questi sono dati di test, non tariffe ottenute da un account autentico e non prova che PostNL/DPD o tali prezzi siano disponibili per origini italiane. Nessuna credenziale o indirizzo personale è contenuto nella fixture. Non usare il file come fallback operativo.
