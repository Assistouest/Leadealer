(function () {
	'use strict';

	var strings = window.Leadealer || {};

	function text(key, fallback) {
		return strings[key] || fallback;
	}

	function rotr(value, amount) {
		return (value >>> amount) | (value << (32 - amount));
	}

	function sha256Words(ascii) {
		var maxWord = Math.pow(2, 32);
		var words = [];
		var asciiBitLength = ascii.length * 8;
		var initialHash = [
			0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
			0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19
		];
		var k = [
			0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
			0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
			0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
			0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
			0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
			0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
			0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
			0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
		];
		var hash = initialHash.slice(0);
		var i;
		var offset;

		ascii += '\x80';
		while (ascii.length % 64 !== 56) {
			ascii += '\x00';
		}

		for (i = 0; i < ascii.length; i++) {
			var code = ascii.charCodeAt(i);
			if (code > 255) {
				throw new Error('Proof input must be ASCII.');
			}
			words[i >> 2] = (words[i >> 2] || 0) | (code << (((3 - i) % 4) * 8));
		}

		words.push(Math.floor(asciiBitLength / maxWord));
		words.push(asciiBitLength >>> 0);

		for (offset = 0; offset < words.length; offset += 16) {
			var w = new Array(64);
			var working = hash.slice(0);
			for (i = 0; i < 16; i++) {
				w[i] = words[offset + i] | 0;
			}
			for (i = 16; i < 64; i++) {
				var w15 = w[i - 15];
				var w2 = w[i - 2];
				var s0 = rotr(w15, 7) ^ rotr(w15, 18) ^ (w15 >>> 3);
				var s1 = rotr(w2, 17) ^ rotr(w2, 19) ^ (w2 >>> 10);
				w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
			}

			for (i = 0; i < 64; i++) {
				var a = working[0];
				var b = working[1];
				var c = working[2];
				var d = working[3];
				var e = working[4];
				var f = working[5];
				var g = working[6];
				var h = working[7];
				var sigma1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
				var choice = (e & f) ^ ((~e) & g);
				var temp1 = (h + sigma1 + choice + k[i] + w[i]) | 0;
				var sigma0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
				var majority = (a & b) ^ (a & c) ^ (b & c);
				var temp2 = (sigma0 + majority) | 0;
				working = [
					(temp1 + temp2) | 0, a, b, c,
					(d + temp1) | 0, e, f, g
				];
			}

			for (i = 0; i < 8; i++) {
				hash[i] = (hash[i] + working[i]) | 0;
			}
		}

		return hash;
	}

	function meetsDifficulty(words, bits) {
		var unsigned = words[0] >>> 0;
		if (bits <= 0) {
			return true;
		}
		if (bits <= 32) {
			return (unsigned >>> (32 - bits)) === 0;
		}
		return false;
	}

	function solve(token, difficulty) {
		return new Promise(function (resolve, reject) {
			var nonce = 0;
			var maxNonce = 25000000;

			function chunk() {
				var end = Math.min(nonce + 1500, maxNonce);
				for (; nonce < end; nonce++) {
					if (meetsDifficulty(sha256Words(token + ':' + nonce), difficulty)) {
						resolve(nonce);
						return;
					}
				}
				if (nonce >= maxNonce) {
					reject(new Error(text('proofError', 'Proof of Work could not be completed.')));
					return;
				}
				window.setTimeout(chunk, 0);
			}

			chunk();
		});
	}

	function uuidV4() {
		if (window.crypto && window.crypto.randomUUID) {
			return window.crypto.randomUUID();
		}
		var bytes = new Uint8Array(16);
		if (window.crypto && window.crypto.getRandomValues) {
			window.crypto.getRandomValues(bytes);
		} else {
			for (var i = 0; i < bytes.length; i++) {
				bytes[i] = Math.floor(Math.random() * 256);
			}
		}
		bytes[6] = (bytes[6] & 0x0f) | 0x40;
		bytes[8] = (bytes[8] & 0x3f) | 0x80;
		var hex = Array.prototype.map.call(bytes, function (byte) {
			return byte.toString(16).padStart(2, '0');
		}).join('');
		return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
	}



	function randomBase64Url(byteLength) {
		var bytes = new Uint8Array(byteLength);
		var binary = '';
		var i;

		if (!window.crypto || !window.crypto.getRandomValues) {
			return '';
		}
		window.crypto.getRandomValues(bytes);

		for (i = 0; i < bytes.length; i++) {
			binary += String.fromCharCode(bytes[i]);
		}

		return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
	}

	function normalizePhoneNumber(rawValue, countryCode) {
		var raw = String(rawValue || '').trim();
		var code = String(countryCode || '+33').replace(/[^+0-9]/g, '');
		var digits;
		var countryDigits;
		var international;

		if (!raw) {
			return '';
		}
		if (!/^\+?[^+]*$/.test(raw)) {
			return null;
		}

		digits = raw.replace(/\D/g, '');
		if (!digits) {
			return null;
		}

		if (raw.charAt(0) === '+') {
			international = '+' + digits;
		} else if (digits.slice(0, 2) === '00') {
			international = '+' + digits.slice(2);
		} else {
			countryDigits = code.replace(/\D/g, '') || '33';

			if (code === '+33') {
				if (digits.slice(0, 2) === '33' && digits.length === 11) {
					digits = digits.slice(2);
				}
				if (digits.charAt(0) === '0') {
					digits = digits.slice(1);
				}
				if (!/^[1-9][0-9]{8}$/.test(digits)) {
					return null;
				}
			} else {
				if (digits.slice(0, countryDigits.length) === countryDigits && digits.length > countryDigits.length + 6) {
					digits = digits.slice(countryDigits.length);
				}
				if (code !== '+39' && digits.charAt(0) === '0') {
					digits = digits.slice(1);
				}
			}

			international = '+' + countryDigits + digits;
		}

		if (international.indexOf('+33') === 0) {
			var frenchNational = international.slice(3);
			if (frenchNational.charAt(0) === '0') {
				frenchNational = frenchNational.slice(1);
			}
			if (!/^[1-9][0-9]{8}$/.test(frenchNational)) {
				return null;
			}
			international = '+33' + frenchNational;
		}

		if (!/^\+[1-9][0-9]{6,14}$/.test(international)) {
			return null;
		}
		return international;
	}

	function updatePhoneCountryFlag(select) {
		var wrapper = select.closest('.leadealer-form__phone-country-wrap');
		var flag = wrapper ? wrapper.querySelector('.leadealer-form__phone-country-flag') : null;
		var option = select.options[select.selectedIndex];

		if (flag) {
			flag.textContent = option ? (option.getAttribute('data-flag') || '🌐') : '🌐';
		}
	}

	function normalizePhoneField(input, showError) {
		var wrapper = input.closest('.leadealer-form__phone');
		var country = wrapper ? wrapper.querySelector('.leadealer-form__phone-country') : null;
		var normalized = normalizePhoneNumber(input.value, country ? country.value : '+33');

		input.setCustomValidity('');
		if (normalized === '') {
			return true;
		}
		if (normalized === null) {
			if (showError) {
				input.setCustomValidity(text('invalidPhone', 'Please enter a valid phone number.'));
			}
			return false;
		}

		input.value = normalized;
		return true;
	}

	function normalizePhoneFields(form, showError) {
		var valid = true;
		form.querySelectorAll('.leadealer-form__phone-number').forEach(function (input) {
			if (!normalizePhoneField(input, showError)) {
				valid = false;
			}
		});
		return valid;
	}

	function conditionMatches(actual, rule) {
		actual = String(actual == null ? '' : actual).trim();
		var expected = String(rule.value == null ? '' : rule.value);

		switch (rule.operator) {
			case 'not_equals':
				return actual !== expected;
			case 'is_empty':
				return actual === '';
			case 'is_not_empty':
				return actual !== '';
			case 'contains':
				return expected !== '' && actual.toLowerCase().indexOf(expected.toLowerCase()) !== -1;
			case 'not_contains':
				return expected === '' || actual.toLowerCase().indexOf(expected.toLowerCase()) === -1;
			case 'equals':
			default:
				return actual === expected;
		}
	}

	function sourceFieldValue(form, fieldId) {
		var wrapper = form.querySelector('[data-leadealer-field-id="' + fieldId + '"]');
		var control;

		if (wrapper && wrapper.hidden) {
			return '';
		}

		control = form.elements.namedItem(fieldId);
		if (!control) {
			return '';
		}

		if (control.tagName && control.tagName.toLowerCase() === 'input' && control.type === 'checkbox') {
			return control.checked ? control.value : '';
		}

		if (typeof control.value !== 'undefined') {
			return control.value || '';
		}

		return '';
	}

	function setConditionalVisibility(wrapper, visible) {
		var changed = wrapper.hidden === visible;
		wrapper.hidden = !visible;
		wrapper.setAttribute('aria-hidden', visible ? 'false' : 'true');

		wrapper.querySelectorAll('input, select, textarea, button').forEach(function (control) {
			control.disabled = !visible;
		});

		return changed;
	}

	function applyConditionalLogic(form) {
		var conditionalFields = Array.prototype.slice.call(form.querySelectorAll('[data-leadealer-conditional]'));
		var maxPasses = conditionalFields.length + 2;
		var pass;

		for (pass = 0; pass < maxPasses; pass++) {
			var changed = false;

			conditionalFields.forEach(function (wrapper) {
				var config;
				var results;
				var visible;

				try {
					config = JSON.parse(wrapper.getAttribute('data-leadealer-conditional') || '{}');
				} catch (error) {
					config = {};
				}

				if (!config.enabled || !Array.isArray(config.rules) || !config.rules.length) {
					visible = true;
				} else {
					results = config.rules.map(function (rule) {
						return conditionMatches(sourceFieldValue(form, rule.field), rule);
					});
					visible = config.match === 'any' ? results.some(Boolean) : results.every(Boolean);
				}

				if (setConditionalVisibility(wrapper, visible)) {
					changed = true;
				}
			});

			if (!changed) {
				break;
			}
		}
	}

	function isHeicFile(file) {
		var type = String(file.type || '').toLowerCase();
		var name = String(file.name || '').toLowerCase();
		return type === 'image/heic' || type === 'image/heif' || (/\.(heic|heif)$/).test(name);
	}

	function uploadFilenameForBlob(blob) {
		switch (String(blob && blob.type || '').toLowerCase()) {
			case 'image/png':
				return 'photo.png';
			case 'image/webp':
				return 'photo.webp';
			case 'image/jpeg':
			default:
				return 'photo.jpg';
		}
	}

	/*
	 * Convert HEIC only through the browser's native image decoder. Leadealer
	 * deliberately does not ship a libheif/WASM decoder: stale browser builds
	 * of libheif have accumulated multiple memory-safety CVEs, while the server
	 * must never parse HEIC at all. Safari/iOS 17+ can decode HEIC natively;
	 * other browsers fail closed and ask the visitor for JPEG instead.
	 */
	function nativeHeicToJpeg(file, quality) {
		function canvasToJpeg(source, width, height, cleanup) {
			var longest = Math.max(width, height);
			var scale = longest > 2000 ? 2000 / longest : 1;
			var canvas = document.createElement('canvas');
			canvas.width = Math.max(1, Math.round(width * scale));
			canvas.height = Math.max(1, Math.round(height * scale));
			var ctx = canvas.getContext('2d');
			if (!ctx) {
				if (cleanup) { cleanup(); }
				return Promise.reject(new Error('decode'));
			}

			try {
				ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
			} catch (error) {
				if (cleanup) { cleanup(); }
				return Promise.reject(error);
			}
			if (cleanup) { cleanup(); }

			return new Promise(function (resolve, reject) {
				canvas.toBlob(function (blob) {
					if (!blob || blob.type !== 'image/jpeg' || blob.size <= 0) {
						reject(new Error('encode'));
						return;
					}
					resolve(blob);
				}, 'image/jpeg', quality);
			});
		}

		function viaImageElement() {
			return new Promise(function (resolve, reject) {
				var objectUrl = URL.createObjectURL(file);
				var image = new Image();
				var settled = false;
				var timer = window.setTimeout(function () {
					if (settled) { return; }
					settled = true;
					URL.revokeObjectURL(objectUrl);
					reject(new Error('timeout'));
				}, 15000);

				image.onload = function () {
					if (settled) { return; }
					settled = true;
					window.clearTimeout(timer);
					if (!image.naturalWidth || !image.naturalHeight || image.naturalWidth > 12000 || image.naturalHeight > 12000 || image.naturalWidth * image.naturalHeight > 25000000) {
						URL.revokeObjectURL(objectUrl);
						reject(new Error('dimensions'));
						return;
					}
					canvasToJpeg(image, image.naturalWidth, image.naturalHeight, function () { URL.revokeObjectURL(objectUrl); }).then(resolve, reject);
				};
				image.onerror = function () {
					if (settled) { return; }
					settled = true;
					window.clearTimeout(timer);
					URL.revokeObjectURL(objectUrl);
					reject(new Error('decode'));
				};
				image.src = objectUrl;
			});
		}

		if (typeof window.createImageBitmap !== 'function') {
			return viaImageElement();
		}

		return window.createImageBitmap(file).then(function (bitmap) {
			if (!bitmap.width || !bitmap.height || bitmap.width > 12000 || bitmap.height > 12000 || bitmap.width * bitmap.height > 25000000) {
				if (bitmap.close) { bitmap.close(); }
				throw new Error('dimensions');
			}
			return canvasToJpeg(bitmap, bitmap.width, bitmap.height, function () {
				if (bitmap.close) { bitmap.close(); }
			});
		}).catch(function () {
			return viaImageElement();
		});
	}

	// UX-only: shrinks the picked photo before it leaves the browser so mobile
	// uploads are faster. Never trusted as the security boundary — the server
	// re-decodes and re-encodes every upload from scratch regardless.
	function resizeImageBlob(blob, maxDimension, quality) {
		if (typeof window.createImageBitmap !== 'function') {
			return Promise.resolve(blob);
		}

		return window.createImageBitmap(blob).then(function (bitmap) {
			var longest = Math.max(bitmap.width, bitmap.height);
			if (longest <= maxDimension) {
				if (bitmap.close) {
					bitmap.close();
				}
				return blob;
			}

			var scale = maxDimension / longest;
			var canvas = document.createElement('canvas');
			canvas.width = Math.round(bitmap.width * scale);
			canvas.height = Math.round(bitmap.height * scale);
			var ctx = canvas.getContext('2d');
			if (!ctx) {
				if (bitmap.close) {
					bitmap.close();
				}
				return blob;
			}
			ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
			if (bitmap.close) {
				bitmap.close();
			}

			return new Promise(function (resolve) {
				canvas.toBlob(function (resized) {
					resolve(resized || blob);
				}, 'image/jpeg', quality);
			});
		}).catch(function () {
			return blob;
		});
	}

	function initUploadField(wrapper, trackUpload) {
		var fileInput   = wrapper.querySelector('.leadealer-form__upload-input');
		var tokenInput  = wrapper.querySelector('.leadealer-form__upload-token');
		var removeBtn   = wrapper.querySelector('.leadealer-form__upload-remove');
		var statusEl    = wrapper.querySelector('.leadealer-form__upload-status');
		var fieldId     = wrapper.getAttribute('data-leadealer-upload-field-id') || '';
		var uploadData  = window.LeadealerUpload || {};
		var uploadStrings = uploadData.strings || {};
		var maxUploadBytes = uploadData.maxUploadBytes || (10 * 1024 * 1024);
		var previewImg  = null;
		var previewUrl  = '';
		var requestId   = 0;

		/*
		 * Since 0.7.3 the selected photo is not pre-uploaded in a separate HTTP
		 * request. The prepared blob stays only in browser memory and is submitted
		 * atomically with the lead. This removes the fragile token hand-off between
		 * /upload and /submit while the legacy /upload route remains available for
		 * older cached front-end scripts.
		 */
		wrapper._leadealerPreparedBlob = null;
		wrapper._leadealerPreparedFilename = '';

		function setUploadState(state) {
			wrapper.setAttribute('data-leadealer-upload-state', state);
		}

		if (!fileInput || !tokenInput || !fieldId) {
			return;
		}

		setUploadState('idle');

		function upload(key, fallback) {
			return uploadStrings[key] || fallback;
		}

		function setStatus(message, isError) {
			if (statusEl) {
				statusEl.textContent = message || '';
				statusEl.classList.toggle('is-error', !!isError);
			}
		}

		function clearPreview() {
			if (previewImg) {
				previewImg.remove();
				previewImg = null;
			}
			if (previewUrl) {
				URL.revokeObjectURL(previewUrl);
				previewUrl = '';
			}
		}

		function reset() {
			tokenInput.value = '';
			wrapper._leadealerPreparedBlob = null;
			wrapper._leadealerPreparedFilename = '';
			clearPreview();
			if (removeBtn) {
				removeBtn.hidden = true;
			}
		}

		fileInput.addEventListener('click', function () {
			if (wrapper.getAttribute('data-leadealer-upload-state') === 'failed') {
				fileInput.value = '';
			}
		});

		if (removeBtn) {
			removeBtn.addEventListener('click', function () {
				requestId += 1;
				fileInput.value = '';
				setStatus('', false);
				reset();
				setUploadState('idle');
			});
		}

		if (fileInput.form) {
			fileInput.form.addEventListener('reset', function () {
				window.setTimeout(function () {
					requestId += 1;
					setStatus('', false);
					reset();
					setUploadState('idle');
				}, 0);
			});
		}

		fileInput.addEventListener('change', function () {
			var file = fileInput.files && fileInput.files[0];

			if (!file) {
				/* A cancelled picker must not discard a photo already prepared. */
				return;
			}

			var currentRequest = ++requestId;

			reset();
			setUploadState('preparing');
			setStatus(upload('preparing', 'Preparing photo…'), false);

			if (file.size > maxUploadBytes * 3) {
				setStatus(upload('uploadTooLarge', 'The photo is too large.'), true);
				fileInput.value = '';
				setUploadState('failed');
				if (removeBtn) {
					removeBtn.hidden = false;
				}
				return;
			}

			var prepared;
			if (isHeicFile(file)) {
				setStatus(upload('converting', 'Converting photo…'), false);
				prepared = nativeHeicToJpeg(file, 0.85).catch(function () {
					throw new Error(upload('conversionError', 'This photo could not be converted. Please try a different photo or export it as JPEG.'));
				});
			} else {
				prepared = Promise.resolve(file);
			}

			var chain = prepared.then(function (blob) {
				return resizeImageBlob(blob, 2000, 0.85);
			}).then(function (blob) {
				if (currentRequest !== requestId) {
					return null;
				}
				if (!blob || !blob.size || blob.size > maxUploadBytes) {
					throw new Error(upload('uploadTooLarge', 'The photo is too large.'));
				}

				wrapper._leadealerPreparedBlob = blob;
				wrapper._leadealerPreparedFilename = uploadFilenameForBlob(blob);

				previewImg = document.createElement('img');
				previewImg.className = 'leadealer-form__upload-preview';
				previewImg.alt = '';
				previewUrl = URL.createObjectURL(blob);
				previewImg.src = previewUrl;
				wrapper.insertBefore(previewImg, statusEl);

				setUploadState('ready');
				setStatus(upload('ready', 'Photo ready to send.'), false);
				if (removeBtn) {
					removeBtn.hidden = false;
				}
				return blob;
			}).catch(function (error) {
				if (currentRequest !== requestId) {
					return;
				}
				reset();
				setUploadState('failed');
				if (removeBtn) {
					removeBtn.hidden = false;
				}
				setStatus(error.message || upload('uploadError', 'The photo could not be prepared. Please try again.'), true);
			});

			if (typeof trackUpload === 'function') {
				trackUpload(chain);
			}
		});
	}

	function initForm(form) {
		var formId = parseInt(form.getAttribute('data-form-id'), 10);
		var challengeUrl = form.getAttribute('data-challenge-url');
		var submitUrl = form.getAttribute('data-submit-url');
		var honeypotName = form.getAttribute('data-honeypot-name');
		var status = form.querySelector('.leadealer-form__status');
		var submitButton = form.querySelector('.leadealer-form__submit');
		var challengePromise = null;
		var proof = null;
		var submissionId = uuidV4();
		var idempotencyKey = randomBase64Url(32);
		var pendingUploads = [];

		function trackUpload(promise) {
			pendingUploads.push(promise);

			var forget = function () {
				var index = pendingUploads.indexOf(promise);
				if (index !== -1) {
					pendingUploads.splice(index, 1);
				}
			};

			promise.then(forget, forget);
		}

		function assertUploadsReady() {
			var uploadStrings = (window.LeadealerUpload && window.LeadealerUpload.strings) || {};
			var errorMessage = uploadStrings.uploadError || 'The photo could not be prepared. Please try again.';

			form.querySelectorAll('[data-leadealer-upload-field]').forEach(function (wrapper) {
				if (wrapper.hidden || wrapper.closest('[hidden]')) {
					return;
				}

				var input = wrapper.querySelector('.leadealer-form__upload-input');
				var token = wrapper.querySelector('.leadealer-form__upload-token');
				var state = wrapper.getAttribute('data-leadealer-upload-state') || 'idle';
				var hasSelectedFile = !!(input && input.files && input.files.length);
				var hasPreparedFile = !!wrapper._leadealerPreparedBlob;
				var hasLegacyToken = !!(token && token.value);

				if (state === 'failed' || state === 'preparing' || state === 'uploading') {
					throw new Error(errorMessage);
				}

				/*
				 * Never allow a browser-selected file to disappear from a submission.
				 * 0.7.3 expects an in-memory prepared blob; a legacy cached script may
				 * instead have produced a signed hidden token. At least one must exist.
				 */
				if (hasSelectedFile && !hasPreparedFile && !hasLegacyToken) {
					throw new Error(errorMessage);
				}

				if (state === 'ready' && !hasPreparedFile && !hasLegacyToken) {
					throw new Error(errorMessage);
				}
			});
		}

		function whenUploadsSettled() {
			var waiting = pendingUploads.length ? Promise.all(pendingUploads.map(function (promise) {
				return promise.catch(function () {});
			})) : Promise.resolve();

			return waiting.then(function () {
				assertUploadsReady();
			});
		}

		submitButton.disabled = false;
		applyConditionalLogic(form);
		form.addEventListener('input', function () { applyConditionalLogic(form); });
		form.addEventListener('change', function () { applyConditionalLogic(form); });
		form.addEventListener('reset', function () {
			window.setTimeout(function () {
				applyConditionalLogic(form);
				form.querySelectorAll('.leadealer-form__phone-country').forEach(updatePhoneCountryFlag);
			}, 0);
		});

		form.querySelectorAll('.leadealer-form__phone-number').forEach(function (input) {
			input.addEventListener('blur', function () {
				normalizePhoneField(input, true);
			});
			input.addEventListener('input', function () {
				input.setCustomValidity('');
			});
		});

		form.querySelectorAll('[data-leadealer-upload-field]').forEach(function (wrapper) {
			initUploadField(wrapper, trackUpload);
		});

		form.querySelectorAll('.leadealer-form__phone-country').forEach(function (select) {
			select.setAttribute('data-previous-country-code', select.value);
			updatePhoneCountryFlag(select);
			select.addEventListener('change', function () {
				var wrapper = select.closest('.leadealer-form__phone');
				var input = wrapper ? wrapper.querySelector('.leadealer-form__phone-number') : null;
				var previousCode = select.getAttribute('data-previous-country-code') || '+33';

				if (input && input.value.indexOf(previousCode) === 0) {
					input.value = input.value.slice(previousCode.length);
				}
				select.setAttribute('data-previous-country-code', select.value);
				updatePhoneCountryFlag(select);

				if (input && input.value) {
					normalizePhoneField(input, true);
				}
			});
		});

		function setStatus(message, isError) {
			status.textContent = message || '';
			status.classList.toggle('is-error', !!isError);
		}

		function prepareProof() {
			if (proof) {
				return Promise.resolve(proof);
			}
			if (challengePromise) {
				return challengePromise;
			}

			var separator = challengeUrl.indexOf('?') === -1 ? '?' : '&';
			var dynamicChallengeUrl = challengeUrl + separator + '_leadealer=' + encodeURIComponent(uuidV4());

			challengePromise = window.fetch(dynamicChallengeUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Cache-Control': 'no-store',
					'Pragma': 'no-cache'
				},
				credentials: 'same-origin',
				cache: 'no-store',
				body: JSON.stringify({ form_id: formId })
			}).then(function (response) {
				if (!response.ok) {
					throw new Error(text('challengeError', 'Unable to start anti-spam verification.'));
				}
				return response.json();
			}).then(function (challenge) {
				return solve(challenge.token, challenge.difficulty).then(function (nonce) {
					proof = { token: challenge.token, nonce: nonce };
					return proof;
				});
			}).catch(function (error) {
				challengePromise = null;
				throw error;
			});

			return challengePromise;
		}

		function triggerPreparation() {
			prepareProof().catch(function () {
				// A visible error is shown only if the visitor actually submits.
			});
		}

		form.addEventListener('focusin', triggerPreparation, { once: true });
		form.addEventListener('pointerdown', triggerPreparation, { once: true });

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!idempotencyKey) {
				setStatus(text('secureBrowserRequired', 'Your browser cannot create a secure submission token. Please update it and try again.'), true);
				return;
			}
			applyConditionalLogic(form);
			normalizePhoneFields(form, true);
			if (!form.reportValidity()) {
				return;
			}

			submitButton.disabled = true;
			setStatus(text(pendingUploads.length ? 'waitingForUpload' : 'verification', pendingUploads.length ? 'Finishing photo preparation…' : 'Verification…'), false);

			/*
			 * Wait for image conversion/resizing before snapshotting the fields. The
			 * actual photo is sent in the same multipart request as the lead, so there
			 * is no second-request token hand-off that can silently lose the file.
			 */
			whenUploadsSettled().then(function () {
				setStatus(text('verification', 'Verification…'), false);
				return prepareProof();
			}).then(function (currentProof) {
				var data = new FormData(form);
				var fields = {};
				var honeypot = {};
				var directFiles = [];

				data.forEach(function (value, key) {
					if (key === honeypotName) {
						honeypot[key] = String(value);
					} else {
						fields[key] = String(value);
					}
				});

				form.querySelectorAll('[data-leadealer-upload-field]').forEach(function (wrapper) {
					if (wrapper.hidden || wrapper.closest('[hidden]')) {
						return;
					}
					var fieldId = wrapper.getAttribute('data-leadealer-upload-field-id') || '';
					var blob = wrapper._leadealerPreparedBlob;
					if (!fieldId || !blob) {
						return;
					}
					directFiles.push({
						fieldId: fieldId,
						blob: blob,
						filename: wrapper._leadealerPreparedFilename || uploadFilenameForBlob(blob)
					});
					/* Direct multipart input supersedes any stale legacy token. */
					fields[fieldId] = '';
				});

				var payload = {
					submission_id: submissionId,
					idempotency_key: idempotencyKey,
					pow_token: currentProof.token,
					pow_nonce: currentProof.nonce,
					honeypot: honeypot,
					fields: fields,
					file_fields: directFiles.map(function (item) { return item.fieldId; })
				};

				if (!directFiles.length) {
					return window.fetch(submitUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						credentials: 'same-origin',
						cache: 'no-store',
						body: JSON.stringify(payload)
					});
				}

				var multipart = new FormData();
				multipart.append('payload', JSON.stringify(payload));
				directFiles.forEach(function (item) {
					multipart.append('leadealer_file_' + item.fieldId, item.blob, item.filename);
				});

				return window.fetch(submitUrl, {
					method: 'POST',
					credentials: 'same-origin',
					cache: 'no-store',
					body: multipart
				});
			}).then(function (response) {
				return response.json().catch(function () { return {}; }).then(function (body) {
					if (!response.ok) {
						throw new Error(body.message || text('sendError', 'Your request could not be submitted.'));
					}
					return body;
				});
			}).then(function (body) {
				setStatus(body.message || text('defaultSuccess', 'Your request has been received.'), false);
				form.reset();
				form.querySelectorAll('.leadealer-form__phone-country').forEach(function (select) {
					select.setAttribute('data-previous-country-code', select.value);
					updatePhoneCountryFlag(select);
				});
				form.querySelectorAll('.leadealer-form__phone-number').forEach(function (input) {
					input.setCustomValidity('');
				});
				proof = null;
				challengePromise = null;
				submissionId = uuidV4();
				idempotencyKey = randomBase64Url(32);
			}).catch(function (error) {
				setStatus(error.message || text('sendError', 'Your request could not be submitted.'), true);
				proof = null;
				challengePromise = null;
			}).finally(function () {
				submitButton.disabled = false;
			});
		});
	}

	document.querySelectorAll('.leadealer-form').forEach(initForm);
}());
