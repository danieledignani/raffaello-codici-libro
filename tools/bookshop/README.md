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
4. Su Raffaello Scuola la verifica del cron e della cache:
   - il passaggio giornaliero che toglie i corsi scaduti deve girare (WP-Cron, oppure un cron
     di sistema con `DISABLE_WP_CRON`); Licenze > Impostazioni dice se è in ritardo;
   - pagine riservate, corsi e download non devono arrivare dalla cache agli utenti collegati
     (WP Rocket con "User Cache", Cloudflare con "Cache Everything"): prova dopo una revoca,
     con due utenti diversi e da anonimo.
5. Sul Bookshop il generatore `RSCU-` di License Manager, i prodotti RSCU, l'esclusione delle
   chiavi RSCU dallo snippet GreenBubble e il plugin Raffaello Licenze — Bookshop. I prodotti
   RSCU restano non acquistabili, privati o in bozza, fino alla prova finale.
6. Il collegamento come sopra, poi "Prova connessione".
7. Prova finale con un prodotto di prova dedicato: pubblicato ma nascosto dal catalogo, con il
   generatore `RSCU-` e uno SKU proprio, e su Raffaello Scuola una licenza di prova con quello
   SKU. Un utente non amministratore lo compra dal checkout, poi l'ordine si rimborsa per intero:
   attivazione e revoca si vedono in Licenze > Attivazioni. Finita la prova, il prodotto e la
   licenza di prova si rendono privati o si eliminano.
8. Solo alla fine, l'apertura delle vendite: i prodotti RSCU veri diventano acquistabili.

## Rimborso parziale

Con un rimborso parziale si sceglie a mano quale codice dell'ordine togliere. Su Raffaello
Scuola, in Licenze > Codici (filtro per ordine), "Revoca definitivamente" su quel codice, libero o
già riscattato: fa come la revoca dal Bookshop. Il codice non si potrà più usare né riattivare, le
sue attivazioni si revocano e i corsi LearnDash si riallineano. Se si interrompe con un errore,
"Risincronizza" sul codice revocato rifà la revoca delle attivazioni e il riallineamento.
