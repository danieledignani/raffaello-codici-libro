<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controlli di configurazione: un solo elenco, mostrato in tre posti. La pagina
 * Impostazioni li mostra tutti con lo stato, l'avviso in bacheca solo i bloccanti,
 * Salute del sito ne fa un test. Nessuna richiesta HTTP: la protezione dei file
 * si legge dalla cache del controllo di FileProtection.
 */
class Health
{
    const OK = 'ok';
    const BLOCCANTE = 'bloccante';
    const ATTENZIONE = 'attenzione';
    const INFO = 'informativo';
    const NON_VERIFICABILE = 'non_verificabile';

    public function register(): void
    {
        add_action('admin_notices', [$this, 'notice']);
        add_filter('site_status_tests', [$this, 'site_status_tests']);
    }

    /**
     * Tutti i controlli, anche quelli a posto: id, stato, titolo, testo e, se c'è
     * qualcosa da correggere, il link dove farlo.
     *
     * @return array<int, array{id: string, stato: string, titolo: string, testo: string, link: ?string}>
     */
    public static function problems(): array
    {
        // Un errore del database non deve sembrare una configurazione a posto.
        try {
            return self::checks();
        } catch (\RuntimeException $e) {
            Log::error('Controlli di configurazione non riusciti (' . get_class($e) . ').');
            return [self::item('tabelle', self::BLOCCANTE, __('Tabelle del plugin', 'raffaello-codici-libro'), __('Le tabelle del plugin non si leggono: guarda il log (sorgente raffaello-licenze).', 'raffaello-codici-libro'))];
        }
    }

    private static function checks(): array
    {
        global $wpdb;
        $licenze = Database::table('licenze');
        $impostazioni = admin_url('admin.php?page=rcl_impostazioni');
        $p = [];

        // Segreto: bloccante solo se si vende qualcosa sul Bookshop.
        $vendibili = (int) Database::get_var("SELECT COUNT(*) FROM {$licenze} WHERE sku IS NOT NULL AND attiva = 1");
        $config = Config::state();
        $titolo = __('Segreto del Bookshop', 'raffaello-codici-libro');
        if ($config['problema'] === null) {
            $p[] = self::item('segreto', self::OK, $titolo, $config['fonte'] === 'wp-config'
                ? __('Definito in wp-config.php (RCL_BOOKSHOP_SECRET).', 'raffaello-codici-libro')
                : __('Salvato nelle impostazioni.', 'raffaello-codici-libro'));
        } else {
            $testi = [
                'mancante'   => __('Non impostato: gli acquisti dal Bookshop vengono rifiutati (503). Generalo in Licenze > Impostazioni e copialo nelle impostazioni del plugin del Bookshop.', 'raffaello-codici-libro'),
                /* translators: %s: host del sito che ha salvato il segreto */
                'altro_host' => sprintf(__('Salvato da un altro sito (%s): il database viene da un altro ambiente e qui il segreto non vale. Generane uno per questo sito in Licenze > Impostazioni.', 'raffaello-codici-libro'), $config['host'] ?? ''),
                'vuoto'      => __('RCL_BOOKSHOP_SECRET è definita in wp-config.php ma vuota: gli acquisti vengono rifiutati e le impostazioni non contano.', 'raffaello-codici-libro'),
                /* translators: %s: wp-config.php o impostazioni */
                'corto'      => sprintf(__('Troppo corto in %s: servono almeno 32 caratteri. Gli acquisti vengono rifiutati.', 'raffaello-codici-libro'), $config['fonte'] === 'wp-config' ? 'wp-config.php' : __('impostazioni', 'raffaello-codici-libro')),
            ];
            $p[] = self::item('segreto', $vendibili ? self::BLOCCANTE : self::ATTENZIONE, $titolo, $testi[$config['problema']]
                . ($vendibili ? '' : ' ' . __('Nessuna licenza ha ancora lo SKU di un prodotto: per ora servono solo i codici stampati.', 'raffaello-codici-libro')),
                $config['fonte'] === 'wp-config' ? null : $impostazioni . '#rcl-segreto');
        }

        $versione = get_option('rcl_db_version');
        $p[] = $versione === RCL_VERSION
            ? self::item('tabelle', self::OK, __('Tabelle del plugin', 'raffaello-codici-libro'), __('Aggiornate.', 'raffaello-codici-libro'))
            /* translators: 1: versione delle tabelle, 2: versione del plugin */
            : self::item('tabelle', self::BLOCCANTE, __('Tabelle del plugin', 'raffaello-codici-libro'), sprintf(__('Non aggiornate (%1$s invece di %2$s): si riprova a ogni richiesta. Se resta così, guarda il log (sorgente raffaello-licenze).', 'raffaello-codici-libro'), $versione ?: '—', RCL_VERSION));

        $p[] = $vendibili
            /* translators: %d: numero di licenze */
            ? self::item('licenze', self::OK, __('Licenze vendute sul Bookshop', 'raffaello-codici-libro'), sprintf(_n('%d licenza attiva con lo SKU di un prodotto.', '%d licenze attive con lo SKU di un prodotto.', $vendibili, 'raffaello-codici-libro'), $vendibili))
            : self::item('licenze', self::ATTENZIONE, __('Licenze vendute sul Bookshop', 'raffaello-codici-libro'), __('Nessuna licenza attiva ha lo SKU di un prodotto del Bookshop: gli acquisti restano in attesa di configurazione.', 'raffaello-codici-libro'), admin_url('admin.php?page=rcl_licenze'));

        // Conta solo un contenuto pubblicato: uno cestinato o in bozza non sblocca niente.
        $vuote = Database::get_col("SELECT l.nome FROM {$licenze} l WHERE l.sku IS NOT NULL AND l.attiva = 1
            AND NOT EXISTS (SELECT 1 FROM " . Database::table('licenza_post') . " lp JOIN {$wpdb->posts} p ON p.ID = lp.post_id AND p.post_status = 'publish'
                WHERE lp.licenza_id = l.id) ORDER BY l.nome");
        $p[] = $vuote
            /* translators: %s: nomi delle licenze */
            ? self::item('contenuti', self::BLOCCANTE, __('Contenuti delle licenze', 'raffaello-codici-libro'), sprintf(__('Licenze vendute senza contenuti pubblicati: %s. Chi le compra non sblocca niente.', 'raffaello-codici-libro'), implode(', ', $vuote)), admin_url('admin.php?page=rcl_licenze'))
            : self::item('contenuti', self::OK, __('Contenuti delle licenze', 'raffaello-codici-libro'), __('Ogni licenza venduta sblocca almeno un contenuto pubblicato.', 'raffaello-codici-libro'));

        // Il segnale più forte: si vende un prodotto che qui non ha una licenza.
        $attesa = Database::get_results('SELECT sku, COUNT(*) AS n FROM ' . Database::table('codici') . "
            WHERE tipo = 'acquistato' AND licenza_id IS NULL AND revocato_il IS NULL GROUP BY sku ORDER BY sku");
        $p[] = $attesa
            /* translators: %s: SKU con il numero di codici */
            ? self::item('in_attesa', self::BLOCCANTE, __('Acquisti in attesa', 'raffaello-codici-libro'), sprintf(__('Codici acquistati senza una licenza con il loro SKU: %s. Crea la licenza con quello SKU: al salvataggio si attivano.', 'raffaello-codici-libro'), implode(', ', array_map(function ($r) {
                return ($r->sku ?? '—') . ' (' . (int) $r->n . ')';
            }, $attesa))), admin_url('admin.php?page=rcl_codici&stato=in_attesa'))
            : self::item('in_attesa', self::OK, __('Acquisti in attesa', 'raffaello-codici-libro'), __('Nessun codice acquistato aspetta una licenza.', 'raffaello-codici-libro'));

        $p[] = self::my_contents_page();

        // LearnDash: conta solo per i corsi collegati a licenze attive.
        $corsi = array_map('intval', Database::get_col("SELECT DISTINCT lp.post_id FROM " . Database::table('licenza_post') . " lp
            JOIN {$licenze} l ON l.id = lp.licenza_id AND l.attiva = 1
            JOIN {$wpdb->posts} p ON p.ID = lp.post_id AND p.post_type = 'sfwd-courses' AND p.post_status <> 'trash'"));
        $titolo = __('LearnDash', 'raffaello-codici-libro');
        if (!$corsi) {
            $p[] = self::item('learndash', self::OK, $titolo, __('Nessuna licenza ha corsi.', 'raffaello-codici-libro'));
        } elseif (!LearnDash::active()) {
            /* translators: %d: numero di corsi */
            $p[] = self::item('learndash', self::BLOCCANTE, $titolo, sprintf(_n('%d corso collegato alle licenze, ma LearnDash non è attivo: chi ha la licenza non viene iscritto.', '%d corsi collegati alle licenze, ma LearnDash non è attivo: chi ha la licenza non viene iscritto.', count($corsi), 'raffaello-codici-libro'), count($corsi)), admin_url('plugins.php'));
        } else {
            /* translators: %d: numero di corsi */
            $p[] = self::item('learndash', self::OK, $titolo, sprintf(_n('Attivo, %d corso collegato alle licenze.', 'Attivo, %d corsi collegati alle licenze.', count($corsi), 'raffaello-codici-libro'), count($corsi)));
        }
        // In modalità aperta o gratuita si entra nel corso senza licenza.
        $p[] = self::course_setting($corsi, 'modalita', __('Corsi in modalità «Chiusa»', 'raffaello-codici-libro'), function ($id) {
            return learndash_get_setting($id, 'course_price_type') !== 'closed';
        }, self::BLOCCANTE, __('Corsi non in modalità «Chiusa»: %s. In un\'altra modalità ci si iscrive senza licenza, o si paga su LearnDash.', 'raffaello-codici-libro'));
        // Come ld_course_access_expires_on() di LearnDash 5.0.2: conta solo con i giorni impostati.
        $p[] = self::course_setting($corsi, 'scadenza_corsi', __('Corsi senza scadenza dell\'accesso di LearnDash', 'raffaello-codici-libro'), function ($id) {
            return learndash_get_setting($id, 'expire_access') && abs((int) learndash_get_setting($id, 'expire_access_days')) > 0;
        }, self::ATTENZIONE, __('Corsi con la scadenza dell\'accesso di LearnDash: %s. La scadenza la decide la licenza: con quella di LearnDash l\'accesso può finire prima.', 'raffaello-codici-libro'));
        $p[] = self::expiry_cron();

        $p[] = function_exists('ri_login_url')
            ? self::item('identity', self::OK, __('Raffaello Identity', 'raffaello-codici-libro'), __('Attivo.', 'raffaello-codici-libro'))
            : self::item('identity', self::BLOCCANTE, __('Raffaello Identity', 'raffaello-codici-libro'), __('Non attivo: niente accesso con Identity e gli acquisti non si agganciano al profilo dell\'acquirente.', 'raffaello-codici-libro'), admin_url('plugins.php'));

        $p[] = wp_is_using_https()
            ? self::item('https', self::OK, __('HTTPS', 'raffaello-codici-libro'), __('Il sito è in https.', 'raffaello-codici-libro'))
            : self::item('https', self::ATTENZIONE, __('HTTPS', 'raffaello-codici-libro'), __('Il sito non è in https: il Bookshop deve chiamare un indirizzo https.', 'raffaello-codici-libro'), admin_url('options-general.php'));

        $p[] = self::file_protection();
        $p[] = self::last_event();

        return $p;
    }

    /** Avviso in bacheca e nelle pagine del plugin, solo per i bloccanti: senza chiusura, sparisce quando sono risolti. */
    public function notice(): void
    {
        $screen = get_current_screen();
        // Non in Impostazioni: lì lo dice già la tabella dei controlli.
        if (!current_user_can('manage_options') || ($screen->id !== 'dashboard' && strpos($screen->id, 'rcl_') === false)
            || strpos($screen->id, 'rcl_impostazioni') !== false) {
            return;
        }
        // La cartella esposta ha già il suo avviso, con la regola nginx.
        $bloccanti = array_filter(self::problems(), function ($p) {
            return $p['stato'] === self::BLOCCANTE && $p['id'] !== 'file';
        });
        if (!$bloccanti) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('Raffaello Licenze: configurazione da completare.', 'raffaello-codici-libro'); ?></strong>
                <a href="<?php echo esc_url(admin_url('admin.php?page=rcl_impostazioni')); ?>"><?php esc_html_e('Licenze > Impostazioni', 'raffaello-codici-libro'); ?></a>
            </p>
            <ul class="ul-disc">
                <?php foreach ($bloccanti as $b) : ?>
                    <li><strong><?php echo esc_html($b['titolo']); ?>:</strong> <?php echo esc_html($b['testo']); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    public function site_status_tests($tests)
    {
        $tests['direct']['rcl_configurazione'] = [
            'label' => __('Configurazione di Raffaello Licenze', 'raffaello-codici-libro'),
            'test'  => [$this, 'site_health_test'],
        ];
        return $tests;
    }

    /** Test di Salute del sito: critico con un bloccante, consigliato con un punto da controllare o non verificabile. */
    public function site_health_test(): array
    {
        $aperti = array_filter(self::problems(), function ($p) {
            return !in_array($p['stato'], [self::OK, self::INFO], true);
        });
        $stati = array_column($aperti, 'stato');
        $status = in_array(self::BLOCCANTE, $stati, true) ? 'critical' : ($aperti ? 'recommended' : 'good');
        $label = [
            'good'        => __('Raffaello Licenze è configurato', 'raffaello-codici-libro'),
            'recommended' => __('Raffaello Licenze ha punti da controllare', 'raffaello-codici-libro'),
            'critical'    => __('Raffaello Licenze ha una configurazione da completare', 'raffaello-codici-libro'),
        ];
        $items = '';
        foreach ($aperti as $a) {
            $items .= '<li><strong>' . esc_html($a['titolo']) . ':</strong> ' . esc_html($a['testo']) . '</li>';
        }
        return [
            'label'       => $label[$status],
            'status'      => $status,
            'badge'       => ['label' => __('Raffaello Licenze', 'raffaello-codici-libro'), 'color' => 'blue'],
            'description' => '<p>' . esc_html__('Licenze, acquisti dal Bookshop e corsi funzionano solo con la configurazione completa.', 'raffaello-codici-libro') . '</p>' . ($items ? '<ul>' . $items . '</ul>' : ''),
            'actions'     => '<p><a href="' . esc_url(admin_url('admin.php?page=rcl_impostazioni')) . '">' . esc_html__('Apri Licenze > Impostazioni', 'raffaello-codici-libro') . '</a></p>',
            'test'        => 'rcl_configurazione',
        ];
    }

    private static function item(string $id, string $stato, string $titolo, string $testo, ?string $link = null): array
    {
        return ['id' => $id, 'stato' => $stato, 'titolo' => $titolo, 'testo' => $testo, 'link' => $link];
    }

    private static function my_contents_page(): array
    {
        $titolo = __('Pagina «I miei contenuti»', 'raffaello-codici-libro');
        $link = admin_url('admin.php?page=rcl_impostazioni');
        // get_post(0) darebbe il post globale.
        $id = (int) get_option(Frontend::OPTION_MIEI_CONTENUTI);
        $post = $id ? get_post($id) : null;
        if (!$post) {
            return self::item('pagina', self::ATTENZIONE, $titolo, __('Non scelta, o eliminata: dopo un riscatto il messaggio non rimanda alla pagina con le licenze.', 'raffaello-codici-libro'), $link);
        }
        if ($post->post_status !== 'publish') {
            return self::item('pagina', self::ATTENZIONE, $titolo, __('La pagina scelta non è pubblicata.', 'raffaello-codici-libro'), get_edit_post_link($post->ID, 'raw'));
        }
        // Lo shortcode è registrato anche in admin: Frontend si registra sempre.
        if (!has_shortcode($post->post_content, 'raffaello_miei_contenuti')) {
            return self::item('pagina', self::ATTENZIONE, $titolo, __('Nel contenuto della pagina scelta non c\'è lo shortcode [raffaello_miei_contenuti]. Se la pagina usa un layout del builder salvato a parte, verifica a mano.', 'raffaello-codici-libro'), get_edit_post_link($post->ID, 'raw'));
        }
        /* translators: %s: titolo della pagina */
        return self::item('pagina', self::OK, $titolo, sprintf(__('«%s», pubblicata, con lo shortcode.', 'raffaello-codici-libro'), get_the_title($post)));
    }

    /**
     * Impostazione di LearnDash da controllare sui corsi delle licenze: $wrong dice se un corso
     * è da correggere, $stato quanto è grave. Senza learndash_get_setting() non si può dire.
     */
    private static function course_setting(array $corsi, string $id, string $titolo, callable $wrong, string $stato, string $testo): array
    {
        if (!$corsi) {
            return self::item($id, self::OK, $titolo, __('Nessun corso collegato alle licenze.', 'raffaello-codici-libro'));
        }
        if (!function_exists('learndash_get_setting')) {
            return self::item($id, self::NON_VERIFICABILE, $titolo, __('LearnDash non è attivo: le impostazioni dei corsi non si leggono.', 'raffaello-codici-libro'));
        }
        $da_correggere = array_values(array_filter($corsi, $wrong));
        if (!$da_correggere) {
            return self::item($id, self::OK, $titolo, __('Tutti i corsi collegati alle licenze sono a posto.', 'raffaello-codici-libro'));
        }
        return self::item($id, $stato, $titolo, sprintf($testo, implode(', ', array_map('get_the_title', $da_correggere))), get_edit_post_link($da_correggere[0], 'raw'));
    }

    /** Il cron giornaliero toglie i corsi scaduti: conta solo con LearnDash. */
    private static function expiry_cron(): array
    {
        $titolo = __('Scadenze dei corsi (cron)', 'raffaello-codici-libro');
        if (!LearnDash::active()) {
            return self::item('cron', self::OK, $titolo, __('Serve solo con LearnDash attivo.', 'raffaello-codici-libro'));
        }
        // LearnDash::register() lo riprogramma a ogni richiesta: se manca, la programmazione non riesce.
        $next = wp_next_scheduled(LearnDash::CRON);
        if (!$next) {
            return self::item('cron', self::ATTENZIONE, $titolo, __('Non programmato: WordPress non riesce a programmare il passaggio giornaliero, e le iscrizioni scadute restano.', 'raffaello-codici-libro'));
        }
        if ($next < time() - DAY_IN_SECONDS) {
            /* translators: %s: data prevista */
            return self::item('cron', self::ATTENZIONE, $titolo, sprintf(__('Il cron di WordPress non gira: il passaggio previsto il %s non è ancora partito, e le iscrizioni scadute restano. Con DISABLE_WP_CRON serve un cron di sistema.', 'raffaello-codici-libro'), Codes::date(gmdate('Y-m-d H:i:s', $next))));
        }
        $last = get_option(LearnDash::LAST_RUN);
        if (!$last) {
            /* translators: %s: data e ora del primo passaggio */
            return self::item('cron', self::INFO, $titolo, sprintf(__('Programmato per il %s, nessun passaggio ancora.', 'raffaello-codici-libro'), get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'd/m/Y H:i')));
        }
        if ($last < gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS)) {
            /* translators: %s: data dell'ultimo passaggio */
            return self::item('cron', self::ATTENZIONE, $titolo, sprintf(__('L\'ultimo passaggio riuscito è del %s: guarda il log (sorgente raffaello-licenze).', 'raffaello-codici-libro'), Codes::date($last)));
        }
        return self::item('cron', self::OK, $titolo, __('Il passaggio giornaliero gira.', 'raffaello-codici-libro'));
    }

    /** Dalla cache del controllo di FileProtection: qui nessuna richiesta HTTP. */
    private static function file_protection(): array
    {
        $titolo = __('Cartella protetta dei materiali', 'raffaello-codici-libro');
        switch (get_transient(FileProtection::CHECK_TRANSIENT)) {
            case 'protetta':
                return self::item('file', self::OK, $titolo, __('I file dei materiali non si scaricano dal loro indirizzo.', 'raffaello-codici-libro'));
            case 'esposta':
                return self::item('file', self::BLOCCANTE, $titolo, __('I file dei materiali si scaricano senza codice: manca la regola nginx (vedi l\'avviso in bacheca).', 'raffaello-codici-libro'), admin_url());
            case 'non_verificabile':
                return self::item('file', self::NON_VERIFICABILE, $titolo, __('Il server non riesce a scaricare un file di prova: verifica a mano come dice l\'avviso in bacheca.', 'raffaello-codici-libro'), admin_url());
        }
        return self::item('file', self::NON_VERIFICABILE, $titolo, __('Non ancora controllata: il controllo parte aprendo la bacheca.', 'raffaello-codici-libro'), admin_url());
    }

    /** Sempre informativo: da qui non si vedono né Cloudflare né la configurazione del Bookshop. */
    private static function last_event(): array
    {
        $titolo = __('Ultima richiesta dal Bookshop', 'raffaello-codici-libro');
        $nota = __('Raggiungibilità (Cloudflare) e configurazione del Bookshop si provano con «Prova connessione» nelle impostazioni del plugin del Bookshop.', 'raffaello-codici-libro');
        $e = get_option(Push::ULTIMO_EVENTO);
        if (!$e) {
            return self::item('ultimo_evento', self::INFO, $titolo, __('Nessuna richiesta con firma valida.', 'raffaello-codici-libro') . ' ' . $nota);
        }
        $esiti = [
            'ok'           => __('riuscita', 'raffaello-codici-libro'),
            'rcl_formato'  => __('formato non valido', 'raffaello-codici-libro'),
            'rcl_occupato' => __('ordine occupato, il Bookshop riprova', 'raffaello-codici-libro'),
            'rcl_errore'   => __('errore interno, il Bookshop riprova', 'raffaello-codici-libro'),
        ];
        /* translators: 1: evento, 2: data e ora, 3: esito, 4: IP */
        return self::item('ultimo_evento', self::INFO, $titolo, sprintf(__('%1$s il %2$s, esito: %3$s, IP %4$s.', 'raffaello-codici-libro'),
            $e['evento'] !== '' ? $e['evento'] : '—',
            get_date_from_gmt($e['ora'], 'd/m/Y H:i'),
            $esiti[$e['esito']] ?? $e['esito'],
            $e['ip'] ?? '—'
        ) . ' ' . $nota);
    }
}
