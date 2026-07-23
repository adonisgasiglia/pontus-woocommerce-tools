<?php
/**
 * Checkout tracking and signed webhook delivery.
 *
 * @package PontusWooCommerceTools
 */

namespace Pontus\WooCommerceTools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks the checkout journey and delivers order events.
 */
final class Webhooks {

	private const OPTION_KEY       = 'pwt_webhook_settings';
	private const STATE_PREFIX     = 'pwt_checkout_';
	private const ORDER_META_ID    = '_pwt_checkout_id';
	private const META_CREATED_SENT = '_pwt_webhook_created_sent';
	private const META_PAID_SENT    = '_pwt_webhook_paid_sent';

	/**
	 * Singleton instance.
	 *
	 * @var Webhooks|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared module instance.
	 *
	 * @return Webhooks
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracking' ) );

		add_action( 'wp_ajax_pwt_track_checkout', array( $this, 'track_checkout' ) );
		add_action( 'wp_ajax_nopriv_pwt_track_checkout', array( $this, 'track_checkout' ) );

		add_action( 'pwt_deliver_webhook', array( $this, 'deliver_webhook' ), 10, 3 );
		add_action( 'pwt_check_checkout_abandonment', array( $this, 'check_abandonment' ), 10, 2 );

		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_checkout_id' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'order_created' ), 10, 3 );
		add_action( 'woocommerce_payment_complete', array( $this, 'order_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_paid' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'order_failed' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'order_cancelled' ) );
	}

	/**
	 * Adds the settings page below WooCommerce.
	 */
	public function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Webhooks Pontus', 'pontus-woocommerce-tools' ),
			__( 'Webhooks Pontus', 'pontus-woocommerce-tools' ),
			'manage_woocommerce',
			'pwt-webhooks',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Registers webhook settings.
	 */
	public function register_settings() {
		register_setting(
			'pwt_webhooks',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Sanitizes settings.
	 *
	 * @param array $settings Submitted settings.
	 * @return array
	 */
	public function sanitize_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		return array(
			'enabled'       => ! empty( $settings['enabled'] ) ? 'yes' : 'no',
			'url'           => isset( $settings['url'] ) ? esc_url_raw( trim( $settings['url'] ) ) : '',
			'secret'        => isset( $settings['secret'] ) ? sanitize_text_field( $settings['secret'] ) : '',
			'abandon_minutes' => isset( $settings['abandon_minutes'] )
				? max( 5, min( 10080, absint( $settings['abandon_minutes'] ) ) )
				: 30,
		);
	}

	/**
	 * Renders the admin configuration and inline reference.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = $this->get_settings();
		$example  = array(
			'event'       => 'order.paid',
			'delivery_id' => 'uuid',
			'occurred_at' => '2026-07-23T16:30:00-03:00',
			'data'        => array(
				'checkout_id' => 'pwt_example',
				'order_id'    => 1234,
				'status'      => 'processing',
				'customer'    => array(
					'name'  => 'Cliente Exemplo',
					'email' => 'cliente@exemplo.com',
					'phone' => '5521999999999',
					'cpf'   => '00000000000',
					'cnpj'  => '',
				),
				'items'       => array(
					array(
						'key'         => 'office',
						'name'        => 'Escritório Inteligente',
						'description' => 'Plano mensal',
						'quantity'    => 1,
						'unit_price'  => 189.00,
						'discount'    => 75.60,
						'final_price' => 113.40,
					),
				),
				'totals'      => array(
					'subtotal' => 189.00,
					'discount' => 75.60,
					'total'    => 113.40,
				),
				'coupons'     => array( 'ESCRITORIOOFF' ),
				'payment'     => array(
					'method'         => 'pix',
					'method_title'   => 'Pix',
					'status'         => 'paid',
					'transaction_id' => 'safe-provider-id',
				),
			),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Webhooks Pontus', 'pontus-woocommerce-tools' ); ?></h1>
			<p><?php esc_html_e( 'Envie a jornada do checkout, abandonos e pedidos ao n8n por uma única URL.', 'pontus-woocommerce-tools' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'pwt_webhooks' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Ativar webhooks', 'pontus-woocommerce-tools' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( 'yes', $settings['enabled'] ); ?>> <?php esc_html_e( 'Enviar eventos ao n8n', 'pontus-woocommerce-tools' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="pwt-webhook-url"><?php esc_html_e( 'URL do webhook', 'pontus-woocommerce-tools' ); ?></label></th>
						<td><input class="regular-text code" type="url" id="pwt-webhook-url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[url]" value="<?php echo esc_attr( $settings['url'] ); ?>" placeholder="https://n8n.exemplo.com/webhook/pontus"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pwt-webhook-secret"><?php esc_html_e( 'Chave secreta', 'pontus-woocommerce-tools' ); ?></label></th>
						<td>
							<input class="regular-text code" type="password" autocomplete="new-password" id="pwt-webhook-secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[secret]" value="<?php echo esc_attr( $settings['secret'] ); ?>">
							<p class="description"><?php esc_html_e( 'Usada para gerar o cabeçalho X-Pontus-Signature com HMAC SHA-256.', 'pontus-woocommerce-tools' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pwt-abandon-minutes"><?php esc_html_e( 'Prazo de abandono', 'pontus-woocommerce-tools' ); ?></label></th>
						<td>
							<input type="number" min="5" max="10080" id="pwt-abandon-minutes" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[abandon_minutes]" value="<?php echo esc_attr( $settings['abandon_minutes'] ); ?>"> <?php esc_html_e( 'minutos sem atividade ou pagamento confirmado', 'pontus-woocommerce-tools' ); ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<details style="max-width: 1100px; margin-top: 20px;">
				<summary style="cursor: pointer; font-size: 16px; font-weight: 600;"><?php esc_html_e( 'Consultar referência básica do webhook', 'pontus-woocommerce-tools' ); ?></summary>
				<div style="padding: 12px 4px;">
					<h2><?php esc_html_e( 'Eventos', 'pontus-woocommerce-tools' ); ?></h2>
					<ul>
						<li><code>checkout.started</code>: entrada no checkout.</li>
						<li><code>checkout.identification_completed</code>: avanço confirmado para Dados da Contratação.</li>
						<li><code>checkout.contract_data_completed</code>: avanço confirmado para Pagamento.</li>
						<li><code>checkout.payment_started</code>: entrada na etapa de pagamento.</li>
						<li><code>checkout.submission_started</code>: tentativa de finalizar o pedido.</li>
						<li><code>checkout.abandoned</code>: prazo sem pagamento confirmado.</li>
						<li><code>order.created</code>: pedido criado no WooCommerce.</li>
						<li><code>order.paid</code>: pagamento confirmado.</li>
						<li><code>order.payment_failed</code> e <code>order.cancelled</code>: falha ou cancelamento.</li>
					</ul>

					<h2><?php esc_html_e( 'Cabeçalhos', 'pontus-woocommerce-tools' ); ?></h2>
					<ul>
						<li><code>X-Pontus-Event</code>: nome do evento.</li>
						<li><code>X-Pontus-Delivery</code>: identificador único da entrega.</li>
						<li><code>X-Pontus-Timestamp</code>: timestamp Unix usado na assinatura.</li>
						<li><code>X-Pontus-Signature</code>: <code>sha256=HMAC(timestamp.corpo, chave)</code>.</li>
					</ul>

					<h2><?php esc_html_e( 'Itens contratuais', 'pontus-woocommerce-tools' ); ?></h2>
					<p><?php esc_html_e( 'O array items contém apenas os componentes comprados: office, phone e meetings. Cada item informa quantidade, valor original, desconto individual e valor final.', 'pontus-woocommerce-tools' ); ?></p>

					<h2><?php esc_html_e( 'Exemplo', 'pontus-woocommerce-tools' ); ?></h2>
					<pre style="background:#fff; border:1px solid #ccd0d4; padding:16px; overflow:auto;"><?php echo esc_html( wp_json_encode( $example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
					<p><strong><?php esc_html_e( 'Segurança:', 'pontus-woocommerce-tools' ); ?></strong> <?php esc_html_e( 'dados completos de cartão, CVV, senha e tokens do gateway nunca são enviados.', 'pontus-woocommerce-tools' ); ?></p>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Loads checkout tracking.
	 */
	public function enqueue_tracking() {
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_script(
			'pwt-checkout-tracking',
			PWT_PLUGIN_URL . 'assets/js/checkout-tracking.js',
			array(),
			PWT_VERSION,
			true
		);

		wp_localize_script(
			'pwt-checkout-tracking',
			'pwtCheckoutTracking',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pwt_track_checkout' ),
			)
		);
	}

	/**
	 * Receives a trusted same-origin checkout event.
	 */
	public function track_checkout() {
		check_ajax_referer( 'pwt_track_checkout', 'nonce' );

		$event = isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : '';
		$id    = isset( $_POST['checkout_id'] ) ? sanitize_key( wp_unslash( $_POST['checkout_id'] ) ) : '';
		$step  = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';

		$allowed = array(
			'checkout.started',
			'checkout.activity',
			'checkout.identification_completed',
			'checkout.contract_data_completed',
			'checkout.payment_started',
			'checkout.submission_started',
		);

		if ( ! in_array( $event, $allowed, true ) || ! preg_match( '/^pwt_[a-z0-9_-]{12,80}$/', $id ) ) {
			wp_send_json_error( array( 'message' => 'invalid_event' ), 400 );
		}

		$raw_fields = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '{}';
		$fields     = json_decode( $raw_fields, true );
		$fields     = is_array( $fields ) ? $this->sanitize_payload_array( $fields ) : array();

		$state                  = $this->get_state( $id );
		$state['checkout_id']   = $id;
		$state['last_step']     = $step;
		$state['last_activity'] = time();
		$state['fields']        = array_merge( isset( $state['fields'] ) ? $state['fields'] : array(), $fields );
		$state['sent_events']   = isset( $state['sent_events'] ) && is_array( $state['sent_events'] ) ? $state['sent_events'] : array();

		$this->save_state( $id, $state );
		$this->schedule_abandonment( $id, $state['last_activity'] );

		if ( 'checkout.activity' !== $event && empty( $state['sent_events'][ $event ] ) ) {
			$this->queue_event( $event, $this->build_checkout_payload( $state ) );
			$state['sent_events'][ $event ] = time();
			$this->save_state( $id, $state );
		}

		wp_send_json_success( array( 'checkout_id' => $id ) );
	}

	/**
	 * Stores checkout correlation on the order.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Checkout data.
	 */
	public function store_checkout_id( $order, $data ) {
		unset( $data );

		if ( ! $order instanceof \WC_Order || empty( $_POST['pwt_checkout_id'] ) ) {
			return;
		}

		$id = sanitize_key( wp_unslash( $_POST['pwt_checkout_id'] ) );
		if ( preg_match( '/^pwt_[a-z0-9_-]{12,80}$/', $id ) ) {
			$order->update_meta_data( self::ORDER_META_ID, $id );
		}
	}

	/**
	 * Sends order.created.
	 *
	 * @param int      $order_id   Order ID.
	 * @param array    $posted_data Posted checkout data.
	 * @param WC_Order $order       Order object.
	 */
	public function order_created( $order_id, $posted_data, $order ) {
		unset( $posted_data );

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || 'yes' === $order->get_meta( self::META_CREATED_SENT, true ) ) {
			return;
		}

		$id = (string) $order->get_meta( self::ORDER_META_ID, true );
		$this->attach_order_to_state( $id, $order );

		$this->queue_event( 'order.created', $this->build_order_payload( $order ) );
		$order->update_meta_data( self::META_CREATED_SENT, 'yes' );
		$order->save();
	}

	/**
	 * Sends order.paid once.
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() || 'yes' === $order->get_meta( self::META_PAID_SENT, true ) ) {
			return;
		}

		$id = (string) $order->get_meta( self::ORDER_META_ID, true );
		if ( $id ) {
			$state         = $this->get_state( $id );
			$state['paid'] = true;
			$state['order_id'] = $order->get_id();
			$this->save_state( $id, $state );
		}

		$this->queue_event( 'order.paid', $this->build_order_payload( $order ) );
		$order->update_meta_data( self::META_PAID_SENT, 'yes' );
		$order->save();
	}

	/**
	 * Sends payment failure.
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_failed( $order_id ) {
		$this->send_order_status_event( 'order.payment_failed', $order_id );
	}

	/**
	 * Sends cancellation.
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_cancelled( $order_id ) {
		$this->send_order_status_event( 'order.cancelled', $order_id );
	}

	/**
	 * Sends one status event per order.
	 *
	 * @param string $event    Event name.
	 * @param int    $order_id Order ID.
	 */
	private function send_order_status_event( $event, $order_id ) {
		$order    = wc_get_order( $order_id );
		$meta_key = '_pwt_' . str_replace( '.', '_', $event ) . '_sent';

		if ( ! $order || 'yes' === $order->get_meta( $meta_key, true ) ) {
			return;
		}

		$this->queue_event( $event, $this->build_order_payload( $order ) );
		$order->update_meta_data( $meta_key, 'yes' );
		$order->save();
	}

	/**
	 * Checks whether a checkout became abandoned.
	 *
	 * @param string $id                Checkout ID.
	 * @param int    $expected_activity Activity timestamp when scheduled.
	 */
	public function check_abandonment( $id, $expected_activity ) {
		$state = $this->get_state( $id );

		if (
			empty( $state )
			|| ! empty( $state['paid'] )
			|| ! empty( $state['abandonment_sent'] )
			|| (int) $expected_activity !== (int) $state['last_activity']
		) {
			return;
		}

		$delay     = $this->get_abandonment_delay();
		$remaining = ( (int) $state['last_activity'] + $delay ) - time();

		if ( $remaining > 0 ) {
			$this->schedule_single_action( time() + $remaining, 'pwt_check_checkout_abandonment', array( $id, (int) $state['last_activity'] ) );
			return;
		}

		$this->queue_event( 'checkout.abandoned', $this->build_checkout_payload( $state ) );
		$state['abandonment_sent'] = time();
		$this->save_state( $id, $state );
	}

	/**
	 * Delivers one signed webhook and schedules retries.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Event data.
	 * @param int    $attempt Attempt number.
	 */
	public function deliver_webhook( $event, $payload, $attempt = 1 ) {
		$settings = $this->get_settings();
		if ( 'yes' !== $settings['enabled'] || empty( $settings['url'] ) ) {
			return;
		}

		$delivery_id = isset( $payload['_delivery_id'] ) ? (string) $payload['_delivery_id'] : wp_generate_uuid4();
		unset( $payload['_delivery_id'] );

		$body = wp_json_encode(
			array(
				'event'       => $event,
				'delivery_id' => $delivery_id,
				'occurred_at' => wp_date( DATE_ATOM ),
				'data'        => $payload,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, (string) $settings['secret'] );

		$response = wp_remote_post(
			$settings['url'],
			array(
				'timeout'     => 15,
				'data_format' => 'body',
				'headers'     => array(
					'Content-Type'        => 'application/json',
					'X-Pontus-Event'      => $event,
					'X-Pontus-Delivery'   => $delivery_id,
					'X-Pontus-Timestamp'  => $timestamp,
					'X-Pontus-Signature'  => 'sha256=' . $signature,
				),
				'body'        => $body,
			)
		);

		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			$this->log( 'info', 'Webhook entregue.', array( 'event' => $event, 'delivery_id' => $delivery_id, 'status' => $status ) );
			return;
		}

		$this->log(
			'error',
			'Falha ao entregar webhook.',
			array(
				'event'       => $event,
				'delivery_id' => $delivery_id,
				'attempt'     => $attempt,
				'status'      => $status,
				'error'       => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ),
			)
		);

		if ( $attempt < 5 ) {
			$payload['_delivery_id'] = $delivery_id;
			$delay = MINUTE_IN_SECONDS * ( 2 ** ( $attempt - 1 ) );
			$this->schedule_single_action( time() + $delay, 'pwt_deliver_webhook', array( $event, $payload, $attempt + 1 ) );
		}
	}

	/**
	 * Queues an event without delaying the page.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Event data.
	 */
	private function queue_event( $event, $payload ) {
		$settings = $this->get_settings();
		if ( 'yes' !== $settings['enabled'] || empty( $settings['url'] ) ) {
			return;
		}

		$payload['_delivery_id'] = wp_generate_uuid4();

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'pwt_deliver_webhook', array( $event, $payload, 1 ), 'pontus-webhooks' );
			return;
		}

		$this->schedule_single_action( time() + 1, 'pwt_deliver_webhook', array( $event, $payload, 1 ) );
	}

	/**
	 * Schedules the latest abandonment check.
	 *
	 * @param string $id       Checkout ID.
	 * @param int    $activity Last activity.
	 */
	private function schedule_abandonment( $id, $activity ) {
		$this->schedule_single_action(
			time() + $this->get_abandonment_delay(),
			'pwt_check_checkout_abandonment',
			array( $id, (int) $activity )
		);
	}

	/**
	 * Uses Action Scheduler when available, with WP-Cron fallback.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param string $hook      Hook name.
	 * @param array  $args      Hook arguments.
	 */
	private function schedule_single_action( $timestamp, $hook, $args ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, $hook, $args, 'pontus-webhooks' );
			return;
		}

		wp_schedule_single_event( $timestamp, $hook, $args );
	}

	/**
	 * Attaches an order to the tracked checkout.
	 *
	 * @param string   $id    Checkout ID.
	 * @param WC_Order $order Order.
	 */
	private function attach_order_to_state( $id, $order ) {
		if ( ! $id ) {
			return;
		}

		$state             = $this->get_state( $id );
		$state['order_id'] = $order->get_id();
		$state['last_step'] = 'payment';
		$state['last_activity'] = time();
		$this->save_state( $id, $state );
		$this->schedule_abandonment( $id, $state['last_activity'] );
	}

	/**
	 * Builds a checkout-stage payload.
	 *
	 * @param array $state Checkout state.
	 * @return array
	 */
	private function build_checkout_payload( $state ) {
		$fields = isset( $state['fields'] ) ? $state['fields'] : array();

		return array(
			'checkout_id'     => isset( $state['checkout_id'] ) ? $state['checkout_id'] : '',
			'order_id'        => isset( $state['order_id'] ) ? (int) $state['order_id'] : null,
			'last_step'       => isset( $state['last_step'] ) ? $state['last_step'] : '',
			'last_activity_at'=> isset( $state['last_activity'] ) ? wp_date( DATE_ATOM, (int) $state['last_activity'] ) : null,
			'customer'        => $this->customer_from_fields( $fields ),
			'fields'          => $fields,
			'items'           => $this->build_cart_items(),
			'totals'          => $this->build_cart_totals(),
			'coupons'         => $this->cart_coupon_codes(),
		);
	}

	/**
	 * Builds the order payload.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function build_order_payload( $order ) {
		return array(
			'checkout_id' => (string) $order->get_meta( self::ORDER_META_ID, true ),
			'order_id'    => $order->get_id(),
			'order_number'=> $order->get_order_number(),
			'status'      => $order->get_status(),
			'created_at'  => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null,
			'customer'    => array(
				'name'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'email'      => $order->get_billing_email(),
				'phone'      => $order->get_billing_phone(),
				'company'    => $order->get_billing_company(),
				'cpf'        => $this->first_order_meta( $order, array( '_billing_cpf', 'billing_cpf', '_cpf', 'cpf' ) ),
				'cnpj'       => $this->first_order_meta( $order, array( '_billing_cnpj', 'billing_cnpj', '_cnpj', 'cnpj' ) ),
				'address'    => array(
					'address_1' => $order->get_billing_address_1(),
					'address_2' => $order->get_billing_address_2(),
					'city'      => $order->get_billing_city(),
					'state'     => $order->get_billing_state(),
					'postcode'  => $order->get_billing_postcode(),
					'country'   => $order->get_billing_country(),
				),
			),
			'items'       => $this->build_order_items( $order ),
			'totals'      => array(
				'subtotal' => $this->money( $order->get_subtotal() ),
				'discount' => $this->money( $order->get_discount_total() ),
				'shipping' => $this->money( $order->get_shipping_total() ),
				'tax'      => $this->money( $order->get_total_tax() ),
				'total'    => $this->money( $order->get_total() ),
				'currency' => $order->get_currency(),
			),
			'coupons'     => array_map( 'strtoupper', $order->get_coupon_codes() ),
			'payment'     => array(
				'method'         => $order->get_payment_method(),
				'method_title'   => $order->get_payment_method_title(),
				'status'         => $order->is_paid() ? 'paid' : $order->get_status(),
				'transaction_id' => $order->get_transaction_id(),
				'paid_at'        => $order->get_date_paid() ? $order->get_date_paid()->date( DATE_ATOM ) : null,
			),
		);
	}

	/**
	 * Returns cart components and individual discounts.
	 *
	 * @return array
	 */
	private function build_cart_items() {
		$selected = array();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( Coupon_Addons::PRODUCT_ID !== (int) $cart_item['product_id'] ) {
					continue;
				}

				$selected['office'] = true;
				$options = isset( $cart_item['yith_wapo_options'] ) ? $cart_item['yith_wapo_options'] : array();
				$this->detect_addons( $options, $selected );
			}
		}

		$items   = $this->component_items( $selected );
		$coupons = array();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_applied_coupons() as $code ) {
				$coupons[] = array(
					'code'     => $code,
					'discount' => (float) WC()->cart->get_coupon_discount_amount( $code, false ),
				);
			}
		}

		return $this->allocate_discounts( $items, $coupons );
	}

	/**
	 * Returns order components and individual discounts.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function build_order_items( $order ) {
		$selected = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( Coupon_Addons::PRODUCT_ID !== (int) $item->get_product_id() ) {
				continue;
			}

			$selected['office'] = true;
			$meta = array();

			foreach ( $item->get_meta_data() as $metadata ) {
				$data = $metadata->get_data();
				$meta[ $data['key'] ] = $data['value'];
			}

			$this->detect_addons( $meta, $selected );
		}

		$coupons = array();
		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			$coupons[] = array(
				'code'     => $coupon_item->get_code(),
				'discount' => (float) $coupon_item->get_discount(),
			);
		}

		return $this->allocate_discounts( $this->component_items( $selected ), $coupons );
	}

	/**
	 * Detects selected YITH add-ons in nested data.
	 *
	 * @param mixed $data     Raw data.
	 * @param array $selected Selected component map.
	 */
	private function detect_addons( $data, &$selected ) {
		$json = remove_accents( strtolower( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ) );

		if ( false !== strpos( $json, 'atendimento telefonico' ) || false !== strpos( $json, '1-0' ) ) {
			$selected['phone'] = true;
		}

		if ( false !== strpos( $json, 'mais reunioes' ) || false !== strpos( $json, '1-1' ) ) {
			$selected['meetings'] = true;
		}
	}

	/**
	 * Creates contract-ready components.
	 *
	 * @param array $selected Selected component map.
	 * @return array
	 */
	private function component_items( $selected ) {
		$definitions = array(
			'office' => array(
				'name'        => 'Escritório Inteligente',
				'description' => 'Plano mensal de escritório inteligente',
				'unit_price'  => 189.00,
			),
			'phone' => array(
				'name'        => 'Atendimento Telefônico',
				'description' => 'Número exclusivo e atendimento telefônico',
				'unit_price'  => 50.00,
			),
			'meetings' => array(
				'name'        => 'Pacote Mais Reuniões',
				'description' => 'Pacote com 5 horas mensais de salas de reunião',
				'unit_price'  => 350.00,
			),
		);

		$items = array();
		foreach ( $definitions as $key => $definition ) {
			if ( empty( $selected[ $key ] ) ) {
				continue;
			}

			$items[] = array(
				'key'         => $key,
				'name'        => $definition['name'],
				'description' => $definition['description'],
				'quantity'    => 1,
				'unit_price'  => $this->money( $definition['unit_price'] ),
				'discount'    => 0.0,
				'final_price' => $this->money( $definition['unit_price'] ),
			);
		}

		return $items;
	}

	/**
	 * Allocates each real coupon discount to its eligible components.
	 *
	 * @param array $items   Component items.
	 * @param array $coupons Coupon code and actual discount.
	 * @return array
	 */
	private function allocate_discounts( $items, $coupons ) {
		foreach ( $coupons as $coupon_data ) {
			$discount = max( 0, (float) $coupon_data['discount'] );
			if ( $discount <= 0 ) {
				continue;
			}

			$coupon  = new \WC_Coupon( $coupon_data['code'] );
			$targets = $this->coupon_component_targets( $coupon );
			$indexes = array();
			$base    = 0.0;

			foreach ( $items as $index => $item ) {
				if ( empty( $targets ) || in_array( $item['key'], $targets, true ) ) {
					$indexes[] = $index;
					$base += (float) $item['unit_price'];
				}
			}

			if ( $base <= 0 || empty( $indexes ) ) {
				continue;
			}

			$remaining = min( $discount, $base );
			$last      = end( $indexes );

			foreach ( $indexes as $index ) {
				$share = $index === $last
					? $remaining
					: $this->money( $discount * ( (float) $items[ $index ]['unit_price'] / $base ) );
				$share = min( $share, (float) $items[ $index ]['unit_price'] - (float) $items[ $index ]['discount'] );

				$items[ $index ]['discount'] += $share;
				$remaining -= $share;
			}
		}

		foreach ( $items as &$item ) {
			$item['discount']    = $this->money( $item['discount'] );
			$item['final_price'] = $this->money( max( 0, $item['unit_price'] - $item['discount'] ) );
		}
		unset( $item );

		return $items;
	}

	/**
	 * Maps coupon targets to contract component keys.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return array
	 */
	private function coupon_component_targets( $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_pwt_addon_coupon_enabled', true ) ) {
			return array();
		}

		$targets = array();
		if ( 'yes' === $coupon->get_meta( '_pwt_addon_coupon_base', true ) ) {
			$targets[] = 'office';
		}
		if ( 'yes' === $coupon->get_meta( '_pwt_addon_coupon_phone', true ) ) {
			$targets[] = 'phone';
		}
		if ( 'yes' === $coupon->get_meta( '_pwt_addon_coupon_meetings', true ) ) {
			$targets[] = 'meetings';
		}

		return $targets;
	}

	/**
	 * Returns cart totals.
	 *
	 * @return array
	 */
	private function build_cart_totals() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		return array(
			'subtotal' => $this->money( WC()->cart->get_subtotal() ),
			'discount' => $this->money( WC()->cart->get_discount_total() ),
			'total'    => $this->money( WC()->cart->get_total( 'edit' ) ),
			'currency' => get_woocommerce_currency(),
		);
	}

	/**
	 * Returns uppercase cart coupon codes.
	 *
	 * @return array
	 */
	private function cart_coupon_codes() {
		return function_exists( 'WC' ) && WC()->cart
			? array_map( 'strtoupper', WC()->cart->get_applied_coupons() )
			: array();
	}

	/**
	 * Extracts customer data from checkout fields.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	private function customer_from_fields( $fields ) {
		return array(
			'name'       => trim( ( isset( $fields['billing_first_name'] ) ? $fields['billing_first_name'] : '' ) . ' ' . ( isset( $fields['billing_last_name'] ) ? $fields['billing_last_name'] : '' ) ),
			'first_name' => isset( $fields['billing_first_name'] ) ? $fields['billing_first_name'] : '',
			'last_name'  => isset( $fields['billing_last_name'] ) ? $fields['billing_last_name'] : '',
			'email'      => isset( $fields['billing_email'] ) ? $fields['billing_email'] : '',
			'phone'      => isset( $fields['billing_phone'] ) ? $fields['billing_phone'] : '',
			'company'    => isset( $fields['billing_company'] ) ? $fields['billing_company'] : '',
			'cpf'        => $this->first_array_value( $fields, array( 'billing_cpf', 'cpf' ) ),
			'cnpj'       => $this->first_array_value( $fields, array( 'billing_cnpj', 'cnpj' ) ),
		);
	}

	/**
	 * Gets the first present array value.
	 *
	 * @param array $data Data.
	 * @param array $keys Candidate keys.
	 * @return string
	 */
	private function first_array_value( $data, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
				return (string) $data[ $key ];
			}
		}
		return '';
	}

	/**
	 * Gets the first present order meta.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $keys  Candidate keys.
	 * @return string
	 */
	private function first_order_meta( $order, $keys ) {
		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key, true );
			if ( '' !== $value ) {
				return (string) $value;
			}
		}
		return '';
	}

	/**
	 * Recursively sanitizes browser-provided fields.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private function sanitize_payload_array( $data ) {
		$clean = array();

		foreach ( $data as $key => $value ) {
			$key = sanitize_key( $key );
			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_payload_array( $value );
			} elseif ( is_scalar( $value ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $clean;
	}

	/**
	 * Returns persisted settings with defaults.
	 *
	 * @return array
	 */
	private function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'enabled'         => 'no',
				'url'             => '',
				'secret'          => '',
				'abandon_minutes' => 30,
			)
		);
	}

	/**
	 * Returns abandonment delay in seconds.
	 *
	 * @return int
	 */
	private function get_abandonment_delay() {
		$settings = $this->get_settings();
		return max( 5, (int) $settings['abandon_minutes'] ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Gets checkout state.
	 *
	 * @param string $id Checkout ID.
	 * @return array
	 */
	private function get_state( $id ) {
		$state = get_transient( self::STATE_PREFIX . $id );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Saves checkout state for seven days.
	 *
	 * @param string $id    Checkout ID.
	 * @param array  $state State.
	 */
	private function save_state( $id, $state ) {
		set_transient( self::STATE_PREFIX . $id, $state, WEEK_IN_SECONDS );
	}

	/**
	 * Rounds a monetary value.
	 *
	 * @param mixed $value Value.
	 * @return float
	 */
	private function money( $value ) {
		return round( (float) $value, wc_get_price_decimals() );
	}

	/**
	 * Writes to the WooCommerce log.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private function log( $level, $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$context['source'] = 'pontus-webhooks';
		wc_get_logger()->log( $level, $message, $context );
	}
}
