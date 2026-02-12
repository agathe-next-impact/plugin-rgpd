/**
 * OmniPrivacy Pro — Front-end Portal JS
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		// Demande de magic link.
		$('#omniprivacy-magic-link-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var $msg = $('#omniprivacy-message');
			var email = $form.find('#omniprivacy-email').val();

			$btn.prop('disabled', true).text(omniprivacyPortal.i18n.sending);

			$.post(omniprivacyPortal.ajaxUrl, {
				action: 'omniprivacy_request_magic_link',
				nonce: omniprivacyPortal.nonce,
				email: email
			}, function (response) {
				$msg.show();
				if (response.success) {
					$msg.removeClass('error').addClass('success').text(response.data.message);
				} else {
					$msg.removeClass('success').addClass('error').text(response.data.message);
				}
				$btn.prop('disabled', false).text($btn.data('label') || omniprivacyPortal.i18n.sending.replace('...', ''));
			}).fail(function () {
				$msg.show().removeClass('success').addClass('error').text(omniprivacyPortal.i18n.error);
				$btn.prop('disabled', false);
			});
		});

		// Soumission de demande de suppression.
		$('#omniprivacy-deletion-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var items = [];

			$form.find('input[name="items[]"]:checked').each(function () {
				items.push($(this).val());
			});

			if (items.length === 0) {
				alert(omniprivacyPortal.i18n.error);
				return;
			}

			$btn.prop('disabled', true);

			$.post(omniprivacyPortal.ajaxUrl, {
				action: 'omniprivacy_submit_deletion',
				nonce: omniprivacyPortal.nonce,
				email: $form.find('input[name="email"]').val(),
				items: items
			}, function (response) {
				if (response.success) {
					$form.html('<p class="success">' + omniprivacyPortal.i18n.requestSent + '</p>');
				} else {
					alert(response.data.message);
					$btn.prop('disabled', false);
				}
			}).fail(function () {
				alert(omniprivacyPortal.i18n.error);
				$btn.prop('disabled', false);
			});
		});
	});
})(jQuery);
