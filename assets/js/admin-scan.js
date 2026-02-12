/**
 * OmniPrivacy Pro — Admin PII Scan JS
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		// Démarrer un scan.
		$('#omniprivacy-start-scan').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true).text(omniprivacyAdmin.i18n.scanRunning);

			$.post(omniprivacyAdmin.ajaxUrl, {
				action: 'omniprivacy_start_scan',
				nonce: omniprivacyAdmin.nonce
			}, function (response) {
				if (response.success) {
					$('#omniprivacy-scan-status').text(response.data.message).show();
				} else {
					$('#omniprivacy-scan-status').text(response.data.message).show();
				}
				$btn.prop('disabled', false).text($btn.data('label'));
			}).fail(function () {
				$btn.prop('disabled', false).text($btn.data('label'));
			});
		});

		// Anonymiser un élément.
		$(document).on('click', '.omniprivacy-anonymize', function (e) {
			e.preventDefault();
			if (!confirm(omniprivacyAdmin.i18n.confirmAnon)) {
				return;
			}

			var $btn = $(this);
			var itemId = $btn.data('id');

			$.post(omniprivacyAdmin.ajaxUrl, {
				action: 'omniprivacy_anonymize_item',
				nonce: omniprivacyAdmin.nonce,
				item_id: itemId
			}, function (response) {
				if (response.success) {
					$btn.closest('tr').fadeOut();
				} else {
					alert(response.data.message);
				}
			});
		});

		// Ignorer un élément.
		$(document).on('click', '.omniprivacy-ignore', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var itemId = $btn.data('id');

			$.post(omniprivacyAdmin.ajaxUrl, {
				action: 'omniprivacy_ignore_item',
				nonce: omniprivacyAdmin.nonce,
				item_id: itemId
			}, function (response) {
				if (response.success) {
					$btn.closest('tr').fadeOut();
				} else {
					alert(response.data.message);
				}
			});
		});
	});
})(jQuery);
