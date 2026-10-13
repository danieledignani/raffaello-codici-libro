/* Raffaello Codici Libro — meta box materiali: righe ripetibili + media picker. */
(function ($) {
    'use strict';

    function bindSelectFile($btn) {
        var frame = wp.media({
            title: 'Seleziona file',
            button: { text: 'Usa questo file' },
            multiple: false
        });
        // Gli upload da questo modal vanno nella cartella protetta (FileProtection::upload_dir).
        // Senza uploader (quota multisite esaurita, browser non supportato) si sceglie solo dalla libreria.
        if (frame.uploader) {
            frame.uploader.options.uploader.params.rcl_materiale = 1;
        }

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            // Un file fuori dalla cartella protetta verrebbe scartato al salvataggio (Materials::save).
            if (attachment.url.indexOf('/rcl_materiali/') === -1) {
                window.alert('«' + (attachment.filename || attachment.title) + '» non è nella cartella protetta: caricalo dalla scheda «Carica file».');
                return;
            }
            var $row = $btn.closest('.rcl-materiale-row');
            $row.find('.rcl-attachment-id').val(attachment.id);
            $row.find('.rcl-attachment-name').text(attachment.filename || attachment.title);
            var $titolo = $row.find('input[name="rcl_materiali_titolo[]"]');
            if (!$titolo.val()) {
                $titolo.val(attachment.title || attachment.filename);
            }
        });

        frame.open();
    }

    $(document).on('click', '.rcl-select-file', function (e) {
        e.preventDefault();
        if (typeof wp === 'undefined' || !wp.media) {
            return;
        }
        bindSelectFile($(this));
    });

    $(document).on('click', '#rcl-add-row', function (e) {
        e.preventDefault();
        var tpl = $('#rcl-materiale-template').html();
        $('#rcl-materiali-rows').append(tpl);
    });

    $(document).on('click', '.rcl-remove-row', function (e) {
        e.preventDefault();
        $(this).closest('.rcl-materiale-row').remove();
    });
})(jQuery);
