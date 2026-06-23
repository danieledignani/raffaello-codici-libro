/* Raffaello Codici Libro — riscatto via AJAX con ricaricamento al successo. */
(function ($) {
    'use strict';

    $(document).on('submit', '.rcl-form', function (e) {
        // Senza JS il form fa POST classico verso admin-post; con JS usiamo AJAX.
        if (typeof rclData === 'undefined') {
            return;
        }
        e.preventDefault();

        var $form = $(this);
        var $msg = $form.find('.rcl-message');
        var $btn = $form.find('button[type="submit"]');
        var codice = $form.find('input[name="codice"]').val();
        var postId = $form.find('input[name="post_id"]').val();

        $btn.prop('disabled', true);
        $msg.removeClass('rcl-message--ok rcl-message--ko').text('');

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
            if (res && res.success) {
                $msg.addClass('rcl-message--ok').text(res.data.message);
                // Se sblocca il contenuto corrente, ricarica per mostrare i download.
                if (res.data.unlocks_current) {
                    window.location.reload();
                }
            } else {
                var m = (res && res.data && res.data.message) ? res.data.message : rclData.i18n.error;
                $msg.addClass('rcl-message--ko').text(m);
            }
        }).fail(function () {
            $msg.addClass('rcl-message--ko').text(rclData.i18n.error);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });
})(jQuery);
