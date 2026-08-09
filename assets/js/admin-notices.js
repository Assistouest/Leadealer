(function () {
	'use strict';

	var notice = document.getElementById('leadealer-delivery-notice');
	var config = window.LeadealerNotices || {};

	if (!notice || !config.ajaxUrl || !config.nonce) {
		return;
	}

	notice.addEventListener('click', function (event) {
		if (!event.target.closest('.notice-dismiss')) {
			return;
		}

		var deadId = parseInt(notice.getAttribute('data-dead-id'), 10) || 0;
		var data = new URLSearchParams();
		data.append('action', 'leadealer_dismiss_delivery_notice');
		data.append('_ajax_nonce', config.nonce);
		data.append('dead_id', String(deadId));

		window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: data.toString()
		}).catch(function () {
			// Dismissal is a convenience only; delivery state remains in Lead Vault.
		});
	});
}());
