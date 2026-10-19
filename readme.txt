=== Raffaello Licenze ===
Contributors: grupporaffaello
Tags: licenze, codici, sblocco, materiali, download, scuola, identity, sso, learndash
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.5.1
License: GPLv2 or later

Licenze di accesso a pagine, materiali scaricabili e corsi LearnDash: codici stampati sui libri e acquisti sul Bookshop attivati in automatico sul profilo di Raffaello Identity.

== Descrizione ==

Una **licenza** sblocca un insieme di contenuti: pagine, articoli e corsi LearnDash. Chi ha una licenza attiva vede le pagine bloccate, scarica i materiali e segue i corsi. Si ottiene in tre modi:

* **Codice stampato sul libro**: condiviso fra tutte le copie, ogni utente che lo inserisce riceve la sua attivazione. Ha un termine per il riscatto (facoltativo); la licenza può dare l'accesso per un numero di giorni dal riscatto oppure senza scadenza.
* **Acquisto sul Bookshop** (raffaellobookshop.it): License Manager for WooCommerce genera un codice `RSCU-XXXX-XXXX` e il plugin Raffaello Licenze — Bookshop lo manda a questo sito con il `sub` di Raffaello Identity dell'acquirente. La licenza si attiva da sola sul suo profilo, subito o al primo accesso, con la scadenza decisa dal Bookshop. Con quantità maggiore di 1 l'acquirente riceve il primo codice e può regalare gli altri, che si riscattano a mano. Rimborso totale o annullamento sul Bookshop revocano la licenza.
* **Attivazione manuale** dall'admin, per l'assistenza.

Funzionalità:

* Tre livelli di blocco, come prima: materiali in anteprima con form di sblocco contestuale (`[raffaello_materiali]` o elemento YOOtheme), blocco dell'intera pagina, sezioni YOOtheme con la condizione "Accesso materiali (pagina corrente)". I contenuti bloccati restano nascosti anche via REST, feed, estratti e oEmbed.
* **Download protetto**: i file non sono raggiungibili via URL diretto senza il relativo diritto.
* Una pagina con il blocco attivo ma senza licenze resta bloccata per tutti tranne gli amministratori, con un avviso nell'editor.
* **Corsi LearnDash**: iscrizione automatica ai corsi delle licenze attive e disiscrizione alla scadenza o alla revoca. Si toccano solo le iscrizioni fatte dal plugin: quelle manuali o di gruppo restano.
* Inserimento manuale del codice con messaggi chiari: codice non valido, già utilizzato, scaduto, non più attivo, accesso scaduto o revocato, codice di un'altra piattaforma (RAF Lab, Raffaello Digitale). Dopo dieci codici non validi in un'ora l'utente deve aspettare.
* Pagina **"I miei contenuti"** (`[raffaello_miei_contenuti]`): licenze attive e scadute dell'utente, con la scadenza, i link ai contenuti e il form del codice.
* Backoffice **Licenze**: catalogo (nome, SKU del prodotto del Bookshop, durata per i codici stampati, contenuti), codici stampati e acquistati (gli acquistati mascherati), import CSV, attivazioni con ricerca per email, `sub`, codice e ordine, revoca e attivazione manuale, impostazioni.
* Log su WooCommerce > Stato > Log (sorgente `raffaello-licenze`) se WooCommerce è attivo, altrimenti nel log PHP. Mai codici completi né segreti.
* Aggiornamento automatico da GitHub.

== Utilizzo ==

1. **Licenze > Aggiungi licenza**: nome, SKU (solo per le licenze vendute sul Bookshop: lo SKU del prodotto licenza, non l'ISBN del libro), durata in giorni per i codici stampati (vuota = senza scadenza), contenuti. Nello stesso form si aggiungono i codici stampati della licenza.
2. In alternativa, dal riquadro **"Raffaello Licenze"** della pagina si scelgono le licenze che la sbloccano. Nello stesso riquadro l'interruttore **"Blocca l'intera pagina"** mostra il form di sblocco al posto del contenuto a chi non ha la licenza.
3. Materiali: meta box **"Materiali sbloccabili"** della pagina e shortcode `[raffaello_materiali]` (oppure `[raffaello_materiali post_id="123"]`), o l'elemento YOOtheme **"Materiali Codici Libro"** (gruppo Raffaello).
4. `[raffaello_codice]` mostra il solo form del codice. `[raffaello_miei_contenuti]` va in una pagina da indicare in **Licenze > Impostazioni**: il messaggio dopo un riscatto rimanda lì.
5. **Licenze > Codici > Importa CSV**: colonne `codice,licenza,attivabile_fino`, dove licenza è l'id o lo SKU e la data è facoltativa.
6. **Corsi LearnDash venduti**: modalità di accesso "Chiusa", con il pulsante verso il prodotto del Bookshop, e senza la scadenza dell'accesso di LearnDash (la scadenza la decide la licenza). Se LearnDash viene attivato dopo gli acquisti, **Licenze > Impostazioni > Riallinea LearnDash**.
7. **Vendita sul Bookshop**: in **Licenze > Impostazioni** "Genera segreto" e copia lo stesso valore nelle impostazioni del plugin **Raffaello Licenze — Bookshop**, dove va anche l'indirizzo di questo sito; il segreto si vede una volta sola. In alternativa la costante `RCL_BOOKSHOP_SECRET` in `wp-config.php`, che ha la precedenza sulle impostazioni. Il segreto salvato vale solo sul sito che l'ha salvato: un database copiato da un altro ambiente non si porta dietro il collegamento. Ordine del go-live in `tools/bookshop/README.md`. Per una prova senza Bookshop: `tools/push-di-prova.php` genera un push o un `ping` firmato e il comando curl.
8. **Licenze > Impostazioni** mostra lo stato della configurazione (segreto, licenze e contenuti, acquisti in attesa, pagina "I miei contenuti", LearnDash, Identity, cartella protetta, ultima richiesta dal Bookshop) con il link per correggere. I problemi bloccanti compaiono anche in Bacheca e nelle pagine del plugin, finché non sono risolti, e in Strumenti > Salute del sito.

Aggiornando dalla 1.4 i dati si migrano da soli: ogni codice con contenuti diventa una licenza con gli stessi contenuti e lo stesso stato, ogni riscatto un'attivazione con la stessa data e la scadenza del codice. Le pagine con il blocco attivo restano bloccate. Le tabelle della 1.4 restano nel database.

== Test su staging ==

È incluso uno script di seed (solo CLI) che crea una pagina di esempio con materiali, una licenza e codici di prova:

`wp eval-file tools/seed.php` oppure `php tools/seed.php` (rimozione: `php tools/seed.php --remove`).

== Requisiti ==

* WordPress 5.8+, PHP 7.4+
* MySQL 5.7.5+ o MariaDB 10.0.2+: i lock per nome (GET_LOCK) devono poter essere più di uno per connessione.
* Un solo server di database, senza instradare le SELECT su repliche (HyperDB/LudicrousDB): i lock per nome valgono solo sul server che li prende.
* Plugin **Raffaello Identity** per l'autenticazione SSO e per l'aggancio degli acquisti al profilo (`sub` di Identity).
* Facoltativi: **LearnDash** (corsi), **ACF** (riquadro della pagina come field group), **YOOtheme Pro** (elemento e condizioni), **WooCommerce** (log).
* Sul Bookshop: il plugin Raffaello Licenze — Bookshop, con WooCommerce, Action Scheduler e License Manager for WooCommerce 3.0.20. Il segreto condiviso si imposta dalle impostazioni dei due plugin, oppure con le costanti in `wp-config.php`.
* Con nginx (che ignora il .htaccess) una regola che neghi l'accesso diretto a wp-content/uploads/rcl_materiali/: se manca, il plugin mostra agli amministratori la regola da aggiungere.

== Changelog ==

= 1.5.1 =
* Nuovo: il segreto condiviso con il Bookshop si imposta da Licenze > Impostazioni, con "Genera segreto" (mostrato una volta sola) oppure incollandolo. È salvato senza caricamento automatico e vale solo sul sito che l'ha salvato: un database copiato da un altro ambiente non si porta dietro il collegamento. Non si rilegge mai: il campo vuoto lo lascia com'è, per toglierlo c'è "Cancella segreto". La costante RCL_BOOKSHOP_SECRET in wp-config.php resta e ha la precedenza, anche vuota.
* Nuovo: evento "ping" sull'endpoint raffaello-licenze/v1/eventi, firmato come gli altri. Risponde con versione, ora e SKU senza una licenza attiva, senza toccare licenze e attivazioni. Lo usa "Prova connessione" del plugin del Bookshop; anche tools/push-di-prova.php prepara il ping.
* Nuovo: Licenze > Impostazioni mostra lo stato della configurazione, con il link per correggere: segreto, tabelle, licenze vendute e contenuti pubblicati, acquisti in attesa, pagina "I miei contenuti", LearnDash e impostazioni dei corsi, cron delle scadenze, Raffaello Identity, https, cartella protetta, ultima richiesta dal Bookshop. Finché non sono risolti, i problemi bloccanti compaiono anche in Bacheca, nelle altre pagine del plugin e in Strumenti > Salute del sito.
* Nuovo: si registra l'ultima richiesta del Bookshop con firma valida (ora, evento, esito, IP). I rifiuti restano solo nel log.
* Modificato: un segreto con meno di 32 caratteri, anche nella costante, non vale più e l'endpoint risponde 503.
* Modificato: il codice del Bookshop non è più uno snippet in tools/bookshop ma il plugin "Raffaello Licenze — Bookshop". tools/bookshop/README.md rimanda al plugin e riporta l'ordine del go-live.

= 1.5.0 =
* Nuovo: il plugin si chiama "Raffaello Licenze" (cambia solo il nome visibile; slug, text domain e prefissi restano). Pagine, materiali e corsi LearnDash si sbloccano con licenze, che si ottengono con un codice stampato sul libro, un acquisto sul Bookshop o un'attivazione dell'assistenza.
* Nuovo: gli acquisti sul Bookshop si attivano da soli sul profilo Raffaello Identity dell'acquirente, subito o al primo accesso, con la scadenza decisa dal Bookshop. Rimborso totale o annullamento revocano la licenza, e la revoca è definitiva. Con quantità maggiore di 1 l'acquirente riceve il primo codice e può regalare gli altri.
* Nuovo: endpoint raffaello-licenze/v1/eventi firmato HMAC-SHA256 (segreto RCL_BOOKSHOP_SECRET in wp-config), idempotente sull'id della licenza, con un evento per ordine alla volta. Nel repository: lo snippet per il Bookshop (tools/bookshop, invii a lotti di 50 licenze e 64 KB, nuovi tentativi, azione "Reinvia licenze a Raffaello Scuola") e tools/push-di-prova.php.
* Nuovo: iscrizione automatica ai corsi LearnDash delle licenze attive e disiscrizione alla scadenza o alla revoca, verificate sullo stato reale di LearnDash; le iscrizioni manuali e di gruppo non si toccano; cron giornaliero e pulsante "Riallinea LearnDash"; una sincronizzazione non riuscita fa riprovare il Bookshop.
* Nuovo: pagina "I miei contenuti" ([raffaello_miei_contenuti]), con licenze attive e scadute, scadenze, link ai contenuti e form del codice.
* Nuovo: menu "Licenze" con catalogo (SKU, durata, contenuti, codici stampati), codici stampati e acquistati (gli acquistati mascherati), import CSV, attivazioni con ricerca, revoca, riattivazione e attivazione manuale, impostazioni. Le ricerche per email, sub e codice viaggiano in POST e non finiscono negli URL.
* Nuovo: nella libreria media il filtro "Materiali protetti" (vista elenco e menu dei filtri di griglia e modal) mostra i file della cartella protetta, a chi vede gli allegati privati.
* Migliorato: nel riquadro della pagina si scelgono le licenze. Il codice inserito a mano dà messaggi chiari (già attivo, accesso scaduto o revocato, già utilizzato, non più attivo, scaduto, in attesa di configurazione, codice di RAF Lab o di Raffaello Digitale) e dopo 10 codici non validi in un'ora si aspetta. Senza JavaScript compaiono gli stessi esiti e gli stessi link, anche sulla pagina appena sbloccata (in testa al contenuto, oppure in un riquadro fisso sulle pagine senza loop come quelle del builder YOOtheme).
* Modificato (riservatezza): una pagina con il blocco attivo e nessuna licenza resta bloccata per tutti tranne gli amministratori, con un avviso nell'editor. La revoca di un codice toglie l'accesso anche se una richiesta concorrente ha lasciato un'attivazione attiva.
* Modificato: l'IP registrato al riscatto non viene più da X-Forwarded-For. Nei log non ci sono mai codici completi, segreti né SQL, e l'email del cliente è mascherata.
* Aggiornamento dalla 1.4: migrazione automatica e ripetibile. Ogni codice con contenuti diventa una licenza e ogni riscatto un'attivazione con la scadenza di prima; le pagine bloccate restano bloccate e le tabelle della 1.4 restano. La versione del database avanza solo se tabelle, colonne e indici esistono davvero e la migrazione è riuscita.
* Requisiti: MySQL 5.7.5+ o MariaDB 10.0.2+, un solo server di database (niente SELECT instradate su repliche).

= 1.4.4 =
* Corretto: i file dei materiali ora sono davvero protetti. Prima passava dal plugin solo il link di download: il file restava in uploads con un indirizzo pubblico, scaricabile da chiunque anche senza codice ed elencato in /wp-json/wp/v2/media e nella pagina allegato. Ora i file caricati dal riquadro "Materiali sbloccabili" finiscono in wp-content/uploads/rcl_materiali, chiusa da un .htaccess di diniego (Apache 2.2 e 2.4), senza anteprime né miniature, e si scaricano solo dalla pagina dopo lo sblocco.
* Gli allegati dei materiali sono privati: non compaiono nella REST pubblica dei media e non hanno una pagina allegato pubblica. Amministratori e redattori li vedono nella libreria media; gli autori no, perché WordPress richiede il permesso read_private_posts.
* Dalla libreria media si possono aggiungere solo file già protetti che l'utente può modificare: un file pubblico, o di un altro utente, scelto dalla libreria viene scartato al salvataggio, con un avviso che dice di caricarlo dal riquadro. I materiali già salvati restano.
* Nuovo: per i materiali salvati prima di questa versione gli amministratori trovano in bacheca il pulsante "Proteggi i file dei materiali". Lo spostamento mantiene il percorso (2023/01/manuale.pdf diventa rcl_materiali/2023/01/manuale.pdf), sposta insieme al file anche anteprime, miniature, originale non scalato e backup delle immagini modificate, così si può sempre tornare indietro (nella libreria le loro anteprime non si vedono più: la cartella risponde 403), e riferisce quanti file sono riusciti e quanti no; quelli non riusciti restano al loro posto e si possono ritentare. I materiali il cui file non si trova su disco compaiono nell'avviso come da verificare. Gli indirizzi diretti dei file spostati smettono di funzionare, anche dove erano usati fuori dai materiali: dopo lo spostamento conviene svuotare la cache della CDN.
* Nuovo: controllo di salute giornaliero, in bacheca e nelle pagine del plugin. Se un file della cartella protetta risulta scaricabile (tipico di nginx, che ignora il .htaccess) gli amministratori vedono la regola nginx da aggiungere; se la verifica non è possibile, un avviso spiega come controllare a mano. "Ricontrolla ora" ripete la verifica.
* Rimosso uninstall.php: disinstallando il plugin non si cancellano più tabelle, opzioni e materiali.

= 1.4.3 =
* Corretto (riservatezza): i contenuti bloccati non sono più leggibili, da chi non ha accesso, fuori dalla pagina stessa. Vale per le pagine con "Blocca l'intera pagina con il codice" attivo e almeno un codice associato e per i contenuti il cui layout YOOtheme usa la condizione "Accesso materiali (pagina corrente)". REST API (compresi elenchi, ricerca e tipi di contenuto personalizzati), feed, estratti (ricerca, archivi, embed), post inclusi in altre pagine (Query Loop) e campi Contenuto/Teaser dei layout YOOtheme mostrano "Contenuto riservato." al posto del testo; dalla REST spariscono anche note a piè di pagina e campi ACF. Prima la REST restituiva l'intero contenuto, compreso il layout YOOtheme con le sezioni nascoste. Come prima, vedono tutto gli amministratori e chi ha riscattato un codice valido; nell'editor a blocchi chi può modificare il contenuto lo riceve completo.

= 1.4.2 =
* Nuovo: sotto al campo "Codici Libro — Sblocco" della pagina viene ora mostrato un riepilogo in sola lettura dei codici associati, con il loro stato (Attivo / Scaduto / Disattivo) e un link per gestirli nel menu Codici Libro. Così si vede subito quali codici esistono e in che stato sono.

= 1.4.1 =
* Corretto: il pulsante "Accedi" mostrato nei form di sblocco (compresa la pagina bloccata) per gli utenti non autenticati ora usa il login di Raffaello Identity (SSO, OIDC) tramite ri_login_url(), come il link del menu, invece del login standard di WordPress. Se Raffaello Identity non è attivo si usa il fallback a wp_login_url().

= 1.4.0 =
* Nuovo: blocco dell'intera pagina con il codice. Nel riquadro "Codici Libro — Sblocco" è disponibile l'interruttore "Blocca l'intera pagina con il codice": se attivo (e la pagina ha codici associati), gli utenti che non hanno riscattato un codice valido vedono il form di sblocco al posto del contenuto, mentre amministratori e utenti abilitati vedono la pagina normalmente. Dopo lo sblocco la pagina viene mostrata.

= 1.3.3 =
* Corretto: il campo per-pagina "Codici Libro — Sblocco" (versione ACF) non salvava i codici digitati. Il salvataggio leggeva il valore con get_field(), che il filtro acf/load_value sostituisce con le associazioni già presenti, perdendo l'input. Ora i codici digitati sulla pagina vengono creati e associati correttamente e risultano visibili sia sulla pagina sia nel menu Codici Libro.

= 1.3.2 =
* Reintrodotto il gating di sezione per YOOtheme (disattivato nella 1.3.1). Corretto il formato di registrazione del listener "source.init": ora è un metodo statico registrato come da convenzione YOOtheme (core/WooCommerce), risolvendo l'errore fatale della 1.3.0. Il tipo "Site" espone il campo booleano "Accesso materiali (pagina corrente)" (gruppo Codici Libro) marcato come condizione: usalo nelle Access/Dynamic Condition del builder ("non vuoto" per la sezione protetta, condizione inversa per la sezione con il form [raffaello_codice]). Dopo l'aggiornamento aprire una volta il customizer YOOtheme per rigenerare lo schema.

= 1.3.1 =
* Correzione urgente: disattivata l'integrazione "source.init" introdotta nella 1.3.0 (campo "Accesso materiali" per il gating di sezione), che causava un errore fatale durante il render front-end delle pagine YOOtheme. Il resto del plugin (materiali, codici, riscatti, download, elemento builder) torna a funzionare. Il gating di sezione verrà reintrodotto in una versione successiva, una volta corretta l'integrazione con la source di YOOtheme.

= 1.3.0 =
* Nuovo: integrazione con le Access Condition / Dynamic Condition di YOOtheme Pro. La source "Site" espone il campo booleano "Accesso materiali (pagina corrente)" (gruppo Codici Libro): permette di mostrare/nascondere intere sezioni del builder in base ai codici riscattati dall'utente per la pagina (gating di sezione lato server). Usare la condizione "non vuoto" per la sezione protetta e la condizione inversa per la sezione con il form [raffaello_codice]. Dopo l'aggiornamento aprire una volta il customizer YOOtheme per rigenerare lo schema.

= 1.2.1 =
* Corretto: i messaggi di errore del riscatto via AJAX (es. "Codice non valido", "Questo codice è scaduto") ora vengono mostrati correttamente al posto del messaggio generico.
* Migliorato: l'IP registrato ad ogni riscatto considera l'IP reale del client dietro reverse proxy/CDN (es. Cloudflare via CF-Connecting-IP), con fallback a REMOTE_ADDR. Intestazioni filtrabili con 'rcl_client_ip_headers'.

= 1.2.0 =
* Campo per-pagina (ACF o meta box nativo) per inserire i codici che sbloccano la pagina, con creazione automatica dei codici nuovi.

= 1.1.0 =
* Integrazione YOOtheme Pro (elemento builder "Materiali Codici Libro") e script di seed per staging.

= 1.0.0 =
* Prima versione: riscatto codici, sblocco contestuale materiali, download protetto, backoffice e import CSV.
