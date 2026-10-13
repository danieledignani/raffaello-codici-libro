<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Corsi LearnDash sbloccati dalle licenze. Un solo servizio, sync(), chiamato
 * dopo ogni cambio di diritti; un cron giornaliero per le scadenze. Senza
 * LearnDash non fa niente.
 */
class LearnDash
{
    /** User meta con i corsi iscritti dal plugin: solo questi si disiscrivono. */
    const META = 'rcl_corsi_gestiti';

    const CRON = 'rcl_sincronizza_scadenze';
    const LAST_RUN = 'rcl_ultima_sincronizzazione';

    public function register(): void
    {
        add_action(self::CRON, [self::class, 'sync_expired']);
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
        }
    }

    public static function active(): bool
    {
        return function_exists('ld_update_course_access') && function_exists('sfwd_lms_has_access');
    }

    /**
     * Iscrive l'utente ai corsi coperti dalle sue attivazioni valide che non
     * può già seguire, e disiscrive solo dai corsi iscritti dal plugin che non
     * sono più coperti. Le iscrizioni manuali o di gruppo non si toccano. Una
     * sincronizzazione per utente alla volta, dalla lettura dei diritti al
     * salvataggio dei corsi gestiti; se il lock non arriva è un errore. Se
     * LearnDash non arriva allo stato voluto, si salva quello che è riuscito e
     * poi si lancia un'eccezione: il push risponde 500 e il Bookshop riprova.
     */
    public static function sync(int $user_id): void
    {
        global $wpdb;
        if (!self::active()) {
            return;
        }
        if (!Database::lock('rcl_utente_' . $user_id, 5)) {
            throw new \RuntimeException('Sincronizzazione LearnDash già in corso per l\'utente');
        }

        try {
            $covered = array_map('intval', Database::get_col($wpdb->prepare(
                'SELECT DISTINCT lp.post_id
                 FROM ' . Database::table('attivazioni') . ' a
                 JOIN ' . Database::table('licenze') . ' l ON l.id = a.licenza_id
                 JOIN ' . Database::table('licenza_post') . ' lp ON lp.licenza_id = a.licenza_id
                 LEFT JOIN ' . Database::table('codici') . " c ON c.id = a.codice_id
                 JOIN {$wpdb->posts} p ON p.ID = lp.post_id
                 WHERE a.user_id = %d AND p.post_type = 'sfwd-courses' AND " . Database::valid_activation(),
                $user_id,
                Database::now()
            )));
            $managed = array_map('intval', array_filter((array) get_user_meta($user_id, self::META, true)));
            $failed = 0;

            // Conta lo stato finale, non il valore restituito: LearnDash dà false anche quando non
            // c'era niente da fare. Un corso entra fra i gestiti solo se l'accesso c'è davvero.
            foreach ($covered as $course_id) {
                if (sfwd_lms_has_access($course_id, $user_id)) {
                    continue;
                }
                ld_update_course_access($user_id, $course_id);
                if (sfwd_lms_has_access($course_id, $user_id)) {
                    $managed[] = $course_id;
                } else {
                    $failed++;
                }
            }
            // Esce dai gestiti se l'accesso non c'è più, o se quello che resta non è l'iscrizione
            // diretta (un gruppo aggiunto dopo la nostra iscrizione): quello non è nostro da togliere.
            foreach (array_diff($managed, $covered) as $course_id) {
                ld_update_course_access($user_id, $course_id, true);
                if (!sfwd_lms_has_access($course_id, $user_id) || !get_user_meta($user_id, 'course_' . $course_id . '_access_from', true)) {
                    $managed = array_diff($managed, [$course_id]);
                } else {
                    $failed++;
                }
            }

            $managed = array_values(array_unique($managed));
            if ($managed) {
                update_user_meta($user_id, self::META, $managed);
            } else {
                delete_user_meta($user_id, self::META);
            }
            if ($failed) {
                throw new \RuntimeException('LearnDash non ha applicato tutte le iscrizioni');
            }
        } finally {
            Database::unlock('rcl_utente_' . $user_id);
        }
    }

    /**
     * Sincronizza tutti gli utenti, anche dopo un errore; alla fine, se qualcuno
     * è fallito, un'eccezione: il push risponde 500 e il Bookshop riprova.
     */
    public static function sync_users(array $user_ids): void
    {
        if (self::sync_each($user_ids)) {
            throw new \RuntimeException('Sincronizzazione LearnDash non riuscita');
        }
    }

    /** Sincronizza ogni utente anche se uno fallisce; restituisce quanti sono falliti (già nel log). */
    private static function sync_each(array $user_ids): int
    {
        $failed = 0;
        foreach (array_unique(array_map('intval', $user_ids)) as $user_id) {
            try {
                self::sync($user_id);
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Sincronizzazione LearnDash non riuscita (' . get_class($e) . ').', ['user_id' => $user_id]);
            }
        }
        return $failed;
    }

    /**
     * Cron giornaliero: utenti con attivazioni scadute dall'ultimo passaggio.
     * Un errore su un utente non ferma gli altri, ma il passaggio non avanza:
     * il giorno dopo si riprovano tutti.
     */
    public static function sync_expired(): void
    {
        global $wpdb;
        if (!self::active()) {
            return;
        }
        $now = Database::now();
        $failed = self::sync_each(Database::get_col($wpdb->prepare(
            'SELECT DISTINCT user_id FROM ' . Database::table('attivazioni') . ' WHERE user_id IS NOT NULL AND scade_il > %s AND scade_il <= %s',
            get_option(self::LAST_RUN, '1970-01-01 00:00:00'),
            $now
        )));
        if (!$failed) {
            update_option(self::LAST_RUN, $now, false);
        }
    }

    /**
     * "Riallinea LearnDash": tutti gli utenti con attivazioni o con iscrizioni del
     * plugin. Un errore su un utente non ferma gli altri. Restituisce [utenti, errori].
     */
    public static function realign_all(): array
    {
        global $wpdb;
        $users = array_unique(array_map('intval', array_merge(
            Database::get_col('SELECT DISTINCT user_id FROM ' . Database::table('attivazioni') . ' WHERE user_id IS NOT NULL'),
            Database::get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::META))
        )));
        return [count($users), self::sync_each($users)];
    }
}
