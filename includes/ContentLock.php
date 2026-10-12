<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contenuti bloccati: decide chi li vede e li nasconde nei canali diversi dalla
 * pagina (REST, feed, estratti, oEmbed, elenchi, campi dinamici YOOtheme).
 *
 * Un contenuto è bloccato se ha il blocco pagina attivo con almeno un codice
 * associato (vedi PageGate) o se il suo layout YOOtheme usa il campo
 * rcl_page_access (sezioni condizionate). Lo vedono gli amministratori e chi ha
 * l'accesso col codice, come per la pagina e le sezioni; nella REST in
 * context=edit anche chi può modificarlo.
 */
class ContentLock
{
    const PAGE = 'page';
    const SECTIONS = 'sections';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_rest']);
        add_filter('the_content', [$this, 'filter_loop_content'], 99);
        add_filter('render_block_core/post-content', [$this, 'filter_post_content_block'], 10, 3);
        add_filter('get_the_excerpt', [$this, 'filter_excerpt'], 99, 2);
        add_filter('yootheme_source_post_content', [$this, 'filter_source'], 10, 2);
        add_filter('yootheme_source_post_teaser', [$this, 'filter_source'], 10, 2);
    }

    /**
     * Blocco del contenuto per l'utente corrente: PAGE (form al posto della
     * pagina), SECTIONS (sezioni del layout nascoste) oppure null se può vederlo.
     */
    public static function of(int $post_id): ?string
    {
        // Memoria per richiesta. La chiave include l'utente, che cron e CLI possono cambiare.
        static $cache = [];
        $user_id = get_current_user_id();
        $key = $user_id . ':' . $post_id;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if (get_post_meta($post_id, CodeField::META_BLOCK, true) && Database::get_post_codes($post_id)) {
            $lock = self::PAGE;
        } elseif (YooSource::is_used_by($post_id)) {
            $lock = self::SECTIONS;
        } else {
            $lock = null;
        }

        if ($lock && Codes::user_can_access($user_id, $post_id)) {
            $lock = null;
        }

        return $cache[$key] = $lock;
    }

    public function register_rest(): void
    {
        // Gli allegati non si bloccano: niente interruttore né layout, testo in description e caption.
        foreach (array_diff(get_post_types(['show_in_rest' => true]), ['attachment']) as $type) {
            add_filter("rest_prepare_{$type}", [$this, 'filter_rest'], 10, 3);
        }
    }

    /**
     * REST: contenuto, estratto, note a piè di pagina e campi ACF. In context=edit
     * chi può modificare il post riceve tutto (editor a blocchi); agli altri, anche
     * negli elenchi in context=edit concessi a chi ha edit_posts, toglie la forma grezza.
     */
    public function filter_rest($response, $post, $request)
    {
        if (!self::of($post->ID) || ($request['context'] === 'edit' && current_user_can('edit_post', $post->ID))) {
            return $response;
        }

        $data = $response->get_data();
        foreach (['content', 'excerpt'] as $field) {
            if (isset($data[$field]['rendered'])) {
                $data[$field]['rendered'] = self::message();
            }
            unset($data[$field]['raw']);
        }
        unset($data['meta']['footnotes'], $data['acf']);
        $response->set_data($data);

        return $response;
    }

    /**
     * the_content dei post mostrati mentre gira il loop principale: elenchi, feed,
     * post inseriti in una pagina singola da shortcode o plugin. Esclusi la pagina
     * singola stessa (PageGate e condizioni YOOtheme), gli estratti (filter_excerpt
     * ha il post esplicito) e ciò che sta fuori dal loop, dove passa anche il layout
     * YOOtheme con un post globale che non è il suo.
     */
    public function filter_loop_content($content)
    {
        if (!in_the_loop() || doing_filter('get_the_excerpt')) {
            return $content;
        }

        $post_id = (int) get_the_ID();
        if (is_singular() && $post_id === (int) get_queried_object_id()) {
            return $content;
        }

        return self::of($post_id) ? self::message() : $content;
    }

    /**
     * Blocco "Contenuto del post" (Query Loop): dentro e fuori dal loop principale,
     * anche nella REST della pagina che lo contiene. Il core stampa il contenuto del
     * post globale, che coincide con il postId del contesto.
     */
    public function filter_post_content_block($block_content, $parsed_block, $block)
    {
        $post_id = (int) ($block->context['postId'] ?? 0);

        return $post_id && self::of($post_id) ? self::message() : $block_content;
    }

    /** Estratti (ricerca, REST, feed, embed, YOOtheme): sono generati dal contenuto. */
    public function filter_excerpt($excerpt, $post = null)
    {
        $post = get_post($post);

        return $post && self::of($post->ID) ? self::message() : $excerpt;
    }

    /** Campi "Contenuto" e "Teaser" dei post nei layout YOOtheme (griglie, template, ricerca live). */
    public function filter_source($content, $post)
    {
        return self::of($post->ID) ? self::message() : $content;
    }

    /** Testo al posto del contenuto bloccato. */
    private static function message(): string
    {
        return esc_html__('Contenuto riservato.', 'raffaello-codici-libro');
    }
}
