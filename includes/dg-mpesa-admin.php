<?php
defined( 'ABSPATH' ) || exit;

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
		wp_enqueue_script( 'dg_mpesa_tailwind',  'https://cdn.tailwindcss.com', [], null, false );
		wp_enqueue_script( 'dg_mpesa_chartjs',   'https://cdn.jsdelivr.net/npm/chart.js', [], null, false );
		wp_enqueue_style(  'dg_mpesa_jost',      'https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap', [], '1.0' );
		wp_enqueue_style(  'dg_mpesa_admin_css', DG_MPESA_PLUGIN_URL . 'assets/css/admin-styles.css', [ 'dashicons', 'dg_mpesa_jost' ], DG_MPESA_VERSION );
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

		$page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$limit = 20;
		$rows  = $this->queries->list_rows( $limit, ( $page - 1 ) * $limit );
		$pages = (int) ceil( $this->queries->row_count() / $limit );

		$dates    = array_column( $chart, 'd' );
		$revenues = array_column( $chart, 'rev' );

		?>
		<div class="wrap dg-admin-dashboard" style="font-family:'Jost',sans-serif;">
			<div class="bg-white rounded-lg shadow-sm p-6 mb-6 mt-4">

				<!-- Header -->
				<div class="flex justify-between items-center mb-6">
					<div class="flex items-center">
						<img src="<?php echo esc_url( DG_MPESA_PLUGIN_URL . 'assets/img/mpesa-logo.png' ); ?>" alt="M-Pesa" class="h-10 mr-4">
						<h1 class="text-2xl font-bold text-gray-800 m-0">M-Pesa Analytics Dashboard</h1>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dg_mpesa_settings' ) ); ?>"
					   class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md transition duration-200">
						Settings Guide
					</a>
				</div>

				<!-- KPI Cards -->
				<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
					<div class="bg-green-50 p-6 rounded-lg border border-green-100">
						<h3 class="text-green-800 text-sm font-semibold uppercase tracking-wider mb-2">Total Revenue</h3>
						<div class="text-3xl font-bold text-green-900"><?php echo wc_price( $kpi['revenue'] ); ?></div>
						<p class="text-green-600 text-sm mt-1">Lifetime M-Pesa Sales</p>
					</div>
					<div class="bg-blue-50 p-6 rounded-lg border border-blue-100">
						<h3 class="text-blue-800 text-sm font-semibold uppercase tracking-wider mb-2">Total Transactions</h3>
						<div class="text-3xl font-bold text-blue-900"><?php echo number_format_i18n( $kpi['total'] ); ?></div>
						<p class="text-blue-600 text-sm mt-1">Completed &amp; Failed</p>
					</div>
					<div class="bg-purple-50 p-6 rounded-lg border border-purple-100">
						<h3 class="text-purple-800 text-sm font-semibold uppercase tracking-wider mb-2">Success Rate</h3>
						<div class="text-3xl font-bold text-purple-900"><?php echo $kpi['rate']; ?>%</div>
						<p class="text-purple-600 text-sm mt-1">Completion Rate</p>
					</div>
				</div>

				<!-- Chart -->
				<div class="bg-white border rounded-lg p-6 mb-8">
					<h3 class="text-lg font-bold text-gray-800 mb-4">Revenue — Last 30 Days</h3>
					<canvas id="dgMpesaChart" height="100"></canvas>
				</div>

				<!-- Transactions table -->
				<div class="bg-white border rounded-lg overflow-hidden">
					<div class="px-6 py-4 border-b bg-gray-50">
						<h3 class="text-lg font-bold text-gray-800 m-0">Transaction Log</h3>
					</div>
					<div class="overflow-x-auto">
						<table class="w-full text-left border-collapse">
							<thead>
								<tr>
									<?php foreach ( [ 'Date', 'Order', 'Phone', 'Amount', 'M-Pesa ID', 'Status' ] as $col ) : ?>
										<th class="px-6 py-3 bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo esc_html( $col ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody class="divide-y divide-gray-200">
								<?php if ( ! empty( $rows ) ) : ?>
									<?php foreach ( $rows as $t ) :
										$colour = match ( $t['status'] ) {
											'completed' => 'bg-green-100 text-green-800',
											'failed'    => 'bg-red-100 text-red-800',
											'pending'   => 'bg-yellow-100 text-yellow-800',
											default     => 'bg-gray-100 text-gray-800',
										};
									?>
									<tr class="hover:bg-gray-50">
										<td class="px-6 py-4 text-sm text-gray-600"><?php echo esc_html( $t['date_created'] ); ?></td>
										<td class="px-6 py-4 text-sm font-medium">
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $t['order_id'] . '&action=edit' ) ); ?>" class="text-blue-600 hover:underline">#<?php echo absint( $t['order_id'] ); ?></a>
										</td>
										<td class="px-6 py-4 text-sm text-gray-600"><?php echo esc_html( $t['phone_number'] ); ?></td>
										<td class="px-6 py-4 text-sm text-gray-600"><?php echo wc_price( $t['amount'] ); ?></td>
										<td class="px-6 py-4 text-sm text-gray-500 font-mono"><?php echo esc_html( $t['transaction_id'] ?: '—' ); ?></td>
										<td class="px-6 py-4">
											<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo esc_attr( $colour ); ?>">
												<?php echo esc_html( ucfirst( $t['status'] ) ); ?>
											</span>
										</td>
									</tr>
									<?php endforeach; ?>
								<?php else : ?>
									<tr><td colspan="6" class="px-6 py-4 text-center text-gray-500">No transactions yet.</td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Pagination -->
				<?php if ( $pages > 1 ) : ?>
				<div class="mt-4 flex justify-center gap-1">
					<?php for ( $i = 1; $i <= $pages; $i++ ) :
						$cls = $i === $page
							? 'bg-blue-50 text-blue-600 border-blue-500'
							: 'bg-white text-gray-500 hover:bg-gray-50 border-gray-300';
					?>
					<a href="<?php echo esc_url( add_query_arg( 'paged', $i, admin_url( 'admin.php?page=dg_mpesa_main' ) ) ); ?>"
					   class="px-4 py-2 border text-sm font-medium <?php echo esc_attr( $cls ); ?>"><?php echo $i; ?></a>
					<?php endfor; ?>
				</div>
				<?php endif; ?>

			</div>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function () {
			new Chart(document.getElementById('dgMpesaChart').getContext('2d'), {
				type: 'line',
				data: {
					labels: <?php echo wp_json_encode( $dates ); ?>,
					datasets: [{
						label: 'Revenue (KES)',
						data: <?php echo wp_json_encode( $revenues ); ?>,
						backgroundColor: 'rgba(16,185,129,0.2)',
						borderColor:     'rgba(16,185,129,1)',
						borderWidth: 2,
						tension: 0.3,
						fill: true
					}]
				},
				options: { responsive: true, scales: { y: { beginAtZero: true } } }
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
