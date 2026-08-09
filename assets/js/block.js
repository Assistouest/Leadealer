(function (blocks, element, components, i18n, blockEditor) {
	'use strict';

	var el = element.createElement;
	var InspectorControls = blockEditor.InspectorControls;
	var SelectControl = components.SelectControl;
	var PanelBody = components.PanelBody;
	var Notice = components.Notice;
	var forms = (window.LeadealerBlock && window.LeadealerBlock.forms) || [];
	var options = [{ label: i18n.__('Choose a form', 'leadealer'), value: 0 }].concat(forms.map(function (form) {
		return { label: form.title || ('#' + form.id), value: form.id };
	}));

	blocks.registerBlockType('leadealer/form', {
		apiVersion: 3,
		title: i18n.__('Leadealer Form', 'leadealer'),
		icon: 'feedback',
		category: 'widgets',
		attributes: { formId: { type: 'integer', default: 0 } },
		edit: function (props) {
			var selected = forms.find(function (form) { return form.id === props.attributes.formId; });
			return el('div', { className: props.className },
				el(InspectorControls, {}, el(PanelBody, { title: i18n.__('Form', 'leadealer'), initialOpen: true },
					el(SelectControl, {
						label: i18n.__('Form', 'leadealer'),
						value: props.attributes.formId,
						options: options,
						onChange: function (value) { props.setAttributes({ formId: parseInt(value, 10) || 0 }); }
					})
				)),
				el(Notice, { status: selected ? 'info' : 'warning', isDismissible: false }, selected ? i18n.sprintf(i18n.__('Leadealer Form: %s', 'leadealer'), selected.title) : i18n.__('Choose a form in the block settings.', 'leadealer'))
			);
		},
		save: function () { return null; }
	});
}(window.wp.blocks, window.wp.element, window.wp.components, window.wp.i18n, window.wp.blockEditor));
