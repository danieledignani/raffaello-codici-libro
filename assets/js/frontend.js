/* Raffaello Licenze — riscatto via AJAX con ricaricamento al successo. */
(function ($) {
    'use strict';

    $(document).on('submit', '.rcl-form', function (e) {
        // Senza JS il form fa POST classico verso admin-post; con JS usiamo AJAX.
        if (typeof rclData === 'undefined') {
            return;
        }
        e.preventDefault();

        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var codice = $form.find('input[name="codice"]').val();
        var postId = $form.find('input[name="post_id"]').val();

        // L'esito arriva già in HTML dal server, uguale a quello del flusso senza JavaScript.
        function show(data) {
            var $msg = $form.find('.rcl-message');
            if (data && data.html) {
                $msg.replaceWith(data.html);
            } else {
                $msg.removeClass('rcl-message--ok').addClass('rcl-message--ko')
                    .text(data && data.message ? data.message : rclData.i18n.error);
            }
        }

        $btn.prop('disabled', true);
        $form.find('.rcl-message').removeClass('rcl-message--ok rcl-message--ko').text('');

        $.ajax({
            url: rclData.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'rcl_redeem',
                nonce: rclData.nonce,
                codice: codice,
                post_id: postId
            }
        }).done(function (res) {
            show(res ? res.data : null);
            // Se sblocca il contenuto corrente, ricarica per mostrarlo.
            if (res && res.success && res.data.unlocks_current) {
                window.location.reload();
            }
        }).fail(function (jqXHR) {
            // Gli esiti negativi arrivano con stato HTTP 422/401 e il corpo JSON in data.
            show(jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON.data : null);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });
})(jQuery);
