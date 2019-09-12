<?php

namespace Metabolism\MailchimpConnector;

class Settings
{
	/**
	 * Holds the values to be used in the fields callbacks
	 */
	private $options;

	/**
	 * Start up
	 */
	public function __construct()
	{
		if( !is_admin() )
			return;

		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'page_init' ) );
	}

	/**
	 * Add options page
	 */
	public function add_plugin_page()
	{
		// This page will be under "Settings"
		add_options_page(
			'Settings Admin',
			'Mailchimp Connector',
			'manage_options',
			'mailchimp-connector',
			array( $this, 'create_admin_page' )
		);
	}

	/**
	 * Options page callback
	 */
	public function create_admin_page()
	{
		// Set class property
		$this->options = get_option( 'mailchimp_connector' );
		?>
		<div class="wrap">
			<h1>Mailchimp Connector</h1>
			<form method="post" action="options.php">
				<?php
				// This prints out all hidden setting fields
				settings_fields( 'mailchimp_connector' );
				do_settings_sections( 'mailchimp_connector-admin' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register and add settings
	 */
	public function page_init()
	{
		register_setting(
			'mailchimp_connector', // Option group
			'mailchimp_connector', // Option name
			array( $this, 'sanitize' ) // Sanitize
		);

		add_settings_section(
			'mailchimp_settings', // ID
			__('Mailchimp settings','mc'), // Title
			false,
			'mailchimp_connector-admin' // Page
		);

		add_settings_field(
			'mailchimp_connector_api_key', // ID
			__('API Key'), // Title
			function()
			{
				printf(
					'<input type="text" name="mailchimp_connector[api_key]" required value="%s" style="width:300px"/>',
					isset( $this->options['api_key'] ) ? esc_attr( $this->options['api_key']) : ''
				);
			},
			'mailchimp_connector-admin', // Page
			'mailchimp_settings' // Section
		);

		add_settings_field(
			'mailchimp_connector_post_type', // ID
			__('Post type'), // Title
			function()
			{
				$post_types = get_post_types(['_builtin'=>false], 'objects');

				echo '<select name="mailchimp_connector[post_type]" style="width:300px">';
				foreach ($post_types as $id=>$post_type){

					if( substr($id, 0, 3) !== 'acf' )
					echo '<option value="'.$id.'" '.(isset($this->options['post_type']) && $this->options['post_type'] == $id ? 'selected':'').'>'.$post_type->label.'</option>';
				}
				echo '</select>';
			},
			'mailchimp_connector-admin', // Page
			'mailchimp_settings' // Section
		);

		add_settings_field(
			'mailchimp_connector_from', // ID
			__('From name','mc'), // Title
			function()
			{
				echo '<input type="text" name="mailchimp_connector[from]" required value="'.(isset($this->options['from'])?$this->options['from']:'').'" style="width:300px"/>';
			},
			'mailchimp_connector-admin', // Page
			'mailchimp_settings' // Section
		);

		add_settings_field(
			'mailchimp_connector_reply', // ID
			__('Reply to','mc'), // Title
			function()
			{
				echo '<input type="email" name="mailchimp_connector[reply]" required value="'.(isset($this->options['reply'])?$this->options['reply']:'').'" placeholder="@" style="width:300px"/>';
			},
			'mailchimp_connector-admin', // Page
			'mailchimp_settings' // Section
		);
	}

	/**
	 * Sanitize each setting field as needed
	 *
	 * @param array $input Contains all settings fields as array keys
	 * @return array
	 */
	public function sanitize( $input )
	{
		$new_input = array();

		if( isset( $input['api_key'] ) )
			$new_input['api_key'] = sanitize_text_field( $input['api_key'] );

		if( isset( $input['post_type'] ) )
			$new_input['post_type'] = sanitize_text_field( $input['post_type'] );

		if( isset( $input['reply'] ) )
			$new_input['reply'] = sanitize_email( $input['reply'] );

		if( isset( $input['from'] ) )
			$new_input['from'] = sanitize_text_field( $input['from'] );

		return $new_input;
	}
}