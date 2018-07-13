<?php
/**
 * Plugin Name:       	GravityView - Gravity Forms Entry Revisions
 * Plugin URI:        	https://gravityview.co/extensions/entry-revisions/
 * Description:       	Track changes to Gravity Forms entries and restore from previous revisions. Requires Gravity Forms 2.0 or higher.
 * Version:          	1.0
 * Author:            	GravityView
 * Author URI:        	https://gravityview.co
 * Text Domain:       	gravityview-entry-revisions
 * License:           	GPLv2 or later
 * License URI: 		http://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path:			/languages
 */

/**
 * Class GV_Entry_Revisions
 * @todo revision date merge tag
 */
class GV_Entry_Revisions {

	/**
	 * @var string The storage key used in entry meta storage
	 * @since 1.0
	 * @see gform_update_meta()
	 * @see gform_get_meta()
	 */
	private static $meta_key = 'gv_revision_details';

	/**
	 * Instantiate the class
	 * @since 1.0
	 */
	public static function load() {
		if( ! did_action( 'gv_entry_revisions_loaded' ) ) {
			new self;
			do_action( 'gv_entry_revisions_loaded' );
		}
	}

	/**
	 * Load translations!
	 */
	function load_textdomain() {
        load_plugin_textdomain( 'gravityview-entry-revisions', false, dirname( plugin_basename(__FILE__) ) . '/languages' );
	}

	/**
	 * GV_Entry_Revisions constructor.
	 * @since 1.0
	 */
	private function __construct() {
		$this->add_hooks();
	}

	/**
	 * Add hooks on the single entry screen
	 * @since 1.0
	 */
	private function add_hooks() {

		add_action( 'gform_after_update_entry', array( $this, 'save' ), 10, 3 );

		add_filter( 'gform_entry_meta', array( $this, 'modify_gform_entry_meta' ) );

		$this->maybe_add_entry_detail_hooks();
	}

	/**
     * Returns the additional entry meta details added by this plugin
     *
	 * @return array
	 */
	private function get_entry_meta() {

		$meta = array(
			'gv_revision_parent_id' => array(
				'label'             => __( 'Revision Parent Entry ID' ),
				'is_numeric'        => true,
				'is_default_column' => false,
			),
			'gv_revision_date' => array(
				'label'             => __( 'Revision Parent Date' ),
				'is_numeric'        => true,
				'is_default_column' => false,
			),
			'gv_revision_date_gmt' => array(
				'label'             => __( 'Revision Date (GMT)' ),
				'is_numeric'        => true,
				'is_default_column' => false,
			),
			'gv_revision_user_id' => array(
				'label'             => __( 'Revision Created By' ),
				'is_numeric'        => true,
				'is_default_column' => false,
			),
			'gv_revision_changed' => array(
				'label'             => __( 'Revision Changed Content' ),
				'is_numeric'        => false,
				'is_default_column' => false,
			)
		);

		return $meta;
	}

	/**
     * Updates Gravity Forms to fetch revisions with other entry details
     *
	 * @param array $meta
	 *
	 * @return array
	 */
	public function modify_gform_entry_meta( $meta = array() ) {
		return $this->get_entry_meta() + $meta;
    }

	/**
	 * Hooks only run on the Entry Detail page in WP Admin
	 *
	 * @since 1.0
	 *
	 * @return void
	 */
	private function maybe_add_entry_detail_hooks() {

		// We only run the rest of the hooks on the entry detail page
		if( 'entry_detail' !== GFForms::get_page() ) {
		    return;
		}

		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'add_meta_box' ) );

		add_action( 'admin_init', array( $this, 'admin_init_restore_listener' ) );

		// If showing a revision, get rid of all metaboxes and lingering HTML stuff
		if( isset( $_GET['revision'] ) ) {
			add_action( 'gform_entry_detail_sidebar_before', array( $this, 'start_ob_start' ) );
			add_action( 'gform_entry_detail_content_before', array( $this, 'start_ob_start' ) );

			add_action( 'gform_entry_detail', array( $this, 'end_ob_start' ) );
			add_action( 'gform_entry_detail_sidebar_after', array( $this, 'end_ob_start' ) );
		}
	}

	/**
	 * Alias for ob_start(), since output buffering and actions don't get along
	 * @since 1.0
	 * @return void
	 */
	public function start_ob_start() {
		ob_start();
	}

	/**
	 * Alias for ob_clean(), since output buffering and actions don't get along
	 * @since 1.0
	 * @return void
	 */
	public function end_ob_start() {
		ob_clean();
	}

	/**
	 * Fires after the Entry is updated from the entry detail page.
	 *
	 * @since 1.0
	 *
	 * @param array   $form           The form object for the entry.
	 * @param integer $lead['id']     The entry ID.
	 * @param array   $original_entry The entry object before being updated.
	 *
	 * @return void
	 */
	public function save( $form = array(), $entry_id = 0, $original_entry = array() ) {
		$this->add_revision( $entry_id, $original_entry );
	}

	/**
	 * Adds a revision for an entry
	 *
	 * @since 1.0
	 *
	 * @param int|array $entry_or_entry_id Current entry ID or current entry array
	 * @param array $revision_to_add Previous entry data to add as a revision
	 *
	 * @return bool false: Nothing changed; true: updated
	 */
	private function add_revision( $entry_or_entry_id = 0, $revision_to_add = array() ) {

		$current_entry = $entry_or_entry_id;

		if( ! is_array( $entry_or_entry_id ) && is_numeric( $entry_or_entry_id ) ) {
			$current_entry = GFAPI::get_entry( $entry_or_entry_id );
		}

		if ( is_wp_error( $current_entry ) ) {
			GFCommon::log_debug( __METHOD__ .': Entry not found at ID #' . $entry_or_entry_id );
            return false;
		}

		if ( ! is_array( $current_entry ) ) {
			return false;
		}

		// Find the fields that changed
		$changed_fields = $this->get_modified_entry_fields( $revision_to_add, $current_entry );

		// Nothing changed
		if( empty( $changed_fields ) ) {
			GFCommon::log_debug( __METHOD__ .': Not adding revision; no fields changed.' );
			return false;
		}

		$revision_to_add['status'] = 'gv-revision';

		$revision_id = GFAPI::add_entry( $revision_to_add );

		$revision_meta = array(
            'gv_revision_parent_id' => $current_entry['id'],
			'gv_revision_date'      => current_time( 'timestamp', 0 ),
			'gv_revision_date_gmt'  => current_time( 'timestamp', 1 ),
			'gv_revision_user_id'   => get_current_user_id(),
			'gv_revision_changed'   => $changed_fields,
		);

		foreach ( $revision_meta as $key => $value ) {
			gform_update_meta( $revision_id, $key, $value );
		}

		GFAPI::update_entry_property( $current_entry['id'], 'date_updated', gmdate( 'Y-m-d H:i:s' ) );

		return true;
	}


	/**
	 * Compares old entry array to new, return array of differences with the values of the new entry
	 *
	 * @param array $old
	 * @param array $new
	 *
	 * @return array array of differences, with keys preserved
	 */
	private function get_modified_entry_fields( $old = array(), $new = array() ) {

		$return = $new;

		foreach( $old as $key => $old_value ) {
			// Gravity Forms itself uses == comparison
			if( rgar( $new, $key ) == $old_value ) {
				unset( $return[ $key ] );
			}
		}

		return $return;
	}

	/**
     * Returns an entry revision by ID
     *
	 * @param int $revision_id
	 *
	 * @return array|WP_Error
	 */
	public function get_revision( $revision_id ) {
		return GFAPI::get_entry( $revision_id );
    }

	/**
	 * Get all revisions connected to an entry
	 *
	 * @since 1.0 
	 * 
	 * @param int $entry_id
     * @param string $return "all" or "ids"
	 *
	 * @return array Empty array if none found. Array if found
	 */
	public function get_revisions( $entry_id = 0, $return = 'all' ) {

		$search_criteria = array(
			'field_filters' => array(
				array(
					'key' => 'gv_revision_parent_id',
					'value' => $entry_id
				),
			)
		);


        if( 'all' === $return ) {
		    // TODO: Add filter for page size
	        $revisions = GFAPI::get_entries( 0, $search_criteria, array(), array( 'offset' => 0, 'page_size' => 200 ) );
        } else {
	        $revisions = GFAPI::get_entry_ids( 0, $search_criteria, array(), array( 'offset' => 0, 'page_size' => 0 ) );
        }

		return $revisions;
	}

	/**
	 * Get the latest revision
	 *
	 * @param $entry_id
	 *
	 * @return array Empty array, if no revisions exist. Otherwise, last revision.
	 */
	public function get_last_revision( $entry_id ) {
		
		$revisions = $this->get_revisions( $entry_id );

		if ( empty( $revisions ) ) {
			return array();
		}

		$revision = array_pop( $revisions );
		
		return $revision;
	}

	/**
	 * Deletes all revisions for an entry
	 *
	 * @since 1.0
	 *
	 * @param int $entry_id ID of the entry to remove revsions
     *
     * @return array $deleted Keys are entry IDs; value is 1 if deleted, WP_Error if not
	 */
	private function delete_revisions( $entry_id = 0 ) {

		$revision_ids = $this->get_revisions( $entry_id, 'ids' );

		$deleted = array();
		foreach ( $revision_ids as $revision_id ) {

		    $success = GFAPI::delete_entry( $revision_id );

			if ( is_wp_error( $success ) ) {
                $deleted[ $revision_id ] = $success;
			} else {
				$deleted[ $revision_id ] = 1;
            }
		}

		return $deleted;
	}

	/**
	 * Remove a revision from an entry
	 *
	 * @since 1.0
	 *
	 * @param int $entry_id
	 * @param int $revision_id Revision GMT timestamp
	 *
	 * return bool|WP_Error WP_Error if revision isn't found or submissions blocked; true if revision deleted
	 */
	private function delete_revision( $revision_id = 0 ) {
		return GFAPI::delete_entry( $revision_id );
	}

	/**
	 * Restores an entry to a specific revision, if the revision is found
	 *
	 * @param int $entry_id ID of entry
	 * @param int $revision_id ID of revision (GMT timestamp)
	 *
	 * @return bool|WP_Error WP_Error if there was an error during restore. true if success; false if failure
	 */
	public function restore_revision( $entry_id = 0, $revision_id = 0 ) {

		$revision = GFAPI::get_entry( $revision_id );

		// Revision has already been deleted or does not exist
		if( empty( $revision ) || is_wp_error( $revision ) ) {
			return new WP_Error( 'not_found', __( 'Revision not found', 'gravityview-entry-revisions' ), array( 'entry_id' => $entry_id, 'revision_id' => $revision_id ) );
		}

		$current_entry = GFAPI::get_entry( $entry_id );

		/**
		 * @param bool $restore_entry_meta Whether to restore entry meta as well as field values. Default: false
		 */
		if( false === apply_filters( 'gravityview/entry-revisions/restore-entry-meta', false ) ) {

			// Override revision details with current entry details
			foreach ( $current_entry as $key => $value ) {
				if ( ! is_numeric( $key ) ) {
					$revision[ $key ] = $value;
				}
			}
		}

		// Remove all hooks
		remove_all_filters( 'gform_entry_pre_update' );
		remove_all_filters( 'gform_form_pre_update_entry' );
		remove_all_filters( sprintf( 'gform_form_pre_update_entry_%s', $revision['form_id'] ) );
		remove_all_actions( 'gform_post_update_entry' );
		remove_all_actions( sprintf( 'gform_post_update_entry_%s', $revision['form_id'] ) );

		$entry_meta = $this->get_entry_meta();

		foreach( $entry_meta as $key => $value ) {
			// Remove the entry key data
			unset( $revision[ $key ] );
		}

		$updated_result = GFAPI::update_entry( $revision, $entry_id );

		if ( is_wp_error( $updated_result ) ) {

			/** @var WP_Error $updated_result */
			GFCommon::log_error( $updated_result->get_error_message() );

			return $updated_result;

		} else {

			// Store the current entry as a revision, too, so you can revert
			$this->add_revision( $entry_id, $current_entry );

			/**
			 * Should the revision be removed after it has been restored? Default: false
			 * @param bool $remove_after_restore [Default: false]
			 */
			if( apply_filters( 'gravityview/entry-revisions/delete-after-restore', false ) ) {
				return $this->delete_revision( $revision_id );
			}

			return true;
		}
	}

	/**
	 * Restores an entry
	 *
	 * @since 1.0
	 *
	 * @return void Redirects to single entry view after completion
	 */
	public function admin_init_restore_listener() {

		if( ! rgget('restore') || ! rgget('view') || ! rgget( 'lid' ) ) {
			return;
		}

        // No access!
        if( ! GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
            GFCommon::log_error( 'Restoring the entry revision failed: user does not have the "gravityforms_edit_entries" capability.' );
            return;
        }

        $revision_id = rgget( 'restore' );
        $entry_id = rgget( 'lid' );
        $nonce = rgget( '_wpnonce' );
        $nonce_action = $this->generate_restore_nonce_action( $entry_id, $revision_id );
        $valid = wp_verify_nonce( $nonce, $nonce_action );

        // Nonce didn't validate
        if( ! $valid ) {
            GFCommon::log_error( 'Restoring the entry revision failed: nonce validation failed.' );
            return;
        }

        // Handle restoring the entry
        $this->restore_revision( $entry_id, $revision_id );

        wp_safe_redirect( remove_query_arg( 'restore' ) );
        exit();
	}

	/**
	 * Allow custom meta boxes to be added to the entry detail page.
	 *
	 * @since 1.0
	 *
	 * @param array $meta_boxes The properties for the meta boxes.
	 * @param array $entry The entry currently being viewed/edited.
	 * @param array $form The form object used to process the current entry.
	 * 
	 * @return array $meta_boxes, with the Versions box added
	 */
	public function add_meta_box( $meta_boxes = array(), $entry = array(), $form = array() ) {

		$revision_id = rgget('revision');

		if( ! empty( $revision_id )  ) {
			$meta_boxes = array();
			$meta_boxes[ self::$meta_key ] = array(
				'title'    => esc_html__( 'Restore Entry Revision', 'gravityview-entry-revisions' ),
				'callback' => array( $this, 'meta_box_restore_revision' ),
				'context'  => 'normal',
			);
		} else {
			$meta_boxes[ self::$meta_key ] = array(
				'title'    => esc_html__( 'Entry Revisions', 'gravityview-entry-revisions' ),
				'callback' => array( $this, 'meta_box_entry_revisions' ),
				'context'  => 'normal',
			);
		}

		return $meta_boxes;
	}


	/**
	 * Gets an array of diff table output comparing two entries
	 *
	 * @uses wp_text_diff()
	 *
	 * @param array $previous Previous entry
	 * @param array $current Current entry
	 * @param array $form Entry form
	 *
	 * @return array Array of diff output generated by wp_text_diff()
	 */
	private function get_diff( $previous = array(), $current = array(), $form = array() ) {

		$return = array();

		$entry_meta = array_keys( $this->get_entry_meta() );

		foreach ( $previous as $key => $previous_value ) {

			// Don't compare `gv_revision` data
			if( in_array( $key, $entry_meta ) ) {
				continue;
			}

			$current_value = rgar( $current, $key );

			$field = GFFormsModel::get_field( $form, $key );

			if( ! $field ) {
				continue;
			}

			$label = GFCommon::get_label( $field );

			$diff = wp_text_diff( $previous_value, $current_value, array(
				'show_split_view' => 1,
				'title' => sprintf( esc_html__( '%s (Field %s)', 'gravityview-entry-revisions' ), $label, $key ),
				'title_left' => esc_html__( 'Entry Revision', 'gravityview-entry-revisions' ),
				'title_right' => esc_html__( 'Current Entry', 'gravityview-entry-revisions' ),
			) );

			/**
			 * Fix the issue when using 'title_left' and 'title_right' of TWO extra blank <td></td>s being added. We only want one.
			 * @see wp_text_diff()
			 */
			$diff = str_replace( "<tr class='diff-sub-title'>\n\t<td></td>", "<tr class='diff-sub-title'>\n\t", $diff );

			if ( $diff ) {
				$return[ $key ] = $diff;
			}
		}

		return $return;
	}

	/**
	 * Display entry content comparison and restore button
	 *
	 * @since 1.0
	 *
	 * @param array $data Array with entry/form/mode keys.
	 *
	 * @return void
	 */
	public function meta_box_restore_revision( $data = array() ) {
		
		$entry = rgar( $data, 'entry' );
		$revision = $this->get_revision( rgget( 'revision') );

		if( is_wp_error( $revision ) ) {
		    echo '<h3>' . esc_html__( 'This revision no longer exists.', 'gravityview-entry-revisions' ) . '</h3>';
			?><a href="<?php echo esc_url( remove_query_arg( 'revision' ) ); ?>" class="button button-primary button-large"><?php esc_html_e( 'Return to Entry', 'gravityview-entry-revisions' ); ?></a><?php
		    return;
        }

		$output = $this->get_diff_output( $entry, $revision );

		if ( is_wp_error( $output ) ) {
            return;
		}

		echo $output;
		?>

		<hr />

		<p class="wp-clearfix">
			<a href="<?php echo $this->get_restore_url( $revision ); ?>" class="button button-primary button-hero alignleft" onclick="return confirm('<?php esc_attr_e( 'Are you sure? The Current Entry data will be replaced with the Entry Revision data shown.' ) ?>');"><?php esc_html_e( 'Restore This Entry Revision' ); ?></a>
			<a href="<?php echo esc_url( remove_query_arg( 'revision' ) ); ?>" class="button button-secondary button-hero alignright"><?php esc_html_e( 'Cancel: Keep Current Entry' ); ?></a>
		</p>
	<?php
	}

	private function get_diff_output( array $entry, array $revision ) {

		$diff_output = '';
		$form = GFAPI::get_form( $entry['form_id'] );
		$diffs = $this->get_diff( $revision, $entry, $form );

		if ( empty( $diffs ) ) {
		    return new WP_Error( 'identical', esc_html__( 'This revision is identical to the current entry.', 'gravityview-entry-revisions' ) );
		}

		$diff_output .= wpautop( $this->revision_title( $revision, false, esc_html__( 'The entry revision was created by %2$s, %3$s ago (%4$s).', 'gravityview-entry-revisions' ) ) );

		$diff_output .= '<hr />';

		$diff_output .= '<style>
		table.diff {
			margin-top: 1em;
		}
		table.diff .diff-title th {
			font-weight: normal;
			text-transform: uppercase;
		}
		table.diff .diff-title th {
			font-size: 18px;
			padding-top: 10px;
		}
		table.diff .diff-deletedline { 
			background-color: #edf3ff;
			 border:  1px solid #dcdcdc;
		}
		table.diff .diff-addedline { 
			background-color: #f7fff7; 
			border:  1px solid #ccc;
		}
		 </style>';

		foreach ( $diffs as $diff ) {
			$diff_output .= $diff;
		}

		return $diff_output;
    }

	/**
	 * Generate a nonce action to secure the restoring process
	 *
	 * @since 1.0
	 *
	 * @param int $entry_id
	 * @param int $revision_id
	 *
	 * @return string
	 */
	private function generate_restore_nonce_action( $entry_id = 0, $revision_id = 0 ) {
		return sprintf( 'gv-restore-entry-%d-revision-%d', intval( $entry_id ), intval( $revision_id ), 'gv-restore-entry' );
	}

	/**
	 * Returns nonce URL to restore a revision
	 *
	 * @param array $revision Revision entry array
	 *
	 * @return string
	 */
	private function get_restore_url( $revision = array() ) {

		$nonce_action = $this->generate_restore_nonce_action( $revision['gv_revision_parent_id'], $revision['id'] );

		return wp_nonce_url( add_query_arg( array( 'restore' => $revision['id'] ), remove_query_arg( 'revision' ) ), $nonce_action );
	}

	/**
	 * Retrieve formatted date timestamp of a revision (linked to that revision details page).
	 *
	 * @since 1.0
	 *
	 * @see wp_post_revision_title() for inspiration
	 *
	 * @param array $revision Revision entry array
	 * @param bool       $link     Optional, default is true. Link to revision details page?
	 * @param string $format post revision title: 1: author avatar, 2: author name, 3: time ago, 4: date
	 *
	 * @return string HTML of the revision version
	 */
	private function revision_title( $revision, $link = true, $format = '%1$s %2$s, %3$s ago (%4$s)' ) {

		$revision_user_id = rgar( $revision, 'gv_revision_user_id' );

		$author = get_the_author_meta( 'display_name', $revision_user_id );
		/* translators: revision date format, see http://php.net/date */
		$datef = _x( 'F j, Y @ H:i:s', 'revision date format' );

		$gravatar = get_avatar( $revision_user_id, 32 );
		$date = date_i18n( $datef, $revision['gv_revision_date'] );

		// TODO: Permissions check
		if ( $link ) {
			$link = esc_url( add_query_arg( array( 'revision' => $revision['id'] ) ) );
			$date = "<a href='$link'>$date</a>";
		}

		$revision_date_author = sprintf(
			$format,
			$gravatar,
			$author,
			human_time_diff( $revision['gv_revision_date_gmt'], current_time( 'timestamp', true ) ),
			$date
		);

		return $revision_date_author;
	}

	/**
	 * Display the meta box for the list of revisions
	 *
	 * @since 1.0
	 *
	 * @param array $data Array of data with entry, form, mode keys
	 *
	 * @return void
	 */
	public function meta_box_entry_revisions( $data ) {

		$entry_id = rgars( $data, 'entry/id' );

		echo $this->get_entry_revisions( $entry_id, array( 'container_css' =>  'post-revisions' ) );
	}

	/**
     * Render the entry revisions
     *
     * @since 1.1
     *
	 * @param int $entry_id
	 * @param array $entry
	 * @param array $form
	 */
	public function get_entry_revisions( $entry_id = 0, $atts = array() ) {

	    $atts = wp_parse_args( $atts, array(
	       'container_css' => 'gv-entry-revisions',
           'wpautop'       => 1,
           'format'        => __( '%1$s %2$s, %3$s ago (%4$s)' ),
           'strings'       => array(
	           'no_revisions' => __( 'This entry has no revisions.', 'gravityview-entry-revisions' ),
	           'not_found'    => __( 'Revision not found', 'gravityview-entry-revisions' ),
           )
        ));

		$entry = GFAPI::get_entry( $entry_id );

	    if ( ! $entry || is_wp_error( $entry ) ) {

		    $output = esc_html( $atts['strings']['not_found'] );

	    } else {

		    $form = GFAPI::get_form( $entry['form_id'] );

		    $revisions     = $this->get_revisions( $entry_id );
		    $container_css = esc_attr( $atts['container_css'] );

		    if ( empty( $revisions ) ) {
			    $output = esc_html( $atts['strings']['no_revisions'] );
		    } else {

			    $rows = '';
			    foreach ( $revisions as $revision ) {
				    $diffs = $this->get_diff( $revision, $entry, $form );

				    // Only show if there are differences
				    if ( ! empty( $diffs ) ) {
					    $rows .= "\t<li>" . $this->revision_title( $revision, true, $atts['format'] ) . "</li>\n";
				    }
			    }

			    $output = "<ul class='{$container_css}'>\n" . $rows . "</ul>";
		    }
	    }

		if ( $atts['wpautop'] ) {
			$output = wpautop( $output );
		}

		/**
		 * Modify the output of the revisions
		 */
		$output = apply_filters( 'gravityview/entry-revisions/output', $output, $entry );

		return $output;
    }
}

add_action( 'plugins_loaded', array( 'GV_Entry_Revisions', 'load_textdomain') );
add_action( 'gform_loaded', array( 'GV_Entry_Revisions', 'load' ) );