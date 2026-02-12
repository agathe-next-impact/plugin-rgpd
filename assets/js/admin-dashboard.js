/**
 * OmniPrivacy Pro — Admin Dashboard JS
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		// Gestion du bouton de génération de rapport PDF.
		$('#omniprivacy-generate-report').on('click', function (e) {
			e.preventDefault();
			window.location.href = $(this).data('url');
		});
	});
})(jQuery);
