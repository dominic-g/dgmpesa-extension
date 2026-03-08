<?php
defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Free helper: returns extra CSS for the analytics dashboard.
// Kept outside the class so wp_add_inline_style() can reference it simply.
// ---------------------------------------------------------------------------

function dg_mpesa_analytics_inline_css() {
	return '
.dg-kpi-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin-bottom:28px; }
@media (max-width:768px){ .dg-kpi-grid{ grid-template-columns:1fr; } }
';
}

// ---------------------------------------------------------------------------
// Inline helper: transaction query utilities (formerly a standalone class)
// ---------------------------------------------------------------------------

/**
 * DG_Mpesa_Tx_Queries
 *
 * Lightweight query helper embedded within the admin file.
 * Handles all reads from the custom transactions table.
 */
class DG_Mpesa_Tx_Queries {

	private $tbl;

	public function __construct() {
		global $wpdb;
		$this->tbl = $wpdb->prefix . 'dg_mpesa_transactions';
	}

	/** KPI summary: revenue, total count, success rate. */
	public function summary() {
		global $wpdb;
		$revenue     = (float) ( $wpdb->get_var( "SELECT SUM(amount) FROM {$this->tbl} WHERE status='completed'" ) ?? 0 );
		$total       = (int)   ( $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tbl}" ) ?? 0 );
		$successful  = (int)   ( $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tbl} WHERE status='completed'" ) ?? 0 );
		$rate        = $total > 0 ? round( ( $successful / $total ) * 100, 1 ) : 0;

		return compact( 'revenue', 'total', 'successful', 'rate' );
	}

	/** 30-day daily revenue + volume for chart. */
	public function chart_series() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT DATE(date_created) AS d,
			        COUNT(*) AS cnt,
			        SUM(CASE WHEN status='completed' THEN amount ELSE 0 END) AS rev
			   FROM {$this->tbl}
			  WHERE date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
			  GROUP BY DATE(date_created)
			  ORDER BY d ASC",
			ARRAY_A
		);
	}

	/** Paginated list of transactions, newest first. */
	public function list_rows( $limit = 20, $offset = 0 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->tbl} ORDER BY date_created DESC LIMIT %d OFFSET %d",
				$limit, $offset
			),
			ARRAY_A
		);
	}

	/** Total row count (for pagination maths). */
	public function row_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tbl}" );
	}
}

// ---------------------------------------------------------------------------
// Admin panel
// ---------------------------------------------------------------------------

/**
 * DG_Mpesa_Admin_Panel
 *
 * Registers the plugin's admin menu pages and renders both the
 * Tailwind-based analytics dashboard and the settings guide.
 */
class DG_Mpesa_Admin_Panel {

	/** @var string Hook suffix for main page. */
	private $menu_hook   = false;
	/** @var string Hook suffix for settings sub-page. */
	private $config_hook = false;

	/** @var DG_Mpesa_Tx_Queries */
	private $queries;

	public function __construct() {
		$this->queries = new DG_Mpesa_Tx_Queries();

		add_action( 'admin_menu',    [ $this, 'register_pages' ] );
		add_action( 'admin_notices', [ $this, 'mail_disabled_notice' ] );
	}

	// -----------------------------------------------------------------------
	// Menu registration
	// -----------------------------------------------------------------------

	public function register_pages() {
		$this->menu_hook = add_menu_page(
			__( 'M-Pesa Analytics', 'dg-checkout-for-m-pesa' ),
			__( 'Lipa na Mpesa', 'dg-checkout-for-m-pesa' ),
			'manage_options',
			'dg_mpesa_main',
			[ $this, 'render_analytics' ],
			'dashicons-money-alt',
			54.5
		);

		$this->config_hook = add_submenu_page(
			'dg_mpesa_main',
			__( 'Settings Guide', 'dg-checkout-for-m-pesa' ),
			__( 'Settings Guide', 'dg-checkout-for-m-pesa' ),
			'manage_options',
			'dg_mpesa_settings',
			[ $this, 'render_config_guide' ]
		);

		add_action( 'admin_print_styles-' . $this->menu_hook,   [ $this, 'load_assets' ] );
		add_action( 'admin_print_styles-' . $this->config_hook, [ $this, 'load_assets' ] );
	}

	// -----------------------------------------------------------------------
	// Asset loading
	// -----------------------------------------------------------------------

	public function load_assets() {
		wp_enqueue_script( 'dg_mpesa_chartjs',   DG_MPESA_PLUGIN_URL . 'assets/js/chart.min.js', [], '4.4.3', true );
		wp_enqueue_style(  'dg_mpesa_jost',      'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap', [], '1.0' );
		wp_enqueue_style(  'dg_mpesa_admin_css', DG_MPESA_PLUGIN_URL . 'assets/css/admin-styles.css', [ 'dashicons', 'dg_mpesa_jost' ], DG_MPESA_VERSION );
		wp_add_inline_style( 'dg_mpesa_admin_css', dg_mpesa_analytics_inline_css() );
	}

	// -----------------------------------------------------------------------
	// Notices
	// -----------------------------------------------------------------------

	public function mail_disabled_notice() {
		if ( function_exists( 'mail' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$our_pages = array_filter( [ $this->menu_hook, $this->config_hook ] );
		$on_wc_tab = (
			'woocommerce_page_wc-settings' === $screen->id &&
			( $_GET['tab'] ?? '' ) === 'checkout' &&
			( ! isset( $_GET['section'] ) || 'dg_mpesa_checkout' === ( $_GET['section'] ?? '' ) )
		);

		if ( ! in_array( $screen->id, $our_pages, true ) && ! $on_wc_tab ) {
			return;
		}

		$search_url = esc_url( admin_url( 'plugin-install.php?s=smtp&tab=search&type=term' ) );
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			wp_kses_post( sprintf(
				__( '<strong>Warning:</strong> The PHP <code>mail()</code> function is disabled. Install an <a href="%s" target="_blank">SMTP plugin</a> to ensure order emails are delivered.', 'dg-checkout-for-m-pesa' ),
				$search_url
			) )
		);
	}

	// -----------------------------------------------------------------------
	// Analytics dashboard
	// -----------------------------------------------------------------------

	public function render_analytics() {
		$kpi   = $this->queries->summary();
		$chart = $this->queries->chart_series();

		$page  = max( 1, (int) ( isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ) );
		$limit = 20;
		$rows  = $this->queries->list_rows( $limit, ( $page - 1 ) * $limit );
		$pages = (int) ceil( $this->queries->row_count() / $limit );

		$dates    = array_column( $chart, 'd' );
		$revenues = array_column( $chart, 'rev' );

		?>
		<div class="wrap dg-admin-dashboard">
			<div class="dg-analytics-wrap">
			<div class="dg-analytics-inner">

				<!-- Header -->
				<div class="dg-analytics-header">
					<div style="display:flex;align-items:center;">
						<img src="<?php echo esc_url( DG_MPESA_PLUGIN_URL . 'assets/img/mpesa-logo.png' ); ?>" alt="M-Pesa">
						<h1><?php esc_html_e( 'M-Pesa Analytics Dashboard', 'dg-checkout-for-m-pesa' ); ?></h1>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dg_mpesa_settings' ) ); ?>"><?php esc_html_e( 'Settings Guide', 'dg-checkout-for-m-pesa' ); ?></a>
				</div>

				<!-- KPI Cards -->
				<div class="dg-kpi-grid">
					<div class="dg-kpi-card green">
						<h3><?php esc_html_e( 'Total Revenue', 'dg-checkout-for-m-pesa' ); ?></h3>
						<div class="dg-kpi-value"><?php echo wc_price( $kpi['revenue'] ); ?></div>
						<p class="dg-kpi-label"><?php esc_html_e( 'Lifetime M-Pesa Sales', 'dg-checkout-for-m-pesa' ); ?></p>
					</div>
					<div class="dg-kpi-card blue">
						<h3><?php esc_html_e( 'Total Transactions', 'dg-checkout-for-m-pesa' ); ?></h3>
						<div class="dg-kpi-value"><?php echo number_format_i18n( $kpi['total'] ); ?></div>
						<p class="dg-kpi-label"><?php esc_html_e( 'Completed & Failed', 'dg-checkout-for-m-pesa' ); ?></p>
					</div>
					<div class="dg-kpi-card purple">
						<h3><?php esc_html_e( 'Success Rate', 'dg-checkout-for-m-pesa' ); ?></h3>
						<div class="dg-kpi-value"><?php echo esc_html( $kpi['rate'] ); ?>%</div>
						<p class="dg-kpi-label"><?php esc_html_e( 'Completion Rate', 'dg-checkout-for-m-pesa' ); ?></p>
					</div>
				</div>

				<!-- Chart -->
				<div class="dg-chart-box">
					<h3><?php esc_html_e( 'Revenue — Last 30 Days', 'dg-checkout-for-m-pesa' ); ?></h3>
					<canvas id="dgMpesaChart" height="100"></canvas>
				</div>

				<!-- Transactions table -->
				<div class="dg-table-box">
					<div class="dg-table-header">
						<h3><?php esc_html_e( 'Transaction Log', 'dg-checkout-for-m-pesa' ); ?></h3>
					</div>
					<table class="dg-tx-table">
						<thead>
							<tr>
								<?php foreach ( [ 'Date', 'Order', 'Phone', 'Amount', 'M-Pesa ID', 'Status' ] as $col ) : ?>
									<th><?php echo esc_html( $col ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $rows ) ) : ?>
								<?php foreach ( $rows as $t ) :
									if ( 'completed' === $t['status'] ) {
										$badge = 'dg-status-completed';
									} elseif ( 'failed' === $t['status'] ) {
										$badge = 'dg-status-failed';
									} elseif ( 'pending' === $t['status'] ) {
										$badge = 'dg-status-pending';
									} else {
										$badge = 'dg-status-default';
									}
								?>
								<tr>
									<td><?php echo esc_html( $t['date_created'] ); ?></td>
									<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $t['order_id'] ) . '&action=edit' ) ); ?>">#<?php echo absint( $t['order_id'] ); ?></a></td>
									<td><?php echo esc_html( $t['phone_number'] ); ?></td>
									<td><?php echo wc_price( $t['amount'] ); ?></td>
									<td class="mono"><?php echo esc_html( $t['transaction_id'] ? $t['transaction_id'] : '—' ); ?></td>
									<td><span class="dg-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( ucfirst( $t['status'] ) ); ?></span></td>
								</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr><td colspan="6" class="empty"><?php esc_html_e( 'No transactions yet.', 'dg-checkout-for-m-pesa' ); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<!-- Pagination -->
				<?php if ( $pages > 1 ) : ?>
				<div class="dg-pagination">
					<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'paged', $i, admin_url( 'admin.php?page=dg_mpesa_main' ) ) ); ?>"
					   class="<?php echo $i === $page ? 'current' : ''; ?>"><?php echo absint( $i ); ?></a>
					<?php endfor; ?>
				</div>
				<?php endif; ?>

			</div><!-- .dg-analytics-inner -->
			</div><!-- .dg-analytics-wrap -->
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var ctx = document.getElementById('dgMpesaChart');
			if ( ! ctx ) { return; }
			new Chart(ctx.getContext('2d'), {
				type: 'line',
				data: {
					labels: <?php echo wp_json_encode( $dates ); ?>,
					datasets: [{
						label: 'Revenue (KES)',
						data: <?php echo wp_json_encode( $revenues ); ?>,
						backgroundColor: 'rgba(16,185,129,0.15)',
						borderColor:     '#10b981',
						borderWidth: 2,
						tension: 0.3,
						fill: true,
						pointBackgroundColor: '#10b981'
					}]
				},
				options: {
					responsive: true,
					plugins: { legend: { display: false } },
					scales: { y: { beginAtZero: true } }
				}
			});
		});
		</script>
		<?php
	}




	// -----------------------------------------------------------------------
	// Settings guide
	// -----------------------------------------------------------------------

	public function render_config_guide() {
		$wc_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=dg_mpesa_checkout' );
		$dev_url = 'https://developer.safaricom.co.ke/user/me/apps';

		ob_start();
		?>
		<div class="dg-settings-container-minimal">
			<div class="dg-settings-header-minimal">
				<h2><?php esc_html_e( 'M-Pesa Configuration Guide', 'dg-checkout-for-m-pesa' ); ?></h2>
				<p><?php esc_html_e( 'Follow these steps to start accepting M-Pesa payments.', 'dg-checkout-for-m-pesa' ); ?></p>
			</div>

			<?php
			$steps = [
				[ 'dashicons-admin-settings', 'Step 1: Go to M-Pesa Settings',
					sprintf( __( 'Navigate to <a href="%s" target="_blank">WooCommerce → Payments → M-Pesa</a>.', 'dg-checkout-for-m-pesa' ), esc_url( $wc_url ) ) ],
				[ 'dashicons-toggle-on', 'Step 2: Enable & Pick Environment',
					__( 'Tick "Enable M-Pesa Payment", then choose <strong>Sandbox</strong> for testing or <strong>Live</strong> for real payments.', 'dg-checkout-for-m-pesa' ) ],
				[ 'dashicons-admin-network', 'Step 3: Enter API Credentials',
					sprintf( __( 'Copy your Consumer Key, Secret, Short Code and Passkey from the <a href="%s" target="_blank">Safaricom Developer Portal</a>.', 'dg-checkout-for-m-pesa' ), esc_url( $dev_url ) ) ],
				[ 'dashicons-admin-links', 'Step 4: Confirm Callback URL',
					__( 'The callback URL is auto-generated. Your site must use HTTPS.', 'dg-checkout-for-m-pesa' ) ],
				[ 'dashicons-saved', 'Step 5: Save and Test',
					__( 'Click "Save changes" then run a sandbox transaction to verify everything works.', 'dg-checkout-for-m-pesa' ) ],
			];
			foreach ( $steps as [ $icon, $title, $body ] ) :
			?>
			<div class="dg-settings-card-minimal">
				<h3><span class="dashicons <?php echo esc_attr( $icon ); ?>"></span> <?php echo esc_html( $title ); ?></h3>
				<p><?php echo wp_kses_post( $body ); ?></p>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
		$content = ob_get_clean();
		$this->scaffold_page( __( 'Settings Guide', 'dg-checkout-for-m-pesa' ), $content, 'dg-settings-page' );
	}

	// -----------------------------------------------------------------------
	// Shared page shell (for non-dashboard pages)
	// -----------------------------------------------------------------------

	private function scaffold_page( $title, $content, $extra_class = '' ) {
		ob_start();
		do_action( 'all_admin_notices' );
		$notices = ob_get_clean();
		?>
		<div class="wrap">
			<?php echo $notices; // safe, already generated by WP actions ?>
			<div class="dg-admin-wrap <?php echo esc_attr( $extra_class ); ?>">
				<div class="dg-admin-header">
					<img src="<?php echo esc_url( DG_MPESA_PLUGIN_URL . 'assets/img/mpesa-logo.png' ); ?>" alt="M-Pesa Logo" class="dg-header-logo">
					<h1><?php echo esc_html( $title ); ?></h1>
					<span class="dg-version-tag"><?php esc_html_e( 'Free Version', 'dg-checkout-for-m-pesa' ); ?></span>
				</div>
				<div class="dg-admin-content">
					<?php echo wp_kses_post( $content ); ?>
				</div>
				<div class="dg-admin-footer">
					<?php esc_html_e( 'Thank you for using M-Pesa Checkout.', 'dg-checkout-for-m-pesa' ); ?>
				</div>
			</div>
		</div>
		<?php
	}
}
