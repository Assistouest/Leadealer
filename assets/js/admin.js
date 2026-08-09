(function () {
	'use strict';

	var builder = document.querySelector('.leadealer-builder');
	var strings = window.LeadealerAdmin || {};
	var labels = strings.labels || {};
	var templates = strings.templates || {};

	if (!builder) {
		return;
	}

	var canvas = builder.querySelector('.leadealer-builder__canvas');
	var viewport = builder.querySelector('.leadealer-builder__viewport');
	var stage = builder.querySelector('.leadealer-builder__stage');
	var schemaField = builder.querySelector('.leadealer-builder__schema');
	var inspector = builder.querySelector('.leadealer-builder__field-settings');
	var formSettings = builder.querySelector('.leadealer-builder__form-settings');
	var undoButton = builder.querySelector('[data-builder-action="undo"]');
	var redoButton = builder.querySelector('[data-builder-action="redo"]');
	var schema = [];
	var selectedId = '';
	var device = 'desktop';
	var history = [];
	var future = [];
	var dragState = null;
	var suppressPaletteClickUntil = 0;
	var editingSnapshot = null;

	try {
		schema = JSON.parse(builder.getAttribute('data-schema') || '[]');
		if (!Array.isArray(schema)) {
			schema = [];
		}
	} catch (error) {
		schema = [];
	}

	function clone(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function escapeHtml(value) {
		return (value == null ? '' : String(value)).replace(/[&<>"']/g, function (character) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character];
		});
	}

	function fieldId() {
		return 'field_' + Math.random().toString(36).slice(2, 10);
	}

	function slugify(value) {
		var text = String(value || '').trim().toLowerCase();
		if (text.normalize) {
			text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		}
		text = text.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
		return text;
	}

	function uniqueOptionValue(value, used, fallbackIndex) {
		var base = slugify(value) || ('option-' + (fallbackIndex + 1));
		var candidate = base;
		var suffix = 2;
		while (used[candidate]) {
			candidate = base + '-' + suffix;
			suffix += 1;
		}
		used[candidate] = true;
		return candidate;
	}

	function normalizeOptions(options) {
		var used = {};
		if (!Array.isArray(options)) {
			return [];
		}

		return options.map(function (option, index) {
			var label = typeof option === 'object' && option !== null ? String(option.label || '') : String(option || '');
			var value = typeof option === 'object' && option !== null ? String(option.value || '') : '';
			label = label.trim();
			if (!label) {
				return null;
			}
			return {
				label: label,
				value: uniqueOptionValue(value || label, used, index)
			};
		}).filter(Boolean);
	}

	function defaultLabel(type) {
		return labels[type] || labels.field || 'Field';
	}

	function fieldById(id) {
		return schema.find(function (field) { return field.id === id; }) || null;
	}

	function fieldIndex(id) {
		return schema.findIndex(function (field) { return field.id === id; });
	}

	function ensureConditional(field) {
		if (!field.conditional || typeof field.conditional !== 'object') {
			field.conditional = {};
		}
		field.conditional.enabled = !!field.conditional.enabled;
		field.conditional.match = field.conditional.match === 'any' ? 'any' : 'all';
		field.conditional.rules = Array.isArray(field.conditional.rules) ? field.conditional.rules : [];
		field.conditional.rules = field.conditional.rules.map(function (rule) {
			return {
				field: String(rule.field || ''),
				operator: String(rule.operator || 'equals'),
				value: String(rule.value == null ? '' : rule.value)
			};
		});
		return field;
	}

	function ensureField(field) {
		field.description = field.description || '';
		field.appearance = field.type === 'textarea' && field.appearance === 'message' ? 'message' : 'default';
		field.placeholder = field.placeholder || '';
		field.default = field.default || '';
		field.autocomplete = field.autocomplete || '';
		field.options = normalizeOptions(field.options);
		field.layout = field.layout || {};
		field.layout.desktop = field.layout.desktop || 12;
		field.layout.tablet = field.layout.tablet || field.layout.desktop || 12;
		field.layout.mobile = field.layout.mobile || 12;
		if (field.type === 'select' || field.type === 'radio') {
			var defaultOption = field.options.find(function (option) {
				return option.value === field.default || option.label === field.default;
			});
			field.default = defaultOption ? defaultOption.value : '';
		}
		return ensureConditional(field);
	}

	schema = schema.map(ensureField);

	function sync() {
		schemaField.value = JSON.stringify(schema);
		undoButton.disabled = history.length === 0;
		redoButton.disabled = future.length === 0;
	}

	function pushHistory(snapshot) {
		history.push(snapshot || clone(schema));
		if (history.length > 50) {
			history.shift();
		}
		future = [];
	}

	function remember() {
		pushHistory(clone(schema));
	}

	function iconFor(type) {
		var map = {
			text: 'editor-textcolor',
			email: 'email',
			tel: 'phone',
			textarea: 'editor-alignleft',
			select: 'arrow-down-alt2',
			radio: 'marker',
			checkbox: 'yes-alt',
			consent: 'privacy',
			number: 'editor-ol',
			url: 'admin-links',
			file: 'camera'
		};
		return map[type] || 'forms';
	}

	function optionLabels(field) {
		return field.options.map(function (option) { return option.label; });
	}

	function previewControl(field) {
		var placeholder = escapeHtml(field.placeholder || '');
		if (field.type === 'textarea') {
			var messageClass = field.appearance === 'message' ? ' leadealer-builder__mock-message' : '';
			return '<div class="leadealer-builder__mock-input leadealer-builder__mock-textarea' + messageClass + '">' + (placeholder || '&nbsp;') + '</div>';
		}
		if (field.type === 'select') {
			return '<div class="leadealer-builder__mock-input">' + escapeHtml((field.options[0] && field.options[0].label) || strings.choose || 'Choose…') + '<span>⌄</span></div>';
		}
		if (field.type === 'radio') {
			return '<div class="leadealer-builder__mock-choices"><span>◯ ' + escapeHtml((field.options[0] && field.options[0].label) || strings.defaultOptionOne || 'Option 1') + '</span><span>◯ ' + escapeHtml((field.options[1] && field.options[1].label) || strings.defaultOptionTwo || 'Option 2') + '</span></div>';
		}
		if (field.type === 'checkbox' || field.type === 'consent') {
			return '<div class="leadealer-builder__mock-check">☐ ' + escapeHtml(field.label || defaultLabel(field.type)) + '</div>';
		}
		if (field.type === 'tel') {
			return '<div class="leadealer-builder__mock-phone"><span>🇫🇷</span><div>' + (placeholder || '06 12 34 56 78') + '</div></div>';
		}
		if (field.type === 'file') {
			return '<div class="leadealer-builder__mock-input leadealer-builder__mock-upload"><span class="dashicons dashicons-camera"></span> ' + escapeHtml(strings.uploadPreviewHint || 'Photo upload') + '</div>';
		}
		return '<div class="leadealer-builder__mock-input">' + (placeholder || '&nbsp;') + '</div>';
	}

	function conditionSummary(field) {
		if (!field.conditional.enabled || !field.conditional.rules.length) {
			return '';
		}
		var firstRule = field.conditional.rules[0];
		var source = fieldById(firstRule.field);
		var sourceLabel = source ? source.label : (strings.missingField || 'Missing field');
		var extra = field.conditional.rules.length > 1 ? ' +' + (field.conditional.rules.length - 1) : '';
		return '<span class="leadealer-builder__condition-badge"><span class="dashicons dashicons-randomize"></span>' + escapeHtml(sourceLabel) + extra + '</span>';
	}

	function renderCanvas() {
		canvas.innerHTML = '';
		viewport.setAttribute('data-device', device);

		if (!schema.length) {
			canvas.innerHTML = '<div class="leadealer-builder__empty"><span class="dashicons dashicons-feedback"></span><strong>' + escapeHtml(strings.emptyTitle || 'Start your form') + '</strong><p>' + escapeHtml(strings.emptyText || 'Choose a field on the left.') + '</p></div>';
			sync();
			return;
		}

		schema.forEach(function (field) {
			ensureField(field);
			var card = document.createElement('div');
			var span = field.layout[device] || 12;
			var label = field.type === 'checkbox' || field.type === 'consent' ? '' : '<div class="leadealer-builder__preview-label">' + escapeHtml(field.label || defaultLabel(field.type)) + (field.required ? ' <span>*</span>' : '') + '</div>';
			var description = field.description ? '<div class="leadealer-builder__preview-description">' + escapeHtml(field.description) + '</div>' : '';
			card.className = 'leadealer-builder__preview-field' + (field.id === selectedId ? ' is-selected' : '') + (field.conditional.enabled ? ' is-conditional' : '');
			card.setAttribute('data-field-id', field.id);
			card.style.gridColumn = 'span ' + span;
			card.innerHTML =
				'<div class="leadealer-builder__preview-toolbar">' +
					'<button type="button" class="leadealer-builder__drag" data-drag-handle aria-label="' + escapeHtml(strings.dragToMove || 'Drag to move') + '" title="' + escapeHtml(strings.dragToMove || 'Drag to move') + '"><span aria-hidden="true">⋮⋮</span></button>' +
					'<span class="dashicons dashicons-' + iconFor(field.type) + '"></span>' +
					'<span class="leadealer-builder__preview-type">' + escapeHtml(defaultLabel(field.type)) + '</span>' +
					conditionSummary(field) +
					'<span class="leadealer-builder__preview-span">' + Math.round((span / 12) * 100) + '%</span>' +
					'<button type="button" data-card-action="duplicate" aria-label="' + escapeHtml(strings.duplicate || 'Duplicate') + '">⧉</button>' +
					'<button type="button" data-card-action="remove" aria-label="' + escapeHtml(strings.remove || 'Delete') + '">×</button>' +
				'</div>' + label + previewControl(field) + description;
			canvas.appendChild(card);
		});
		sync();
	}

	function widthButtons(field) {
		var values = [12, 9, 8, 6, 4, 3];
		return '<div class="leadealer-builder__widths">' + values.map(function (value) {
			var pct = Math.round((value / 12) * 100);
			var active = field.layout[device] === value ? ' is-active' : '';
			return '<button type="button" class="' + active + '" data-width="' + value + '">' + pct + '%</button>';
		}).join('') + '</div>';
	}

	function sourceFields(targetId) {
		return schema.filter(function (field) { return field.id !== targetId; });
	}

	function defaultRule(targetId) {
		var sources = sourceFields(targetId);
		return {
			field: sources.length ? sources[0].id : '',
			operator: 'equals',
			value: sources.length ? defaultRuleValue(sources[0]) : ''
		};
	}

	function defaultRuleValue(source) {
		if (!source) {
			return '';
		}
		if (source.type === 'select' || source.type === 'radio') {
			return source.options.length ? source.options[0].value : '';
		}
		if (source.type === 'checkbox' || source.type === 'consent') {
			return '1';
		}
		return '';
	}

	function sourceSelect(targetId, selected) {
		var sources = sourceFields(targetId);
		if (!sources.length) {
			return '<option value="">' + escapeHtml(strings.noSourceFields || 'Add another field first') + '</option>';
		}
		return sources.map(function (source) {
			return '<option value="' + escapeHtml(source.id) + '"' + (source.id === selected ? ' selected' : '') + '>' + escapeHtml(source.label || defaultLabel(source.type)) + '</option>';
		}).join('');
	}

	function operatorSelect(selected) {
		var operators = [
			['equals', strings.operatorEquals || 'is equal to'],
			['not_equals', strings.operatorNotEquals || 'is not equal to'],
			['is_empty', strings.operatorEmpty || 'is empty'],
			['is_not_empty', strings.operatorNotEmpty || 'is not empty'],
			['contains', strings.operatorContains || 'contains'],
			['not_contains', strings.operatorNotContains || 'does not contain']
		];
		return operators.map(function (operator) {
			return '<option value="' + operator[0] + '"' + (operator[0] === selected ? ' selected' : '') + '>' + escapeHtml(operator[1]) + '</option>';
		}).join('');
	}

	function ruleValueControl(rule, index) {
		var source = fieldById(rule.field);
		if (rule.operator === 'is_empty' || rule.operator === 'is_not_empty') {
			return '<span class="leadealer-builder__condition-no-value">' + escapeHtml(strings.noValueNeeded || 'No value needed') + '</span>';
		}
		if (!source) {
			return '<input type="text" data-rule-index="' + index + '" data-rule-key="value" value="' + escapeHtml(rule.value || '') + '">';
		}
		if (source.type === 'select' || source.type === 'radio') {
			var found = false;
			var choices = source.options.map(function (option) {
				if (option.value === rule.value) {
					found = true;
				}
				return '<option value="' + escapeHtml(option.value) + '"' + (option.value === rule.value ? ' selected' : '') + '>' + escapeHtml(option.label) + '</option>';
			}).join('');
			if (!found && rule.value) {
				choices += '<option value="' + escapeHtml(rule.value) + '" selected>' + escapeHtml(strings.unavailableChoice || 'Unavailable choice') + '</option>';
			}
			return '<select data-rule-index="' + index + '" data-rule-key="value">' + choices + '</select>';
		}
		if (source.type === 'checkbox' || source.type === 'consent') {
			return '<select data-rule-index="' + index + '" data-rule-key="value"><option value="1"' + (rule.value === '1' ? ' selected' : '') + '>' + escapeHtml(strings.checked || 'Checked') + '</option><option value=""' + (rule.value !== '1' ? ' selected' : '') + '>' + escapeHtml(strings.notChecked || 'Not checked') + '</option></select>';
		}
		return '<input type="text" data-rule-index="' + index + '" data-rule-key="value" value="' + escapeHtml(rule.value || '') + '">';
	}

	function conditionalSection(field) {
		var sources = sourceFields(field.id);
		var enabled = field.conditional.enabled && field.conditional.rules.length > 0;
		var body = '';

		if (enabled) {
			body += '<label class="leadealer-control"><span>' + escapeHtml(strings.showWhen || 'Show when') + '</span><select data-condition-key="match"><option value="all"' + (field.conditional.match === 'all' ? ' selected' : '') + '>' + escapeHtml(strings.allRules || 'All rules match') + '</option><option value="any"' + (field.conditional.match === 'any' ? ' selected' : '') + '>' + escapeHtml(strings.anyRule || 'Any rule matches') + '</option></select></label>';
			body += '<div class="leadealer-builder__condition-rules">';
			field.conditional.rules.forEach(function (rule, index) {
				body += '<div class="leadealer-builder__condition-rule">' +
					'<select data-rule-index="' + index + '" data-rule-key="field">' + sourceSelect(field.id, rule.field) + '</select>' +
					'<select data-rule-index="' + index + '" data-rule-key="operator">' + operatorSelect(rule.operator) + '</select>' +
					ruleValueControl(rule, index) +
					'<button type="button" class="button-link-delete" data-rule-remove="' + index + '" aria-label="' + escapeHtml(strings.removeRule || 'Remove rule') + '">×</button>' +
				'</div>';
			});
			body += '</div><button type="button" class="button" data-inspector-action="add-rule"' + (field.conditional.rules.length >= 10 ? ' disabled' : '') + '>+ ' + escapeHtml(strings.addRule || 'Add rule') + '</button>';
		}

		return '<div class="leadealer-builder__section leadealer-builder__conditional">' +
			'<div class="leadealer-builder__section-title"><span>' + escapeHtml(strings.conditionalDisplay || 'Conditional display') + '</span></div>' +
			'<label class="leadealer-toggle"><input type="checkbox" data-condition-key="enabled" ' + (enabled ? 'checked' : '') + (sources.length ? '' : ' disabled') + '><span>' + escapeHtml(strings.showConditionally || 'Show this field conditionally') + '</span></label>' +
			(!sources.length ? '<p>' + escapeHtml(strings.addSourceFirst || 'Add another field before creating a condition.') + '</p>' : body) +
		'</div>';
	}

	function defaultValueControl(field) {
		if (field.type === 'select' || field.type === 'radio') {
			var options = '<option value="">—</option>' + field.options.map(function (option) {
				return '<option value="' + escapeHtml(option.value) + '"' + (field.default === option.value ? ' selected' : '') + '>' + escapeHtml(option.label) + '</option>';
			}).join('');
			return '<label class="leadealer-control"><span>' + escapeHtml(strings.defaultValue || 'Default value') + '</span><select data-key="default">' + options + '</select></label>';
		}
		return '<label class="leadealer-control"><span>' + escapeHtml(strings.defaultValue || 'Default value') + '</span><input type="text" data-key="default" value="' + escapeHtml(field.default || '') + '"></label>';
	}

	function renderInspector() {
		var field = fieldById(selectedId);
		if (!field) {
			inspector.hidden = true;
			formSettings.hidden = false;
			return;
		}

		ensureField(field);
		formSettings.hidden = true;
		inspector.hidden = false;
		var options = optionLabels(field).join('\n');
		var choices = field.type === 'select' || field.type === 'radio';
		var simpleInput = !['checkbox', 'consent', 'file'].includes(field.type);
		inspector.innerHTML =
			'<div class="leadealer-builder__inspector-head"><div><span class="dashicons dashicons-' + iconFor(field.type) + '"></span><div><strong>' + escapeHtml(field.label || defaultLabel(field.type)) + '</strong><small>' + escapeHtml(defaultLabel(field.type)) + '</small></div></div><button type="button" data-inspector-action="close" aria-label="' + escapeHtml(strings.close || 'Close') + '">×</button></div>' +
			'<label class="leadealer-control"><span>' + escapeHtml(strings.fieldLabel || 'Label') + '</span><input type="text" data-key="label" value="' + escapeHtml(field.label || '') + '"></label>' +
			'<label class="leadealer-control"><span>' + escapeHtml(strings.description || 'Help text') + '</span><textarea data-key="description" rows="3">' + escapeHtml(field.description || '') + '</textarea></label>' +
			(field.type === 'consent' ? '<p class="leadealer-builder__privacy-note">' + escapeHtml(strings.consentPrivacyNote || 'A short privacy notice and a link to the WordPress Privacy Policy page are added automatically on the front end.') + '</p>' : '') +
			(simpleInput ? '<label class="leadealer-control"><span>' + escapeHtml(strings.placeholder || 'Placeholder') + '</span><input type="text" data-key="placeholder" value="' + escapeHtml(field.placeholder || '') + '"></label>' : '') +
			(field.type === 'textarea' ? '<label class="leadealer-control"><span>' + escapeHtml(strings.appearance || 'Appearance') + '</span><select data-key="appearance"><option value="default"' + (field.appearance !== 'message' ? ' selected' : '') + '>' + escapeHtml(strings.appearanceStandard || 'Standard field') + '</option><option value="message"' + (field.appearance === 'message' ? ' selected' : '') + '>' + escapeHtml(strings.appearanceMessage || 'Message bubble') + '</option></select><small>' + escapeHtml(strings.messageAppearanceTip || 'A conversational style inspired by messaging apps. The field remains a native accessible textarea.') + '</small></label>' : '') +
			(choices ? '<label class="leadealer-control"><span>' + escapeHtml(strings.options || 'Choices, one per line') + '</span><textarea data-key="options" rows="5">' + escapeHtml(options) + '</textarea><small>' + escapeHtml(strings.stableChoices || 'Internal values stay stable when you rename a choice.') + '</small></label>' : '') +
			'<label class="leadealer-toggle"><input type="checkbox" data-key="required" ' + (field.required ? 'checked' : '') + '><span>' + escapeHtml(strings.required || 'Required field') + '</span></label>' +
			'<div class="leadealer-builder__section"><div class="leadealer-builder__section-title"><span>' + escapeHtml(strings.layout || 'Width') + '</span><small>' + escapeHtml(strings[device] || device) + '</small></div>' + widthButtons(field) + '<p>' + escapeHtml(strings.mobileTip || 'Mobile defaults to one column. Change it only when the fields stay comfortable to use.') + '</p></div>' +
			conditionalSection(field) +
			'<details class="leadealer-builder__advanced"><summary>' + escapeHtml(strings.advanced || 'Advanced') + '</summary>' +
				(simpleInput ? defaultValueControl(field) : '') +
				(simpleInput ? '<label class="leadealer-control"><span>' + escapeHtml(strings.autocomplete || 'Autocomplete') + '</span><input type="text" data-key="autocomplete" value="' + escapeHtml(field.autocomplete || '') + '" placeholder="' + escapeHtml(strings.autocompleteExample || 'name, email, organization…') + '"></label>' : '') +
			'</details>' +
			'<div class="leadealer-builder__danger"><button type="button" class="button" data-inspector-action="duplicate">' + escapeHtml(strings.duplicate || 'Duplicate') + '</button><button type="button" class="button-link-delete" data-inspector-action="remove">' + escapeHtml(strings.remove || 'Delete') + '</button></div>';
	}

	function refresh() {
		renderCanvas();
		renderInspector();
	}

	function defaultField(type) {
		var choices = type === 'select' || type === 'radio';
		return ensureField({
			id: fieldId(),
			type: type,
			label: type === 'consent' ? (strings.consentDefaultLabel || 'I agree that my data may be used to respond to my request.') : defaultLabel(type),
			appearance: 'default',
			description: '',
			placeholder: '',
			default: '',
			autocomplete: type === 'email' ? 'email' : (type === 'tel' ? 'tel' : ''),
			required: type === 'consent',
			options: choices ? [strings.defaultOptionOne || 'Option 1', strings.defaultOptionTwo || 'Option 2'] : [],
			layout: { desktop: 12, tablet: 12, mobile: 12 },
			conditional: { enabled: false, match: 'all', rules: [] }
		});
	}

	function addField(type) {
		remember();
		var field = defaultField(type);
		schema.push(field);
		selectedId = field.id;
		refresh();
	}

	function applyTemplate(templateId) {
		var template = templates[templateId];
		if (!template || !Array.isArray(template.schema)) {
			return;
		}

		if (schema.length && !window.confirm(strings.templateConfirm || 'Replace the current fields with this template? You can undo this action.')) {
			return;
		}

		remember();
		schema = clone(template.schema).map(ensureField);
		selectedId = '';


		refresh();
	}

	function removeField(id) {
		var index = fieldIndex(id);
		if (index < 0) {
			return;
		}
		remember();
		schema.splice(index, 1);
		schema.forEach(function (field) {
			ensureConditional(field);
			field.conditional.rules = field.conditional.rules.filter(function (rule) { return rule.field !== id; });
			if (!field.conditional.rules.length) {
				field.conditional.enabled = false;
			}
		});
		selectedId = '';
		refresh();
	}

	function duplicateField(id) {
		var index = fieldIndex(id);
		if (index < 0) {
			return;
		}
		remember();
		var copy = clone(schema[index]);
		copy.id = fieldId();
		copy.label = (copy.label || defaultLabel(copy.type)) + ' ' + (strings.copySuffix || 'copy');
		schema.splice(index + 1, 0, ensureField(copy));
		selectedId = copy.id;
		refresh();
	}

	function updateOptions(field, text) {
		var lines = text.split('\n').map(function (item) { return item.trim(); }).filter(Boolean);
		var old = field.options.slice(0);
		var used = {};
		var byLabel = {};
		old.forEach(function (option) { byLabel[option.label] = option; });

		field.options = lines.map(function (label, index) {
			var matched = byLabel[label];
			var indexed = old[index];
			var candidate = matched && !used[matched.value] ? matched.value : (indexed && !used[indexed.value] ? indexed.value : '');
			var value = uniqueOptionValue(candidate || label, used, index);
			return { label: label, value: value };
		});

		if (field.default && !field.options.some(function (option) { return option.value === field.default; })) {
			field.default = '';
		}
	}

	builder.addEventListener('click', function (event) {
		var templateButton = event.target.closest('.leadealer-builder__template');
		if (templateButton) {
			applyTemplate(templateButton.getAttribute('data-template'));
			return;
		}

		var add = event.target.closest('.leadealer-builder__add');
		if (add) {
			if (Date.now() < suppressPaletteClickUntil) {
				return;
			}
			addField(add.getAttribute('data-type'));
			return;
		}

		var deviceButton = event.target.closest('.leadealer-builder__device');
		if (deviceButton) {
			device = deviceButton.getAttribute('data-device');
			builder.querySelectorAll('.leadealer-builder__device').forEach(function (button) {
				button.classList.toggle('is-active', button === deviceButton);
			});
			refresh();
			return;
		}

		var historyAction = event.target.closest('[data-builder-action]');
		if (historyAction) {
			var action = historyAction.getAttribute('data-builder-action');
			if (action === 'undo' && history.length) {
				future.push(clone(schema));
				schema = history.pop().map(ensureField);
				selectedId = '';
				refresh();
			} else if (action === 'redo' && future.length) {
				history.push(clone(schema));
				schema = future.pop().map(ensureField);
				selectedId = '';
				refresh();
			}
			return;
		}

		var cardAction = event.target.closest('[data-card-action]');
		if (cardAction) {
			var card = cardAction.closest('.leadealer-builder__preview-field');
			var cardId = card.getAttribute('data-field-id');
			if (cardAction.getAttribute('data-card-action') === 'remove') {
				removeField(cardId);
			} else {
				duplicateField(cardId);
			}
			return;
		}

		var width = event.target.closest('[data-width]');
		if (width && selectedId) {
			var widthField = fieldById(selectedId);
			remember();
			widthField.layout[device] = parseInt(width.getAttribute('data-width'), 10) || 12;
			refresh();
			return;
		}

		var ruleRemove = event.target.closest('[data-rule-remove]');
		if (ruleRemove && selectedId) {
			var removeRuleField = fieldById(selectedId);
			var ruleIndex = parseInt(ruleRemove.getAttribute('data-rule-remove'), 10);
			if (removeRuleField && ruleIndex >= 0) {
				remember();
				removeRuleField.conditional.rules.splice(ruleIndex, 1);
				if (!removeRuleField.conditional.rules.length) {
					removeRuleField.conditional.enabled = false;
				}
				refresh();
			}
			return;
		}

		var inspectorAction = event.target.closest('[data-inspector-action]');
		if (inspectorAction) {
			var inspectorValue = inspectorAction.getAttribute('data-inspector-action');
			if (inspectorValue === 'close') {
				selectedId = '';
				refresh();
			} else if (inspectorValue === 'remove') {
				removeField(selectedId);
			} else if (inspectorValue === 'duplicate') {
				duplicateField(selectedId);
			} else if (inspectorValue === 'add-rule') {
				var target = fieldById(selectedId);
				if (target && sourceFields(target.id).length && target.conditional.rules.length < 10) {
					remember();
					target.conditional.enabled = true;
					target.conditional.rules.push(defaultRule(target.id));
					refresh();
				}
			}
			return;
		}

		var cardSelect = event.target.closest('.leadealer-builder__preview-field');
		if (cardSelect && !event.target.closest('[data-drag-handle]')) {
			selectedId = cardSelect.getAttribute('data-field-id');
			refresh();
		}
	});

	inspector.addEventListener('focusin', function (event) {
		if (event.target.matches('[data-key]')) {
			editingSnapshot = clone(schema);
		}
	});

	inspector.addEventListener('input', function (event) {
		var key = event.target.getAttribute('data-key');
		var field = fieldById(selectedId);
		if (!key || !field) {
			return;
		}
		if (key === 'required') {
			field.required = event.target.checked;
		} else if (key === 'options') {
			updateOptions(field, event.target.value);
		} else {
			field[key] = event.target.value;
		}
		sync();
		renderCanvas();
	});

	inspector.addEventListener('change', function (event) {
		var field = fieldById(selectedId);
		var conditionKey = event.target.getAttribute('data-condition-key');
		var ruleKey = event.target.getAttribute('data-rule-key');
		var ruleIndex;
		var source;

		if (!field) {
			return;
		}

		if (conditionKey) {
			remember();
			if (conditionKey === 'enabled') {
				field.conditional.enabled = event.target.checked;
				if (field.conditional.enabled && !field.conditional.rules.length && sourceFields(field.id).length) {
					field.conditional.rules.push(defaultRule(field.id));
				}
			} else if (conditionKey === 'match') {
				field.conditional.match = event.target.value === 'any' ? 'any' : 'all';
			}
			refresh();
			return;
		}

		if (ruleKey) {
			ruleIndex = parseInt(event.target.getAttribute('data-rule-index'), 10);
			if (!field.conditional.rules[ruleIndex]) {
				return;
			}
			remember();
			field.conditional.rules[ruleIndex][ruleKey] = event.target.value;
			if (ruleKey === 'field') {
				source = fieldById(event.target.value);
				field.conditional.rules[ruleIndex].value = defaultRuleValue(source);
			}
			if (ruleKey === 'operator' && (event.target.value === 'is_empty' || event.target.value === 'is_not_empty')) {
				field.conditional.rules[ruleIndex].value = '';
			}
			refresh();
			return;
		}

		if (event.target.matches('[data-key]') && editingSnapshot) {
			pushHistory(editingSnapshot);
			editingSnapshot = null;
			sync();
		}
	});


	function cardInfos(excludedId) {
		return Array.prototype.slice.call(canvas.querySelectorAll('.leadealer-builder__preview-field')).filter(function (card) {
			return card.getAttribute('data-field-id') !== excludedId && !card.classList.contains('is-drag-source');
		}).map(function (card) {
			return {
				card: card,
				rect: card.getBoundingClientRect()
			};
		});
	}

	function insertionIndexFromRects(infos, clientX, clientY) {
		var rows = [];
		var rowTolerance = 10;
		var closestRow;
		var closestDistance = Infinity;

		if (!infos.length) {
			return 0;
		}
		if (clientY < infos[0].rect.top) {
			return 0;
		}
		if (clientY > infos[infos.length - 1].rect.bottom) {
			return infos.length;
		}

		infos.forEach(function (info) {
			var row = rows.length ? rows[rows.length - 1] : null;
			if (!row || Math.abs(info.rect.top - row.top) > rowTolerance) {
				row = { top: info.rect.top, bottom: info.rect.bottom, items: [] };
				rows.push(row);
			}
			row.items.push(info);
			row.top = Math.min(row.top, info.rect.top);
			row.bottom = Math.max(row.bottom, info.rect.bottom);
		});

		rows.forEach(function (row) {
			var center = (row.top + row.bottom) / 2;
			var distance = Math.abs(clientY - center);
			if (distance < closestDistance) {
				closestDistance = distance;
				closestRow = row;
			}
		});

		var firstCard = closestRow.items[0].card;
		var firstIndex = infos.findIndex(function (info) { return info.card === firstCard; });
		var offset = 0;
		closestRow.items.forEach(function (info) {
			if (clientX > info.rect.left + (info.rect.width / 2)) {
				offset += 1;
			}
		});

		return Math.max(0, Math.min(infos.length, firstIndex + offset));
	}

	function canvasInsertionIndex(clientX, clientY, excludedId) {
		return insertionIndexFromRects(cardInfos(excludedId), clientX, clientY);
	}

	function pointerInsideCanvas(clientX, clientY) {
		var rect = canvas.getBoundingClientRect();
		return clientX >= rect.left - 24 && clientX <= rect.right + 24 && clientY >= rect.top - 36 && clientY <= rect.bottom + 36;
	}

	function makePlaceholder(span) {
		var placeholder = document.createElement('div');
		placeholder.className = 'leadealer-builder__drop-placeholder';
		placeholder.style.gridColumn = 'span ' + (span || 12);
		placeholder.innerHTML = '<span class="dashicons dashicons-move"></span><span>' + escapeHtml(strings.dropHere || 'Drop field here') + '</span>';
		return placeholder;
	}

	function makeGhost(source, label) {
		var rect = source.getBoundingClientRect();
		var ghost = document.createElement('div');
		ghost.className = 'leadealer-builder__drag-ghost';
		ghost.style.width = Math.max(140, Math.min(rect.width, 360)) + 'px';
		ghost.innerHTML = '<span class="dashicons dashicons-move"></span><strong>' + escapeHtml(label) + '</strong>';
		document.body.appendChild(ghost);
		return ghost;
	}

	function moveGhost(clientX, clientY) {
		if (!dragState || !dragState.ghost) {
			return;
		}
		dragState.ghost.style.transform = 'translate3d(' + (clientX + 14) + 'px,' + (clientY + 14) + 'px,0)';
	}

	function placePlaceholder(index) {
		var cards;
		if (!dragState || !dragState.placeholder) {
			return;
		}
		cards = Array.prototype.slice.call(canvas.querySelectorAll('.leadealer-builder__preview-field')).filter(function (card) {
			return !dragState.sourceId || card.getAttribute('data-field-id') !== dragState.sourceId;
		});
		if (index >= cards.length) {
			canvas.appendChild(dragState.placeholder);
		} else {
			canvas.insertBefore(dragState.placeholder, cards[index]);
		}
		dragState.insertIndex = index;
	}

	function updateDropPosition(clientX, clientY) {
		if (!dragState || (dragState.kind !== 'existing' && dragState.kind !== 'palette')) {
			return;
		}
		moveGhost(clientX, clientY);
		if (!pointerInsideCanvas(clientX, clientY)) {
			dragState.insertIndex = null;
			if (dragState.placeholder.parentNode) {
				dragState.placeholder.parentNode.removeChild(dragState.placeholder);
			}
			canvas.classList.remove('is-drop-zone');
			return;
		}

		canvas.classList.add('is-drop-zone');
		if (dragState.placeholder.parentNode) {
			dragState.placeholder.parentNode.removeChild(dragState.placeholder);
		}
		placePlaceholder(canvasInsertionIndex(clientX, clientY, dragState.sourceId || ''));

		var stageRect = stage.getBoundingClientRect();
		if (clientY < stageRect.top + 70) {
			stage.scrollTop -= 18;
		} else if (clientY > stageRect.bottom - 70) {
			stage.scrollTop += 18;
		}
	}

	function startExistingDrag(event, handle) {
		var card = handle.closest('.leadealer-builder__preview-field');
		var id = card ? card.getAttribute('data-field-id') : '';
		var field = fieldById(id);
		if (!card || !field || event.button !== 0) {
			return;
		}

		event.preventDefault();
		dragState = {
			kind: 'existing',
			pointerId: event.pointerId,
			sourceId: id,
			field: field,
			placeholder: makePlaceholder(field.layout[device] || 12),
			ghost: makeGhost(card, field.label || defaultLabel(field.type)),
			insertIndex: fieldIndex(id)
		};
		card.classList.add('is-drag-source');
		document.body.classList.add('leadealer-builder-is-dragging');
		updateDropPosition(event.clientX, event.clientY);
	}

	function startPalettePending(event, button) {
		if (event.button !== 0) {
			return;
		}
		dragState = {
			kind: 'palette-pending',
			pointerId: event.pointerId,
			type: button.getAttribute('data-type'),
			button: button,
			startX: event.clientX,
			startY: event.clientY
		};
	}

	function activatePaletteDrag(event) {
		var field = defaultField(dragState.type);
		dragState.kind = 'palette';
		dragState.field = field;
		dragState.placeholder = makePlaceholder(field.layout[device] || 12);
		dragState.ghost = makeGhost(dragState.button, field.label || defaultLabel(field.type));
		dragState.insertIndex = null;
		document.body.classList.add('leadealer-builder-is-dragging');
		suppressPaletteClickUntil = Date.now() + 500;
		updateDropPosition(event.clientX, event.clientY);
	}

	function cleanupDrag() {
		if (!dragState) {
			return;
		}
		if (dragState.ghost && dragState.ghost.parentNode) {
			dragState.ghost.parentNode.removeChild(dragState.ghost);
		}
		if (dragState.placeholder && dragState.placeholder.parentNode) {
			dragState.placeholder.parentNode.removeChild(dragState.placeholder);
		}
		canvas.querySelectorAll('.is-drag-source').forEach(function (node) {
			node.classList.remove('is-drag-source');
		});
		canvas.classList.remove('is-drop-zone');
		document.body.classList.remove('leadealer-builder-is-dragging');
	}

	function finishDrag(event) {
		var state = dragState;
		var next;
		var oldIndex;
		var targetIndex;

		if (!state) {
			return;
		}
		if (state.kind === 'palette-pending') {
			dragState = null;
			return;
		}

		if (state.insertIndex !== null && pointerInsideCanvas(event.clientX, event.clientY)) {
			if (state.kind === 'palette') {
				remember();
				schema.splice(Math.max(0, Math.min(schema.length, state.insertIndex)), 0, state.field);
				selectedId = state.field.id;
			} else if (state.kind === 'existing') {
				oldIndex = fieldIndex(state.sourceId);
				next = schema.filter(function (field) { return field.id !== state.sourceId; });
				targetIndex = Math.max(0, Math.min(next.length, state.insertIndex));
				if (oldIndex !== targetIndex) {
					remember();
					next.splice(targetIndex, 0, state.field);
					schema = next;
				}
				selectedId = state.sourceId;
			}
		}

		cleanupDrag();
		dragState = null;
		refresh();
	}

	builder.addEventListener('pointerdown', function (event) {
		var handle = event.target.closest('[data-drag-handle]');
		var paletteButton = event.target.closest('.leadealer-builder__add');
		if (handle) {
			startExistingDrag(event, handle);
		} else if (paletteButton) {
			startPalettePending(event, paletteButton);
		}
	});

	document.addEventListener('pointermove', function (event) {
		if (!dragState || dragState.pointerId !== event.pointerId) {
			return;
		}
		if (dragState.kind === 'palette-pending') {
			if (Math.hypot(event.clientX - dragState.startX, event.clientY - dragState.startY) >= 7) {
				event.preventDefault();
				activatePaletteDrag(event);
			}
			return;
		}
		event.preventDefault();
		updateDropPosition(event.clientX, event.clientY);
	}, { passive: false });

	document.addEventListener('pointerup', function (event) {
		if (dragState && dragState.pointerId === event.pointerId) {
			finishDrag(event);
		}
	});

	document.addEventListener('pointercancel', function (event) {
		if (dragState && dragState.pointerId === event.pointerId) {
			cleanupDrag();
			dragState = null;
			refresh();
		}
	});

	canvas.addEventListener('keydown', function (event) {
		var handle = event.target.closest('[data-drag-handle]');
		var card;
		var id;
		var from;
		var to;
		var moved;
		if (!handle) {
			return;
		}
		card = handle.closest('.leadealer-builder__preview-field');
		id = card ? card.getAttribute('data-field-id') : '';
		from = fieldIndex(id);
		to = from;
		if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
			to = Math.max(0, from - 1);
		} else if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
			to = Math.min(schema.length - 1, from + 1);
		} else if (event.key === 'Home') {
			to = 0;
		} else if (event.key === 'End') {
			to = schema.length - 1;
		} else {
			return;
		}
		event.preventDefault();
		if (from < 0 || to === from) {
			return;
		}
		remember();
		moved = schema.splice(from, 1)[0];
		schema.splice(to, 0, moved);
		selectedId = id;
		refresh();
		window.requestAnimationFrame(function () {
			var nextHandle = canvas.querySelector('[data-field-id="' + id + '"] [data-drag-handle]');
			if (nextHandle) {
				nextHandle.focus();
			}
		});
	});

	refresh();
}());
