=== Raffaello Codici Libro ===
Contributors: grupporaffaello
Tags: codici, sblocco, materiali, download, scuola, identity, sso
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Sblocco di aree riservate e materiali scaricabili tramite i codici stampati sui libri scolastici, integrato con Raffaello Identity (SSO).

== Descrizione ==

Il plugin consente allo studente o al docente autenticato (tramite Raffaello Identity / SSO) di inserire il codice presente sul libro per sbloccare i materiali scaricabili associati ai contenuti del sito.

Funzionalità (Ipotesi A — MVP):

* Form di inserimento codice nell'area riservata e **form di sblocco contestuale** direttamente nelle pagine con materiali.
* I materiali bloccati sono mostrati **in anteprima con stato "Bloccato"**; dopo lo sblocco diventano scaricabili.
* **Download protetto**: i file non sono raggiungibili via URL diretto senza il relativo diritto.
* Registrazione dell'abbinamento **codice ↔ utente** ad ogni riscatto (data, ora, IP).
* Lo stesso codice è condiviso tra tutte le copie del libro e può sbloccare più contenuti; un contenuto può essere sbloccato da più codici.
* Backoffice: gestione codici, associazione ai contenuti, import CSV, elenco riscatti.
* Aggiornamento automatico da GitHub.

== Utilizzo ==

1. Definire i materiali di una pagina dal meta box **"Materiali sbloccabili (Codici Libro)"**.
2. Inserire lo shortcode `[raffaello_materiali]` nel contenuto (oppure `[raffaello_materiali post_id="123"]`).
3. Creare i codici da **Codici Libro → Codici** (o importarli via CSV) e associarli ai contenuti.
4. Lo shortcode `[raffaello_codice]` mostra il solo form di inserimento (es. nell'area riservata).

== Requisiti ==

* WordPress 5.8+, PHP 7.4+
* Plugin **Raffaello Identity** per l'autenticazione SSO degli utenti.

== Changelog ==

= 1.0.0 =
* Prima versione: riscatto codici, sblocco contestuale materiali, download protetto, backoffice e import CSV.
