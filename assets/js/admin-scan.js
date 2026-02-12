/**
 * OmniPrivacy Pro — Admin PII Scan JS
 * Gestion du scan avec suivi de progression en temps réel.
 */
(function ($) {
	'use strict';

	var scanPolling = null;

	$(document).ready(function () {
		// Démarrer un scan.
		$('#omniprivacy-start-scan').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var originalLabel = $btn.data('label') || $btn.text();
			$btn.prop('disabled', true).text(omniprivacyAdmin.i18n.scanRunning);

			$('.omniprivacy-scan-progress').show();
			$('#omniprivacy-scan-status').text(omniprivacyAdmin.i18n.scanRunning).show();

			$.post(omniprivacyAdmin.ajaxUrl, {
				action: 'omniprivacy_start_scan',
				nonce: omniprivacyAdmin.nonce
			}, function (response) {
				if (response.success) {
					startProgressPolling(response.data.scan_id, $btn, originalLabel);
				} else {
					$('#omniprivacy-scan-status').text(response.data.message);
					$btn.prop('disabled', false).text(originalLabel);
				}
			}).fail(function () {
				$btn.prop('disabled', false).text(originalLabel);
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
					$btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
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
					$btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
				} else {
					alert(response.data.message);
				}
			});
		});

		// Approbation de demande de suppression.
		$(document).on('click', '.omniprivacy-approve-request', function (e) {
			e.preventDefault();
			if (!confirm(omniprivacyAdmin.i18n.confirmApprove || 'Approuver cette demande ?')) {
				return;
			}

			var $btn = $(this);
			var requestId = $btn.data('id');

			$.post(omniprivacyAdmin.ajaxUrl, {
				action: 'omniprivacy_approve_deletion',
				nonce: omniprivacyAdmin.nonce,
				request_id: requestId
			}, function (response) {
				if (response.success) {
					$btn.closest('tr').find('.omniprivacy-status-badge')
						.removeClass('pending').addClass('approved').text('Approved');
					$btn.closest('td').html('&mdash;');
				} else {
					alert(response.data.message);
				}
			});
		});

		// Rejet de demande de suppression.
		$(document).on('click', '.omniprivacy-reject-request', function (e) {
			e.preventDefault();
			var note = prompt(omniprivacyAdmin.i18n.rejectNote || 'Motif du rejet (obligatoire) :');
			if (!note) {
				return;
			}

			var $btn = $(this);
			var requestId = $btn.data('id');

			$.post(omniprivacyAdmin.ajaxUrl, {
				action: 'omniprivacy_reject_deletion',
				nonce: omniprivacyAdmin.nonce,
				request_id: requestId,
				note: note
			}, function (response) {
				if (response.success) {
					$btn.closest('tr').find('.omniprivacy-status-badge')
						.removeClass('pending').addClass('rejected').text('Rejected');
					$btn.closest('td').html('&mdash;');
				} else {
					alert(response.data.message);
				}
			});
		});
	});

	/**
	 * Polling de progression du scan.
	 */
	function startProgressPolling(scanId, $btn, originalLabel) {
		scanPolling = setInterval(function () {
			$.post(omniprivacyAdmin.ajaxUrl, {
				action: 'omniprivacy_scan_progress',
				nonce: omniprivacyAdmin.nonce,
				scan_id: scanId
			}, function (response) {
				if (!response.success) {
					return;
				}

				var data = response.data;
				var percent = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;
				if (percent > 100) percent = 100;

				$('.omniprivacy-scan-progress-bar').css('width', percent + '%');
				$('#omniprivacy-scan-status').text(
					data.step + ' — ' + percent + '% (' + data.found + ' PII)'
				);

				if (data.status === 'complete') {
					clearInterval(scanPolling);
					$('#omniprivacy-scan-status').text(
						omniprivacyAdmin.i18n.scanComplete + ' ' + data.found + ' PII.'
					);
					$btn.prop('disabled', false).text(originalLabel);
					setTimeout(function () { window.location.reload(); }, 2000);
				}
			});
		}, 3000);
	}
})(jQuery);
