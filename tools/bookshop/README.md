# Bookshop → Raffaello Scuola: licenze RSCU

Chi compra sul Bookshop un prodotto di accesso a Raffaello Scuola se lo trova attivo
su raffaelloscuola.it, sul proprio account Raffaello Identity. Il Bookshop resta
l'autorità sulle licenze (License Manager for WooCommerce 3.0.20, versione gratuita):
lo snippet `raffaello-scuola-licenze.php` le manda a Raffaello Scuola, che le riceve
con il plugin Raffaello Licenze (1.5.0 o successiva). Raffaello Scuola non chiama
mai l'API di License Manager.

## Come funziona

1. License Manager assegna le licenze quando l'ordine entra in uno degli stati di
   consegna (processing, completed, test, imported) e lancia
   `lmfwc_event_post_order_license_keys` una volta per ogni riga con licenza, ogni volta
   con tutte le licenze assegnate fin lì.
2. Lo snippet tiene solo le chiavi che iniziano con `RSCU` e salva nella meta d'ordine
   `_rscu_licenze` quelle nuove: id della licenza, chiave, riga d'ordine, SKU e nome del
   prodotto, scadenza. Nella meta `_rscu_cliente` fotografa una volta sola il `sub` di
   Identity del cliente (user meta `ri_oidc_sub`, `null` se manca) e l'email di
   fatturazione (solo per il log). La scadenza è quella di License Manager; se manca, la
   data di pagamento più i giorni di validità; altrimenti `null` (illimitata).
3. Accoda con Action Scheduler (gruppo `raffaello-scuola`) un invio per ordine ed
   evento: se ce n'è già uno in attesa basta quello, se ce n'è uno in esecuzione se ne
   accoda un altro. Il job manda il JSON a
   `RAFFAELLO_SCUOLA_URL/wp-json/raffaello-licenze/v1/eventi` a lotti di al massimo 50
   licenze e 64 KB (i limiti dell'endpoint), uno dopo l'altro, firmando ogni lotto con
   HMAC-SHA256.
4. È riuscito solo se ogni lotto riceve un 200 con JSON `ok: true` e un esito per ogni sua
   licenza. Errori di rete, 429 e 5xx si ritentano rimandando tutto: 5 tentativi in tutto,
   a 1 min, 5 min, 30 min, 2 h e 6 h l'uno dall'altro (il primo un minuto dopo
   l'assegnazione). Gli altri errori, compresa una pagina HTML con 200, sono definitivi.
   L'esito finisce in una nota d'ordine privata: riuscito (l'esito di ogni licenza fino a
   10, oltre i conteggi per esito) oppure fallito definitivamente. Se Action Scheduler non
   accetta un tentativo, lo dicono una nota d'ordine e il log.
5. Rimborso totale (stato "Rimborsato") o annullamento: accoda l'evento
   `licenze_revocate` con tutte le licenze RSCU dell'ordine. La revoca si registra anche
   se License Manager non ha ancora assegnato le licenze: quelle assegnate dopo si
   revocano subito dopo l'assegnazione. La revoca su Raffaello Scuola è definitiva.
   Rimborso parziale: nessuna revoca automatica, solo la nota "Rimborso parziale:
   revocare a mano su Raffaello Scuola le licenze interessate".
6. Azione d'ordine **"Reinvia licenze a Raffaello Scuola"** (menu "Azioni ordine"):
   rimette in coda gli stessi dati fotografati, e la revoca se l'ordine è stato revocato.

Esiti per licenza restituiti da Raffaello Scuola: `attivata`, `codice_libero` (quantità
maggiore di 1: il primo codice di ogni licenza va all'acquirente, gli altri restano codici
liberi da regalare), `senza_destinatario` (cliente senza `sub`: il codice si riscatta a
mano), `in_attesa_configurazione` (SKU non ancora configurato su Raffaello Scuola: il
codice è salvato e si attiva quando si salva la licenza con quello SKU), `revocata`,
`conflitto` (stesso id licenza con dati diversi: nessuna modifica, da guardare a mano).

## Installazione sul Bookshop

### 1. Segreto condiviso

Genera un valore casuale di almeno 32 byte, per esempio:

```
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Sul Bookshop, in `wp-config.php`:

```php
define('RAFFAELLO_SCUOLA_URL', 'https://dev.raffaelloscuola.it'); // in produzione https://www.raffaelloscuola.it
define('RAFFAELLO_SCUOLA_SECRET', '...il valore generato...');
```

Su Raffaello Scuola, in `wp-config.php`, lo **stesso** valore:

```php
define('RCL_BOOKSHOP_SECRET', '...il valore generato...');
```

Senza `RCL_BOOKSHOP_SECRET` Raffaello Scuola risponde 503 (e lo snippet ritenta);
Licenze > Impostazioni mostra se il segreto è definito. Dev e produzione devono avere
segreti diversi.

Se sul Bookshop è definita `WP_HTTP_BLOCK_EXTERNAL`, l'host di Raffaello Scuola va
aggiunto a `WP_ACCESSIBLE_HOSTS`, altrimenti WordPress blocca l'invio (errore di rete,
ritentato e poi fallito).

### 2. Snippet

In Code Snippets: *Aggiungi nuovo*, titolo "Raffaello Scuola: licenze RSCU", incolla il
contenuto di `raffaello-scuola-licenze.php` (Code Snippets toglie da solo il `<?php`
iniziale), scegli "Esegui lo snippet ovunque", salva e attiva. In alternativa si copia
il file in `wp-content/mu-plugins/`. Non tutte e due le cose insieme.

### 3. Snippet GreenBubble: saltare le RSCU

Lo snippet GreenBubble manda a Raffaello Digitale tutto ciò che non inizia con `RLAB`,
quindi oggi manderebbe a RD anche le RSCU. Nel suo ciclo sulle licenze, subito dopo aver
letto `$license_key` e prima di `$is_rlab = str_starts_with($license_key, 'RLAB');`,
aggiungi:

```php
if (str_starts_with($license_key, 'RSCU')) { continue; } // Raffaello Scuola: vedi lo snippet raffaello-scuola-licenze
```

La riga va prima dell'invio e prima che l'id finisca in `_gb_sent_license_ids`: così le
RSCU non vanno a RD né risultano inviate da GreenBubble. Il loro esito è separato: note
d'ordine "Raffaello Scuola, …", meta `_rscu_licenze` e `_rscu_tentativi`, log con
sorgente `raffaello-scuola-licenze`. Il log GreenBubble (`license_manager_greenbubble`)
resta solo per RLAB e RD.

### 4. Generatore in License Manager

License Manager > Generatori > Aggiungi:

- Character map: `ABCDEFGHLMNPQRSTUVZ123456789`
- Number of chunks: `2`, Chunk length: `4`, Separator: `-`
- Prefix: `RSCU-` (License Manager non mette il separatore dopo il prefisso: il trattino va
  scritto nel prefisso)
- Maximum activation count: `1`
- Expires in: i giorni di accesso (per esempio `365`)

Le chiavi risultano come `RSCU-AB12-CD34`.

### 5. Prodotto

- Prodotto **virtuale** con uno **SKU suo** (l'ISBN appartiene già al libro fisico).
- Scheda "License Manager": "Sell license keys", "Generate license keys" con il generatore
  del punto 4, "Delivered quantity" `1`.
- Non "venduto singolarmente": la quantità può essere maggiore di 1.
- Consegna automatica negli stati processing, completed, test e imported (License
  Manager > Impostazioni > WooCommerce, "Define license key delivery").

### 6. Su Raffaello Scuola

Licenze > Aggiungi licenza: nome, **lo stesso SKU del prodotto**, contenuti (pagine,
articoli, corsi LearnDash). Meglio prima delle vendite; se una vendita arriva prima,
l'esito è `in_attesa_configurazione` e l'attivazione parte al salvataggio della licenza,
con la scadenza dell'acquisto. I corsi venduti vanno in modalità "Chiusa", con il
pulsante verso il prodotto del Bookshop.

## Prova

1. Da Raffaello Scuola, con il segreto del dev nell'ambiente:
   `RCL_BOOKSHOP_SECRET=... php tools/push-di-prova.php --url=https://dev.raffaelloscuola.it --sku=SKU-PRODOTTO --sub=123`
   stampa un comando curl con firma valida per 5 minuti.
2. **Cloudflare.** Lancia quel curl dal server del Bookshop (SSH), non dal tuo PC: deve
   rispondere 200 con `{"ok":true,...}`. Se risponde 403 o con una pagina di verifica, il
   POST viene bloccato prima di WordPress e serve una regola per il percorso
   `/wp-json/raffaello-licenze/v1/eventi` dall'IP del Bookshop. Attenzione: Bot Fight
   Mode non si aggira con una regola WAF "Skip".
3. Un ordine con il coupon `SCONTO100` (stato test) sul dev del Bookshop: nella nota
   d'ordine compare "Raffaello Scuola, assegnazione riuscita: RSCU-****-XXXX attivata".
   Su Raffaello Scuola: Licenze > Attivazioni, ricerca per ordine.
4. Rimborso totale dello stesso ordine: nota "Raffaello Scuola, revoca riuscita" e
   attivazione revocata su Raffaello Scuola.

Raffaello Scuola elabora un evento per ordine alla volta: se lo stesso ordine è già in
elaborazione risponde 503 e lo snippet riprova.

Dove guardare: note d'ordine; WooCommerce > Stato > Azioni programmate, gruppo
`raffaello-scuola`; WooCommerce > Stato > Log, sorgente `raffaello-scuola-licenze` sul
Bookshop e `raffaello-licenze` su Raffaello Scuola.

## Limiti noti

- Le modifiche fatte direttamente in License Manager (licenza disattivata o cancellata,
  chiave rigenerata) non arrivano a Raffaello Scuola: si revoca a mano da Licenze >
  Attivazioni.
- Ordini da ospite: il Bookshop non li permette; un cliente senza `sub` riceve
  `senza_destinatario` e riscatta il codice a mano.
- Il `sub` è quello del cliente al momento dell'assegnazione: un cambio di account dopo
  l'acquisto non sposta niente, serve l'assistenza.
