<?php
if ( !class_exists( 'acf_vf_updates' ) ):
class acf_vf_updates
	{

	var $db_version, $acf_version;

	private $db_updates;

	public function __construct()
	{	
		if ( !function_exists( 'acf' ) ){
			return;
		}

		// store the db version for each ACF version since we can only load fields from the active version
		$this->acf_version = version_compare( acf()->settings['version'], '5.0', '<' )? 4 : 5;

		// default to 0 and go through each update from there
		$this->db_version = get_option( $this->get_version_key(), 0 );

		// list of updates
		$this->db_updates = array(
			'upgrade_1' => __( 'Relationship Fields: Generate helper meta fields for uniqueness queries.', 'acf_vf' ),
			'upgrade_2' => __( 'Update Validated Field Read Only values.', 'acf_vf' ),
		);

		// Init at priority 20 so $acf_vf will have been instantiated
		// ACF4
		add_action( 'acf/register_fields', array( $this, 'init' ), 20 );
		// ACF 5
		add_action( 'acf/include_fields', array( $this, 'init' ), 20 );

		// We need to load the tab early for ACF4
		if ( $this->db_version < count( $this->db_updates ) ) {
			add_filter( 'acf_vf/options_field_group', array( $this, 'options_field_group' ) );
			add_filter( 'acf_vf/admin_notices/upgrade', '__return_true' );
		}
	}

	public function init()
	{
		$count = count( $this->db_updates );
		if ( $this->db_version < count( $this->db_updates ) ) {
			add_action( 'admin_head', array( $this, 'admin_head' ) );

			add_action( 'wp_ajax_acf_vf_get_upgrades', array( $this, 'get_upgrades' ) );
			add_action( 'wp_ajax_acf_vf_do_upgrade', array( $this, 'do_upgrade' ) );
		}
	}

	public function admin_head()
	{
		global $acf_vf;
		$min = ( !$acf_vf->debug )? '.min' : '';

		if ( $this->is_settings_page() )
			wp_enqueue_script( 'acf-validated-db-updates', plugins_url( "../common/js/db-updates{$min}.js", __FILE__ ), array( 'jquery' ), ACF_VF_VERSION, true );
 
		// translations
		wp_localize_script(
			'acf-validated-db-updates', 'vf_upgrade_l10n', array(
				'upgrade_complete' => __( 'Database upgrades completed! Your browser will now refresh.', 'acf_vf' )
			)
		);
	}

	private function is_settings_page()
	{
		global $pagenow;

		// for admin, just check the page value
		$valid_pages = array( 'validated-field-settings', 'acf-validated-field' );
		if ( $pagenow == 'admin.php' ){
			if ( isset( $_REQUEST['page'] ) && in_array( $_REQUEST['page'], $valid_pages ) ){
				return true;
			}
		}

		// if it's edit, check the page and the post_type
		if ( $pagenow == 'edit.php' ){
			if ( isset( $_REQUEST['page'] ) && in_array( $_REQUEST['page'], $valid_pages ) ){
				$valid_types = array( 'acf-field-group', 'acf' );
				if ( isset( $_REQUEST['post_type'] ) && in_array( $_REQUEST['post_type'], $valid_types ) ){
					return true;
				}
			}
		}

		// not our settings page
		return false;
	}

	// The fields for the upgrade layout, doesn't save any values
	public static function get_fields()
	{
		return array(
			array(
				'key' => 'field_5617ec772774e',
				'label' => __( 'Database Updates!', 'acf_vf' ),
				'name' => '',
				'type' => 'tab',
				'instructions' => '',
				'required' => 0,
				'conditional_logic' => 0,
				'wrapper' => array(
					'width' => '',
					'class' => 'justin',
					'id' => '',
				),
				'placement' => 'top',
				'endpoint' => 0,
			),
			array(
				'key' => 'field_5617ec942774f',
				'label' => __( 'Database Updates Message', 'acf_vf' ),
				'name' => '',
				'type' => 'message',
				'instructions' => '',
				'required' => 0,
				'conditional_logic' => 0,
				'wrapper' => array(
					'width' => '',
					'class' => '',
					'id' => '',
				),
				'message' => '<div id="acf-vf-db-upgrades"></div>',
				'new_lines' => 'wpautop',
				'esc_html' => 0,
			)
		);
	}

	// Add new tab if there are DB updates to be done
	public function options_field_group( $field_settings )
	{
		$update_fields = self::get_fields();
		$fields = array_merge( $field_settings['fields'], $update_fields );
		$field_settings['fields'] = $fields;
		return $field_settings;
	}

	private function to_json_response( $response )
	{
		global $acf_vf;

		if ( isset( $response['messages'] ) && empty( $response['messages'] ) ) {
			$response['messages'] = array( array( 'text' => __( 'Nothing to update!', 'acf_vf' ) ) );
		}

		header( 'HTTP/1.1 200 OK' );							// be positive!
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		// Send the results back to the browser as JSON
		die( version_compare( phpversion(), '5.3', '>=' )? 
			json_encode( $response, $acf_vf->debug? JSON_PRETTY_PRINT : 0 ) :
			json_encode( $response ) );
	}

	public function get_upgrades()
	{
		$upgrades = array();
		$functions = array_keys( $this->db_updates );
		for ( $i=$this->db_version; $i<count( $functions ); $i++ ) {
			$function = $functions[$i];
			$upgrades[] = array(
				'upgrade' => $function,
				'label' => $this->db_updates[$function]
			);
		}

		$response = array(
			'upgrades' => $upgrades,
			'message' => __( 'The following database updates are needed for Validated Field to function correctly.', 'acf_vf' ),
			'action' => __( 'Upgrade', 'acf_vf' )
		);
		$this->to_json_response( $response );
	}

	public function do_upgrade()
	{
		$upgrade = isset( $_REQUEST['upgrade'] )? $_REQUEST['upgrade'] : '';
		$response = array();
		if ( method_exists( __CLASS__, $upgrade ) ) {
			$response[] = call_user_func( array( $this, $upgrade ) );
		} else {
			header( 'HTTP/1.1 500 Server Error' );
			die( sprintf( __( 'Error performing upgrade %1$2!', 'acf_vf' ), $upgrade ) );
		}

		$this->to_json_response( $response );
	}

	public function upgrade_1()
	{
		global $wpdb, $acf_vf;

		$messages = array();
		$db_fields = $this->get_acf_fields();
		foreach ( $db_fields as $db_field ) {
			$field = get_field_object( $db_field->field_key );
			// If this field value is an array....
			if ( is_array( $field['value'] ) ) {
				// Update the meta data helpers
				$acf_vf->update_metadata_helpers( $field['value'], $db_field->post_id, $field );
				// Load the post
				$post = get_post( $db_field->post_id );
				// Let everyone know we fixed things
				$messages[] = array(
					'text' => sprintf( __( 'Updated values for field %1$s, post %2$s.', 'acf_vf' ), $field['label'], $post->post_title )
				);
			}
		}

		$response = array(
			'messages' => $messages,
			'id' => __FUNCTION__
		);

		$this->increment_version( __FUNCTION__ );

		$this->to_json_response( $response );
	}

	public function upgrade_2()
	{
		$messages = array();
		$db_fields = $this->get_acf_fields();
		foreach ( $db_fields as $db_field ) {
			$field = get_field_object( $db_field->field_key );
			if ( $field['type'] == 'validated_field' ) {
				$update = false;
				if ( empty( $field['read_only'] ) || $field['read_only'] == 'false' ) {
					$field['read_only'] = 'no';
					$update = true;
				} elseif ( $field['read_only'] == 'true' ) {
					$field['read_only'] = 'yes';
					$update = true;
				}
				if ( $update ) {
					acf_update_field( $field );
					$messages[] = array(
						'text' => sprintf( __( 'Updated read-only settings for field %1$s.', 'acf_vf' ), $field['label'] )
					);
				}
			}
		}

		if ( empty( $messages ) ) {

		}
		$response = array(
			'messages' => $messages,
			'id' => __FUNCTION__
		);

		$this->increment_version( __FUNCTION__ );

		$this->to_json_response( $response );
	}

	private function get_acf_fields()
	{
		global $wpdb;		
		$sql = <<<SQL
			SELECT post_id, SUBSTRING( meta_key, 2 ) AS meta_key, meta_value AS field_key
			FROM $wpdb->postmeta field 
			WHERE 
			field.meta_key LIKE '_%' 
			AND field.meta_value like 'field_%';
SQL;
		return $wpdb->get_results( $sql );
	}

	private function get_version_key()
	{
		return "acf_vf_db_version_v{$this->acf_version}";
	}

	private function increment_version( $function )
	{
		$version = ( int ) preg_replace( '~[^0-9]~', '', $function );
		$this->db_version = $version;
		update_option( $this->get_version_key(), $this->db_version );
	}
}
new acf_vf_updates();
endif;