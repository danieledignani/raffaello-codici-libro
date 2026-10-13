/**
 * Voce "Materiali protetti" nel filtro della libreria media (griglia e modal).
 * Le altre voci azzerano il parametro, altrimenti resterebbe attivo cambiando filtro.
 */
(function (media) {
    if (!media || !media.view || !media.view.AttachmentFilters || !media.view.AttachmentFilters.All) {
        return;
    }

    var All = media.view.AttachmentFilters.All;

    media.view.AttachmentFilters.All = All.extend({
        createFilters: function () {
            All.prototype.createFilters.apply(this, arguments);

            _.each(this.filters, function (filter) {
                filter.props.rcl_materiali = null;
            });

            this.filters.rcl_materiali = {
                text: window.rclMediaFiltro.label,
                props: {
                    rcl_materiali: 1,
                    status: null,
                    type: null,
                    uploadedTo: null,
                    orderby: 'date',
                    order: 'DESC'
                },
                priority: 70
            };
        }
    });
})(window.wp && window.wp.media);
