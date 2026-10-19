# Bookshop → Raffaello Scuola: licenze RSCU

Il codice per il Bookshop è il plugin **Raffaello Licenze — Bookshop**, repository
`danieledignani/raffaello-licenze-bookshop` (da creare), con la sua documentazione. Prende
il posto dello snippet per Code Snippets che stava qui fino alla 1.5.0: dove era stato
incollato va disattivato quando si attiva il plugin, non tutti e due insieme.

## Collegamento, dalle impostazioni

1. Su Raffaello Scuola, **Licenze > Impostazioni**: "Genera segreto" e copialo subito, perché
   si vede una volta sola.
2. Sul Bookshop, nelle impostazioni del plugin: l'indirizzo di Raffaello Scuola (solo
   `https://` e host, senza percorso) e lo stesso segreto.
3. Sul Bookshop, "Prova connessione": un `ping` firmato verifica indirizzo, segreto, orologio e
   Cloudflare e riporta gli SKU dei prodotti RSCU che su Raffaello Scuola non hanno una
   licenza attiva.

In alternativa alle impostazioni, su ogni lato una costante in `wp-config.php`, che ha la
precedenza: `RCL_BOOKSHOP_SECRET` su Raffaello Scuola, `RAFFAELLO_SCUOLA_URL` e
`RAFFAELLO_SCUOLA_SECRET` sul Bookshop.

Il segreto salvato vale solo sul sito che l'ha salvato: copiando il database su un altro
ambiente il collegamento va rifatto. Dev e produzione hanno segreti diversi. Per cambiarlo si
aggiornano i due lati; gli invii rifiutati nel frattempo il Bookshop li ritenta da solo.

Licenze > Impostazioni mostra lo stato della configurazione di Raffaello Scuola; i problemi
bloccanti compaiono anche in bacheca e in Strumenti > Salute del sito. Senza il Bookshop,
`tools/push-di-prova.php --evento=ping` prepara un ping firmato da lanciare con curl, per
esempio dal server del Bookshop per provare Cloudflare.

## Ordine del go-live

1. Raffaello Identity sul Bookshop di produzione: senza il `sub` dell'acquirente le licenze
   non si attivano da sole.
2. Raffaello Identity, Raffaello Licenze e LearnDash su Raffaello Scuola di produzione.
3. Su Raffaello Scuola le licenze, con lo SKU dei prodotti e i contenuti; i corsi venduti in
   modalità "Chiusa" e senza la scadenza dell'accesso di LearnDash.
4. Sul Bookshop il generatore `RSCU-` di License Manager, i prodotti RSCU, l'esclusione delle
   chiavi RSCU dallo snippet GreenBubble e il plugin Raffaello Licenze — Bookshop.
5. Il collegamento come sopra, poi "Prova connessione".
6. Un ordine di prova e il suo rimborso totale: attivazione e revoca su Raffaello Scuola, in
   Licenze > Attivazioni.
