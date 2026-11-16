/**
 * dotm Tickets: selettori immagini (libreria media) e colore nella pagina delle impostazioni.
 */
(function ($) {
	'use strict';

	var i18n = window.dotmticketsAdmin || {};

	$(function () {
		$('.dmt-color').wpColorPicker();

		// Immagine singola (logo, fascia).
		$(document).on('click', '.dmt-pick-image', function (e) {
			e.preventDefault();
			var target = $(this).data('target');
			var frame = wp.media({
				title: i18n.choose,
				button: { text: i18n.use },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
				$('#' + target).val(att.id);
				$('.dmt-image[data-input="' + target + '"]').empty().append($('<img>', { src: url, alt: '' }));
			});
			frame.open();
		});

		// Più immagini (loghi degli sponsor), in ordine.
		$(document).on('click', '.dmt-pick-gallery', function (e) {
			e.preventDefault();
			var target = $(this).data('target');
			var ids = String($('#' + target).val() || '').split(',').filter(Boolean);
			var frame = wp.media({
				title: i18n.chooseMany,
				button: { text: i18n.use },
				library: { type: 'image' },
				multiple: 'add'
			});
			frame.on('open', function () {
				var selection = frame.state().get('selection');
				ids.forEach(function (id) {
					var att = wp.media.attachment(id);
					att.fetch();
					selection.add(att);
				});
			});
			frame.on('select', function () {
				var box = $('.dmt-gallery[data-input="' + target + '"]').empty();
				var chosen = [];
				frame.state().get('selection').each(function (model) {
					var att = model.toJSON();
					var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
					chosen.push(att.id);
					box.append($('<img>', { src: url, alt: '' }));
				});
				$('#' + target).val(chosen.join(','));
			});
			frame.open();
		});

		$(document).on('click', '.dmt-clear', function (e) {
			e.preventDefault();
			var target = $(this).data('target');
			$('#' + target).val('');
			$('[data-input="' + target + '"]').empty();
		});
	});
})(jQuery);
