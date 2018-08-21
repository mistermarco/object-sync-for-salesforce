<?php
/**
 * Class file for the Object_Sync_Sf_Queue class. Extend the WP_Queue\Job class for the purposes of Object Sync for Salesforce.
 *
 * @file
 */

if ( ! class_exists( 'Object_Sync_Salesforce' ) ) {
	die();
}

class Object_Sync_Sf_Queue {

	/**
	 * Singleton
	 *
	 * @var Queue|null
	 */
	protected static $instance = null;

	protected $wpdb;
	protected $version;
	protected $slug;
	protected $schedulable_classes;

	public $attempts;
	public $interval;

	/**
	 * Singleton
	 *
	 * @return Queue|null
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Queue constructor.
	 */
	public function __construct( $wpdb, $version, $slug, $schedulable_classes ) {
		//add_filter( 'update_post_metadata', array( $this, 'filter_update_post_metadata' ), 10, 5 );
		$this->wpdb                = $wpdb;
		$this->version             = $version;
		$this->slug                = $slug;
		$this->schedulable_classes = $schedulable_classes;

		$this->attempts = apply_filters( 'object_sync_for_salesforce_job_attempts', 3 );
		$this->interval = apply_filters( 'object_sync_for_salesforce_cron_interval', 1 );

		add_action( 'plugins_loaded', array( $this, 'add_actions' ) );

	}

	public function add_actions() {
		if ( ! class_exists( 'Salesforce_Queue_Job' ) && file_exists( plugin_dir_path( __FILE__ ) . '../vendor/autoload.php' ) ) {
			require_once plugin_dir_path( __FILE__ ) . '../vendor/autoload.php';
			require_once plugin_dir_path( __FILE__ ) . '../classes/salesforce_queue_job.php';
		}
		wp_queue()->cron( $this->attempts, $this->interval );
		$this->set_schedule_frequency();
	}

	public function set_schedule_frequency( $schedules = array() ) {
		// create an option in the core schedules array for each one the plugin defines
		foreach ( $this->schedulable_classes as $key => $value ) {
			$schedule_number = absint( get_option( 'object_sync_for_salesforce_' . $key . '_schedule_number', '' ) );
			$schedule_unit   = get_option( 'object_sync_for_salesforce_' . $key . '_schedule_unit', '' );

			switch ( $schedule_unit ) {
				case 'minutes':
					$seconds = 60;
					break;
				case 'hours':
					$seconds = 3600;
					break;
				case 'days':
					$seconds = 86400;
					break;
				default:
					$seconds = 0;
			}

			$key = $schedule_unit . '_' . $schedule_number;

			$schedules[ $key ] = array(
				'interval' => $seconds * $schedule_number,
				'display'  => 'Every ' . $schedule_number . ' ' . $schedule_unit,
			);

			$this->schedule_frequency = $key;

		}

		return $schedules;
	}

	public function get_schedule_frequency_key( $name = '' ) {

		$schedule_number = get_option( 'object_sync_for_salesforce_' . $name . '_schedule_number', '' );
		$schedule_unit   = get_option( 'object_sync_for_salesforce_' . $name . '_schedule_unit', '' );

		switch ( $schedule_unit ) {
			case 'minutes':
				$seconds = 60;
				break;
			case 'hours':
				$seconds = 3600;
				break;
			case 'days':
				$seconds = 86400;
				break;
			default:
				$seconds = 0;
		}

		$key = $schedule_unit . '_' . $schedule_number;

		return $key;

	}

	/**
	* Convert the schedule frequency from the admin settings into seconds
	*
	*/
	public function get_schedule_frequency_seconds( $name = '' ) {

		$schedule_number = get_option( 'object_sync_for_salesforce_' . $name . '_schedule_number', '' );
		$schedule_unit   = get_option( 'object_sync_for_salesforce_' . $name . '_schedule_unit', '' );

		switch ( $schedule_unit ) {
			case 'minutes':
				$seconds = 60;
				break;
			case 'hours':
				$seconds = 3600;
				break;
			case 'days':
				$seconds = 86400;
				break;
			default:
				$seconds = 0;
		}

		$total = $seconds * $schedule_number;

		return $total;

	}

	public function save_to_queue( $data, $direction ) {
		wp_queue()->push( new Salesforce_Queue_Job( $data, $direction ) );
	}

	/**
	 * Filter the post meta data for backup sizes
	 *
	 * Unfortunately WordPress core is lacking hooks in its image resizing functions so we are reduced
	 * to this hackery to detect when images are resized and previous versions are relegated to backup sizes.
	 *
	 * @param bool   $check
	 * @param int    $object_id
	 * @param string $meta_key
	 * @param mixed  $meta_value
	 * @param mixed  $prev_value
	 * @return bool
	 */
	public function filter_update_post_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		if ( '_wp_attachment_backup_sizes' !== $meta_key ) {
			return $check;
		}

		$current_value = get_post_meta( $object_id, $meta_key, true );

		if ( ! $current_value ) {
			$current_value = array();
		}

		$diff = array_diff_key( $meta_value, $current_value );

		if ( ! $diff ) {
			return $check;
		}

		$key = key( $diff );
		$suffix = substr( $key, strrpos( $key, '-' ) + 1 );

		$image_meta = self::get_image_meta( $object_id );

		foreach ( $image_meta['sizes'] as $size_name => $size ) {
			if ( 0 !== strpos( $size_name, 'ipq-' ) ) {
				continue;
			}

			$meta_value[ $size_name . '-' . $suffix ] = $size;
			unset( $image_meta['sizes'][ $size_name ] );
		}

		if ( ! $this->is_updating_backup_sizes ) {
			$this->is_updating_backup_sizes = true;
			update_post_meta( $object_id, '_wp_attachment_backup_sizes', $meta_value );
			wp_update_attachment_metadata( $object_id, $image_meta );
			return true;
		}

		$this->is_updating_backup_sizes = false;

		return $check;
	}

	/**
	 * Check if the image sizes exist and push them to the queue if not.
	 *
	 * @param int   $post_id
	 * @param array $sizes
	 */
	protected function process_image( $post_id, $sizes ) {
		if ( self::is_attachment_locked( $post_id ) ) {
			return;
		}

		$lock_attachment = false;

		foreach ( $sizes as $size ) {
			if ( self::does_size_already_exist_for_image( $post_id, $size ) ) {
				continue;
			}

			if ( self::is_size_larger_than_original( $post_id, $size ) ) {
				continue;
			}

			$item = array(
				'post_id' => $post_id,
				'width'   => $size[0],
				'height'  => $size[1],
				'crop'    => $size[2],
			);

			wp_queue()->push( new Resize_Job( $item ) );

			$lock_attachment = true;
		}

		if ( $lock_attachment ) {
			self::lock_attachment( $post_id );
		}
	}

	/**
	 * Get image HTML for a specific context in a theme, specifying the exact sizes
	 * for the image. The first image size is always used as the `src` and the other
	 * sizes are used in the `srcset` if they're the same aspect ratio as the original
	 * image. If any of the image sizes don't currently exist, they are queued for
	 * creation by a background process. Example:
	 *
	 * echo ipq_get_theme_image( 1353, array(
	 *         array( 600, 400, false ),
	 *         array( 1280, 720, false ),
	 *         array( 1600, 1067, false ),
	 *     ),
	 * 	   array(
	 *         'class' => 'header-banner'
	 *     )
	 * );
	 *
	 * @param int    $post_id Image attachment ID.
	 * @param array  $sizes   Array of arrays of sizes in the format array(width,height,crop).
	 * @param string $attr    Optional. Attributes for the image markup. Default empty.
	 * @return string HTML img element or empty string on failure.
	 */
	public function get_image( $post_id, $sizes, $attr = '' ) {
		$this->process_image( $post_id, $sizes );

		return wp_get_attachment_image( $post_id, array( $sizes[0][0], $sizes[0][1] ), false, $attr );
	}

	/**
	 * Get image URL for a specific context in a theme, specifying the exact size
	 * for the image. If the image size does not currently exist, it is queued for
	 * creation by a background process. Example:
	 *
	 * echo ipq_get_theme_image_url( 1353, array( 600, 400, false ) );
	 *
	 * @param int   $post_id
	 * @param array $size
	 *
	 * @return string
	 */
	public function get_image_url( $post_id, $size ) {
		$this->process_image( $post_id, array( $size ) );

		$size = self::get_size_name( $size );
		$src  = wp_get_attachment_image_src( $post_id, $size );

		if ( isset( $src[0] ) ) {
			return $src[0];
		}

		return '';
	}

	/**
	 * Get array index name for image size.
	 *
	 * @param array $size array in format array(width,height,crop).
	 * @return string Image size name.
	 */
	public static function get_size_name( $size ) {
		$crop = $size[2] ? 'true' : 'false';
		return 'ipq-' . $size[0] . 'x' . $size[1] . '-' . $crop;
	}

	/**
	 * Get an image's file path.
	 *
	 * @param int $post_id ID of the image post.
	 * @return false|string
	 */
	public static function get_image_path( $post_id ) {
		return get_attached_file( $post_id );
	}

	/**
	 * Get an image's post meta data.
	 *
	 * @param int $post_id ID of the image post.
	 * @return mixed Post meta field. False on failure.
	 */
	public static function get_image_meta( $post_id ) {
		return wp_get_attachment_metadata( $post_id );
	}

	/**
	 * Update meta data for an image
	 *
	 * @param int   $post_id Image ID.
	 * @param array $data    Image data.
	 * @return bool|int False if $post is invalid.
	 */
	public static function update_image_meta( $post_id, $data ) {
		return wp_update_attachment_metadata( $post_id, $data );
	}

	/**
	 * Checks if an image size already exists for an image
	 *
	 * @param int   $post_id Image ID.
	 * @param array $size array in format array(width,height,crop).
	 * @return bool
	 */
	public static function does_size_already_exist_for_image( $post_id, $size ) {
		$image_meta = self::get_image_meta( $post_id );
		$size_name  = self::get_size_name( $size );

		return isset( $image_meta['sizes'][ $size_name ] );
	}

	/**
	 * Check if an image size is larger than the original.
	 *
	 * @param int   $post_id Image ID.
	 * @param array $size array in format array(width,height,crop).
	 *
	 * @return bool
	 */
	public static function is_size_larger_than_original( $post_id, $size ) {
		$image_meta = self::get_image_meta( $post_id );

		if ( ! isset( $image_meta['width'] ) || ! isset( $image_meta['height'] ) ) {
			return true;
		}

		if ( $size[0] > $image_meta['width'] || $size[1] > $image_meta['height'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Is an attachment locked?
	 *
	 * @param int $post_id
	 *
	 * @return bool
	 */
	public static function is_attachment_locked( $post_id ) {
		$image_meta = self::get_image_meta( $post_id );

		if ( isset( $image_meta['ipq_locked'] ) && $image_meta['ipq_locked'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Lock an attachment to prevent multiple queue jobs being created.
	 *
	 * @param int $post_id
	 */
	public static function lock_attachment( $post_id ) {
		$image_meta = self::get_image_meta( $post_id );

		$image_meta['ipq_locked'] = true;

		wp_update_attachment_metadata( $post_id, $image_meta );
	}
}
