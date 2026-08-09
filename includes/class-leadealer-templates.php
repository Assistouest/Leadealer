<?php
/**
 * Built-in form templates.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides translated starter templates for the visual builder.
 *
 * Template source strings remain in US English. WordPress translates the
 * visible labels when the template is inserted, while internal field IDs and
 * choice values remain language-neutral and stable.
 */
final class Leadealer_Templates {

	/**
	 * Get all templates available in the builder.
	 *
	 * @param Leadealer_Form_Repository $forms Form repository used to normalize schemas.
	 * @return array<string,array<string,mixed>>
	 */
	public function get_all( $forms ) {
		$templates = array(
			'it-services-contact' => array(
				'id'          => 'it-services-contact',
				'name'        => __( 'IT services contact', 'leadealer' ),
				'description' => __( 'A ready-made contact form for support, computer repair, WordPress projects, and quote requests.', 'leadealer' ),
				'schema'      => array(
					array(
						'id'           => 'full_name',
						'type'         => 'text',
						'label'        => __( 'Full name', 'leadealer' ),
						'description'  => '',
						'placeholder'  => __( 'First and last name', 'leadealer' ),
						'default'      => '',
						'autocomplete' => 'name',
						'required'     => true,
						'options'      => array(),
						'layout'       => array(
							'desktop' => 6,
							'tablet'  => 6,
							'mobile'  => 12,
						),
						'conditional'  => $this->empty_conditional(),
					),
					array(
						'id'           => 'email',
						'type'         => 'email',
						'label'        => __( 'Email', 'leadealer' ),
						'description'  => '',
						'placeholder'  => 'name@example.com',
						'default'      => '',
						'autocomplete' => 'email',
						'required'     => true,
						'options'      => array(),
						'layout'       => array(
							'desktop' => 6,
							'tablet'  => 6,
							'mobile'  => 12,
						),
						'conditional'  => $this->empty_conditional(),
					),
					array(
						'id'           => 'phone',
						'type'         => 'tel',
						'label'        => __( 'Phone', 'leadealer' ),
						'description'  => '',
						'placeholder'  => '',
						'default'      => '',
						'autocomplete' => 'tel',
						'required'     => true,
						'options'      => array(),
						'layout'       => array(
							'desktop' => 6,
							'tablet'  => 6,
							'mobile'  => 12,
						),
						'conditional'  => $this->empty_conditional(),
					),
					array(
						'id'           => 'service_requested',
						'type'         => 'select',
						'label'        => __( 'What do you need?', 'leadealer' ),
						'description'  => '',
						'placeholder'  => '',
						'default'      => '',
						'autocomplete' => '',
						'required'     => true,
						'options'      => array(
							array(
								'label' => __( 'Home computer support', 'leadealer' ),
								'value' => 'home-computer-support',
							),
							array(
								'label' => __( 'Computer repair', 'leadealer' ),
								'value' => 'computer-repair',
							),
							array(
								'label' => __( 'WordPress website creation', 'leadealer' ),
								'value' => 'wordpress-website-creation',
							),
							array(
								'label' => __( 'Quote request or another question', 'leadealer' ),
								'value' => 'contact-or-quote',
							),
						),
						'layout'       => array(
							'desktop' => 6,
							'tablet'  => 6,
							'mobile'  => 12,
						),
						'conditional'  => $this->empty_conditional(),
					),
					array(
						'id'           => 'service_address',
						'type'         => 'text',
						'label'        => __( 'Service address', 'leadealer' ),
						'description'  => '',
						'placeholder'  => __( 'Street address, postal code and city', 'leadealer' ),
						'default'      => '',
						'autocomplete' => 'street-address',
						'required'     => true,
						'options'      => array(),
						'layout'       => array(
							'desktop' => 12,
							'tablet'  => 12,
							'mobile'  => 12,
						),
						'conditional'  => array(
							'enabled' => true,
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'service_requested',
									'operator' => 'equals',
									'value'    => 'home-computer-support',
								),
							),
						),
					),
					$forms->get_it_services_photo_field_definition(),
					array(
						'id'           => 'message',
						'type'         => 'textarea',
						'appearance'   => 'message',
						'label'        => __( 'How can we help?', 'leadealer' ),
						'description'  => '',
						'placeholder'  => __( 'Describe your request, the issue you are experiencing, or your project…', 'leadealer' ),
						'default'      => '',
						'autocomplete' => '',
						'required'     => true,
						'options'      => array(),
						'layout'       => array(
							'desktop' => 12,
							'tablet'  => 12,
							'mobile'  => 12,
						),
						'conditional'  => $this->empty_conditional(),
					),
					array(
						'id'           => 'consent',
						'type'         => 'consent',
						'label'        => __( 'I agree that my data may be used to contact me.', 'leadealer' ),
						'description'  => '',
						'placeholder'  => '',
						'default'      => '',
						'autocomplete' => '',
						'required'     => true,
						'options'      => array(),
						'layout'       => array(
							'desktop' => 12,
							'tablet'  => 12,
							'mobile'  => 12,
						),
						'conditional'  => $this->empty_conditional(),
					),
				),
			),
		);

		foreach ( $templates as $template_id => $template ) {
			$templates[ $template_id ]['schema'] = $forms->normalize_schema( $template['schema'] );
		}

		/**
		 * Filter the built-in form templates available in the builder.
		 *
		 * @param array $templates Template definitions keyed by template ID.
		 */
		return apply_filters( 'leadealer_templates', $templates );
	}

	/**
	 * Return an empty conditional configuration.
	 *
	 * @return array<string,mixed>
	 */
	private function empty_conditional() {
		return array(
			'enabled' => false,
			'match'   => 'all',
			'rules'   => array(),
		);
	}
}
