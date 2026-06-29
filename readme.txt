=== Raffaello Codici Libro ===
Contributors: grupporaffaello
Tags: codici, sblocco, materiali, download, scuola, identity, sso
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later

Sblocco di aree riservate e materiali scaricabili tramite i codici stampati sui libri scolastici, integrato con Raffaello Identity (SSO).

== Descrizione ==

Il plugin consente allo studente o al docente autenticato (tramite Raffaello Identity / SSO) di inserire il codice presente sul libro per sbloccare i materiali scaricabili associati ai contenuti del sito.

Funzionalità (Ipotesi A — MVP):

* Form di inserimento codice nell'area riservata e **form di sblocco contestuale** direttamente nelle pagine con materiali.
* I materiali bloccati sono mostrati **in anteprima con stato "Bloccato"**; dopo lo sblocco diventano scaricabili.
* **Download protetto**: i file non sono raggiungibili via URL diretto senza il relativo diritto.
* Registrazione dell'abbinamento **codice ↔ utente** ad ogni riscatto (data, ora, IP).
* **Campo per-pagina** (ACF se presente, altrimenti meta box nativo) per indicare direttamente sulla pagina i codici che la sbloccano; i codici nuovi vengono creati automaticamente.
* Lo stesso codice è condiviso tra tutte le copie del libro e può sbloccare più contenuti; un contenuto può essere sbloccato da più codici.
* Backoffice: gestione codici, associazione ai contenuti, import CSV, elenco riscatti.
* Integrazione **YOOtheme Pro**: elemento builder "Materiali Codici Libro" (gruppo Raffaello), oltre allo shortcode.
* Aggiornamento automatico da GitHub.

== Utilizzo ==

1. Definire i materiali di una pagina dal meta box **"Materiali sbloccabili (Codici Libro)"**.
2. Indicare i codici che sbloccano la pagina nel campo **"Codici Libro — Sblocco"** (riquadro laterale dell'editor). Nello stesso riquadro, l'interruttore **"Blocca l'intera pagina con il codice"** rende l'intera pagina accessibile solo a chi ha riscattato un codice (agli altri viene mostrato il form di sblocco al posto del contenuto).
3. Inserire lo shortcode `[raffaello_materiali]` nel contenuto (oppure `[raffaello_materiali post_id="123"]`).
4. In alternativa allo shortcode, con YOOtheme Pro trascinare l'elemento **"Materiali Codici Libro"** (gruppo Raffaello) nel layout.
5. In alternativa al campo per-pagina, i codici si possono creare/associare anche da **Codici Libro → Codici** (o importarli via CSV).
6. Lo shortcode `[raffaello_codice]` mostra il solo form di inserimento (es. nell'area riservata).

== Test su staging ==

È incluso uno script di seed (solo CLI) che crea una pagina di esempio con materiali e codici di prova:

`wp eval-file tools/seed.php` oppure `php tools/seed.php` (rimozione: `php tools/seed.php --remove`).

== Requisiti ==

* WordPress 5.8+, PHP 7.4+
* Plugin **Raffaello Identity** per l'autenticazione SSO degli utenti.

== Changelog ==

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
