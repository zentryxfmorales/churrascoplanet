<?php
/**
 * Custom Dashboard Widgets Class
 *
 * Manages the WordPress dashboard by removing default widgets
 * and adding custom widgets tailored for the store management.
 *
 * @package Client_Admin_Portal
 * @since   1.0.0
 */

// Security check
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CAP_Dashboard
 *
 * Customizes the WordPress dashboard for store managers.
 *
 * @since 1.0.0
 */
class CAP_Dashboard {

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Constructor is empty as hooks are registered by the Loader
	}

	/**
	 * Remove default WordPress dashboard widgets
	 *
	 * Cleans up the dashboard by removing widgets that are not
	 * relevant for store managers.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function remove_default_widgets(): void {
		// Only remove for restricted users
		if ( ! CAP_Loader::is_restricted_user() ) {
			return;
		}

		global $wp_meta_boxes;

		// ====================================================================
		// REMOVE WORDPRESS CORE WIDGETS
		// ====================================================================

		// Welcome panel
		remove_action( 'welcome_panel', 'wp_welcome_panel' );

		// Dashboard widgets
		remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );       // At a Glance
		remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );        // Activity
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );       // Quick Draft
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );           // WordPress Events and News
		remove_meta_box( 'dashboard_secondary', 'dashboard', 'side' );         // Secondary (legacy)
		remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );     // Site Health
		remove_meta_box( 'dashboard_php_nag', 'dashboard', 'normal' );         // PHP Version Nag

		// ====================================================================
		// REMOVE WOOCOMMERCE WIDGETS
		// ====================================================================

		// WooCommerce Status widget
		remove_meta_box( 'woocommerce_dashboard_status', 'dashboard', 'normal' );

		// WooCommerce Reviews widget
		remove_meta_box( 'woocommerce_dashboard_recent_reviews', 'dashboard', 'normal' );

		// WooCommerce Setup widget (if still present)
		remove_meta_box( 'wc_admin_dashboard_setup', 'dashboard', 'normal' );

		// ====================================================================
		// REMOVE OTHER PLUGIN WIDGETS
		// ====================================================================

		// Jetpack (if installed)
		remove_meta_box( 'jetpack_summary_widget', 'dashboard', 'normal' );

		// Yoast SEO (if installed)
		remove_meta_box( 'wpseo-dashboard-overview', 'dashboard', 'normal' );

		// Remove any other widgets that might appear
		remove_meta_box( 'dashboard_incoming_links', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_plugins', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_recent_drafts', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_browser_nag', 'dashboard', 'normal' );
	}

	/**
	 * Add custom dashboard widgets
	 *
	 * Registers our custom widgets for store management.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_custom_widgets(): void {
		// Only add for restricted users (shop managers)
		// Administrators get the default widgets
		if ( ! CAP_Loader::is_restricted_user() ) {
			return;
		}

		// Store Status & Sales Widget (Main)
		wp_add_dashboard_widget(
			'cap_store_status_widget',
			__( 'Store Status & Sales', 'client-admin-portal' ),
			array( $this, 'render_store_status_widget' ),
			null,
			null,
			'normal',
			'high'
		);

		// Quick Actions Widget
		wp_add_dashboard_widget(
			'cap_quick_actions_widget',
			__( 'Quick Actions', 'client-admin-portal' ),
			array( $this, 'render_quick_actions_widget' ),
			null,
			null,
			'side',
			'high'
		);

		// Recent Orders Widget
		wp_add_dashboard_widget(
			'cap_recent_orders_widget',
			__( 'Recent Orders', 'client-admin-portal' ),
			array( $this, 'render_recent_orders_widget' ),
			null,
			null,
			'normal',
			'default'
		);
	}

	/**
	 * Render the Store Status & Sales widget
	 *
	 * Displays key metrics: orders processing, today's sales, store status.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_store_status_widget(): void {
		// Check if WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<p>' . esc_html__( 'WooCommerce is required for this widget.', 'client-admin-portal' ) . '</p>';
			return;
		}

		// Get metrics
		$processing_orders = $this->get_processing_orders_count();
		$today_sales       = $this->get_today_sales();
		$store_status      = $this->get_store_status();

		?>
		<div class="cap-store-status-widget">
			<!-- Store Status Badge -->
			<div class="cap-store-status-header">
				<div class="cap-status-badge cap-status-<?php echo esc_attr( $store_status['class'] ); ?>">
					<span class="cap-status-indicator"></span>
					<span class="cap-status-text"><?php echo esc_html( $store_status['text'] ); ?></span>
				</div>
				<span class="cap-status-time"><?php echo esc_html( current_time( 'H:i' ) ); ?></span>
			</div>

			<!-- Metrics Grid -->
			<div class="cap-metrics-grid">
				<!-- Processing Orders -->
				<div class="cap-metric-card cap-metric-orders">
					<div class="cap-metric-icon">
						<span class="dashicons dashicons-cart"></span>
					</div>
					<div class="cap-metric-content">
						<span class="cap-metric-value"><?php echo esc_html( $processing_orders ); ?></span>
						<span class="cap-metric-label"><?php esc_html_e( 'Orders Processing', 'client-admin-portal' ); ?></span>
					</div>
					<a href="<?php echo esc_url( cap_wc_orders_admin_url( 'wc-processing' ) ); ?>"
					   class="cap-metric-link">
						<?php esc_html_e( 'View', 'client-admin-portal' ); ?> →
					</a>
				</div>

				<!-- Today's Sales -->
				<div class="cap-metric-card cap-metric-sales">
					<div class="cap-metric-icon">
						<span class="dashicons dashicons-chart-bar"></span>
					</div>
					<div class="cap-metric-content">
						<span class="cap-metric-value"><?php echo wp_kses_post( $today_sales['formatted'] ); ?></span>
						<span class="cap-metric-label"><?php esc_html_e( 'Sales Today', 'client-admin-portal' ); ?></span>
					</div>
					<span class="cap-metric-count">
						<?php
						printf(
							/* translators: %d: number of orders */
							esc_html__( '%d orders', 'client-admin-portal' ),
							$today_sales['count']
						);
						?>
					</span>
				</div>

				<!-- Pending Payment -->
				<div class="cap-metric-card cap-metric-pending">
					<div class="cap-metric-icon">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="cap-metric-content">
						<span class="cap-metric-value"><?php echo esc_html( $this->get_pending_orders_count() ); ?></span>
						<span class="cap-metric-label"><?php esc_html_e( 'Pending Payment', 'client-admin-portal' ); ?></span>
					</div>
					<a href="<?php echo esc_url( cap_wc_orders_admin_url( 'wc-pending' ) ); ?>"
					   class="cap-metric-link">
						<?php esc_html_e( 'View', 'client-admin-portal' ); ?> →
					</a>
				</div>

				<!-- Completed Today -->
				<div class="cap-metric-card cap-metric-completed">
					<div class="cap-metric-icon">
						<span class="dashicons dashicons-yes-alt"></span>
					</div>
					<div class="cap-metric-content">
						<span class="cap-metric-value"><?php echo esc_html( $this->get_completed_today_count() ); ?></span>
						<span class="cap-metric-label"><?php esc_html_e( 'Completed Today', 'client-admin-portal' ); ?></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Quick Actions widget
	 *
	 * Provides shortcuts to common actions.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_quick_actions_widget(): void {
		?>
		<div class="cap-quick-actions-widget">
			<a href="<?php echo esc_url( cap_wc_orders_admin_url() ); ?>" class="cap-action-btn">
				<span class="dashicons dashicons-clipboard"></span>
				<span><?php esc_html_e( 'All Orders', 'client-admin-portal' ); ?></span>
			</a>

			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>" class="cap-action-btn">
				<span class="dashicons dashicons-plus-alt"></span>
				<span><?php esc_html_e( 'Add Product', 'client-admin-portal' ); ?></span>
			</a>

			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" class="cap-action-btn">
				<span class="dashicons dashicons-archive"></span>
				<span><?php esc_html_e( 'All Products', 'client-admin-portal' ); ?></span>
			</a>

			<a href="<?php echo esc_url( cap_wc_orders_admin_url( 'wc-processing' ) ); ?>" class="cap-action-btn cap-action-highlight">
				<span class="dashicons dashicons-update"></span>
				<span><?php esc_html_e( 'Processing Orders', 'client-admin-portal' ); ?></span>
			</a>
		</div>
		<?php
	}

	/**
	 * Render the Recent Orders widget
	 *
	 * Shows the latest orders at a glance.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_recent_orders_widget(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<p>' . esc_html__( 'WooCommerce is required for this widget.', 'client-admin-portal' ) . '</p>';
			return;
		}

		// Get recent orders
		$orders = wc_get_orders( array(
			'limit'   => 5,
			'orderby' => 'date',
			'order'   => 'DESC',
		) );

		if ( empty( $orders ) ) {
			echo '<p class="cap-no-orders">' . esc_html__( 'No orders yet.', 'client-admin-portal' ) . '</p>';
			return;
		}

		?>
		<div class="cap-recent-orders-widget">
			<table class="cap-orders-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'client-admin-portal' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'client-admin-portal' ); ?></th>
						<th><?php esc_html_e( 'Status', 'client-admin-portal' ); ?></th>
						<th><?php esc_html_e( 'Total', 'client-admin-portal' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orders as $order ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
									#<?php echo esc_html( $order->get_order_number() ); ?>
								</a>
							</td>
							<td>
								<?php echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?>
							</td>
							<td>
								<span class="cap-order-status cap-status-<?php echo esc_attr( $order->get_status() ); ?>">
									<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
								</span>
							</td>
							<td>
								<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<a href="<?php echo esc_url( cap_wc_orders_admin_url() ); ?>" class="cap-view-all-link">
				<?php esc_html_e( 'View All Orders', 'client-admin-portal' ); ?> →
			</a>
		</div>
		<?php
	}

	/**
	 * Get count of orders with 'processing' status
	 *
	 * @since 1.0.0
	 * @return int Number of processing orders.
	 */
	private function get_processing_orders_count(): int {
		if ( ! function_exists( 'wc_orders_count' ) ) {
			return 0;
		}

		return wc_orders_count( 'processing' );
	}

	/**
	 * Get count of orders with 'pending' status
	 *
	 * @since 1.0.0
	 * @return int Number of pending orders.
	 */
	private function get_pending_orders_count(): int {
		if ( ! function_exists( 'wc_orders_count' ) ) {
			return 0;
		}

		return wc_orders_count( 'pending' );
	}

	/**
	 * Get count of orders completed today
	 *
	 * @since 1.0.0
	 * @return int Number of completed orders today.
	 */
	private function get_completed_today_count(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$orders = wc_get_orders( array(
			'status'       => 'completed',
			'date_created' => '>=' . strtotime( 'today midnight' ),
			'return'       => 'ids',
		) );

		return count( $orders );
	}

	/**
	 * Get today's sales data
	 *
	 * Calculates total sales amount for today.
	 *
	 * @since 1.0.0
	 * @return array Array with 'total', 'formatted', and 'count' keys.
	 */
	private function get_today_sales(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'total'     => 0,
				'formatted' => wc_price( 0 ),
				'count'     => 0,
			);
		}

		// Get orders from today with completed or processing status
		$orders = wc_get_orders( array(
			'status'       => array( 'completed', 'processing' ),
			'date_created' => '>=' . strtotime( 'today midnight' ),
		) );

		$total = 0;
		$count = count( $orders );

		foreach ( $orders as $order ) {
			$total += (float) $order->get_total();
		}

		return array(
			'total'     => $total,
			'formatted' => wc_price( $total ),
			'count'     => $count,
		);
	}

	/**
	 * Get store status
	 *
	 * Determines if the store is currently open based on
	 * store hours configuration (from Uber Direct plugin or static).
	 *
	 * @since 1.0.0
	 * @return array Array with 'open' (bool), 'text', and 'class' keys.
	 */
	private function get_store_status(): array {
		// Check if Uber Direct Connect plugin has store hours
		$settings = get_option( 'wcudc_settings', array() );
		$hours    = $settings['store_hours'] ?? array();

		// If no hours configured, assume always open
		if ( empty( $hours ) ) {
			return array(
				'open'  => true,
				'text'  => __( 'Store Open', 'client-admin-portal' ),
				'class' => 'open',
			);
		}

		// Get current day and time
		$now  = current_time( 'timestamp' );
		$day  = strtolower( gmdate( 'l', $now ) );
		$time = gmdate( 'H:i', $now );

		// Map English day names to Spanish keys
		$day_map = array(
			'monday'    => 'lunes',
			'tuesday'   => 'martes',
			'wednesday' => 'miercoles',
			'thursday'  => 'jueves',
			'friday'    => 'viernes',
			'saturday'  => 'sabado',
			'sunday'    => 'domingo',
		);

		$day_key = $day_map[ $day ] ?? $day;

		// Check if closed today
		if ( ! empty( $hours[ $day_key ]['closed'] ) ) {
			return array(
				'open'  => false,
				'text'  => __( 'Store Closed', 'client-admin-portal' ),
				'class' => 'closed',
			);
		}

		// Check hours
		if ( ! empty( $hours[ $day_key ]['open'] ) && ! empty( $hours[ $day_key ]['close'] ) ) {
			$open  = $hours[ $day_key ]['open'];
			$close = $hours[ $day_key ]['close'];

			if ( $time >= $open && $time <= $close ) {
				return array(
					'open'  => true,
					'text'  => __( 'Store Open', 'client-admin-portal' ),
					'class' => 'open',
				);
			} else {
				return array(
					'open'  => false,
					'text'  => __( 'Store Closed', 'client-admin-portal' ),
					'class' => 'closed',
				);
			}
		}

		// Default to open if can't determine
		return array(
			'open'  => true,
			'text'  => __( 'Store Open', 'client-admin-portal' ),
			'class' => 'open',
		);
	}
}
