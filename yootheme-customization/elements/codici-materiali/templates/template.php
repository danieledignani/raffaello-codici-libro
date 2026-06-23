<?php

// Render frontend dell'elemento: delega allo shortcode del plugin, così la
// logica di sblocco/anteprima/download resta in un solo punto.

$el = $this->el('div', $props, $attrs);

echo $el($props, $attrs);

$post_id = !empty($props['post_id']) ? (int) $props['post_id'] : 0;
$shortcode = $post_id ? '[raffaello_materiali post_id="' . $post_id . '"]' : '[raffaello_materiali]';

echo do_shortcode($shortcode);

echo $el->end();
