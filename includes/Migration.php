<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migrazione dei dati della 1.4. Ogni passo salta ciò che ha già fatto, quindi
 * si può ripetere dopo un'interruzione. Le tabelle della 1.4 non si toccano.
 */
class Migration
{
    public static function run(): void
    {
        global $wpdb;
        $codici = Database::table('codici');
        $codice_post = Database::table('codice_post');
        $riscatti = Database::table('riscatti');

        // Il termine per riscattare è la vecchia data_scadenza (ora locale) portata in UTC.
        foreach (Database::get_results("SELECT id, data_scadenza FROM {$codici} WHERE data_scadenza IS NOT NULL AND attivabile_fino IS NULL") as $c) {
            Database::update('codici', ['attivabile_fino' => get_gmt_from_date($c->data_scadenza)], ['id' => (int) $c->id]);
        }

        // Legami della 1.4, senza quelli scritti per errore sulle revisioni (save_post scatta anche per loro).
        $legami = "SELECT cp.codice_id, cp.post_id FROM {$codice_post} cp
                   LEFT JOIN {$wpdb->posts} p ON p.ID = cp.post_id
                   WHERE p.post_type IS NULL OR p.post_type <> 'revision'";

        // Una licenza per ogni codice con contenuti: nome, contenuti e stato del codice.
        $legacy = Database::get_results(
            "SELECT c.* FROM {$codici} c
             WHERE c.tipo = 'stampato' AND c.licenza_id IS NULL
               AND c.id IN (SELECT l.codice_id FROM ({$legami}) l)
             ORDER BY c.id"
        );
        foreach ($legacy as $c) {
            Database::transaction(function () use ($c, $legami, $wpdb) {
                $now = Database::now();
                $licenza_id = Database::insert('licenze', [
                    'nome'           => $c->descrizione !== null && $c->descrizione !== '' ? $c->descrizione : $c->codice,
                    'attiva'         => (int) $c->attivo,
                    'data_creazione' => $now,
                    'data_modifica'  => $now,
                ]);
                Database::query($wpdb->prepare(
                    'INSERT IGNORE INTO ' . Database::table('licenza_post') . " (licenza_id, post_id)
                     SELECT %d, l.post_id FROM ({$legami}) l WHERE l.codice_id = %d",
                    $licenza_id,
                    (int) $c->id
                ));
                Database::update('codici', ['licenza_id' => $licenza_id], ['id' => (int) $c->id, 'licenza_id' => null]);
            });
        }

        // Ogni riscatto diventa un'attivazione con la sua data e la scadenza del codice.
        $rows = Database::get_results(
            "SELECT r.codice_id, r.user_id, r.data_riscatto, r.ip, c.licenza_id, c.attivabile_fino, um.meta_value AS sub
             FROM {$riscatti} r
             JOIN {$codici} c ON c.id = r.codice_id
             LEFT JOIN {$wpdb->usermeta} um ON um.user_id = r.user_id AND um.meta_key = 'ri_oidc_sub'
             WHERE c.licenza_id IS NOT NULL"
        );
        foreach ($rows as $r) {
            $data = get_gmt_from_date($r->data_riscatto);
            Database::insert_ignore('attivazioni', [
                'chiave'         => 'st:' . $r->codice_id . ':' . $r->user_id,
                'licenza_id'     => (int) $r->licenza_id,
                'user_id'        => (int) $r->user_id,
                'sub'            => $r->sub !== null && $r->sub !== '' ? $r->sub : null,
                'codice_id'      => (int) $r->codice_id,
                'origine'        => Activations::STAMPATO,
                'scade_il'       => $r->attivabile_fino,
                'stato'          => Activations::ATTIVA,
                'ip'             => $r->ip,
                'data_creazione' => $data,
                'data_modifica'  => $data,
            ]);
        }

        Log::info(sprintf('Migrazione dalla 1.4: %d licenze create, %d riscatti letti.', count($legacy), count($rows)));
    }
}
