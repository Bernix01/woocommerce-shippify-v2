<?php
/**
 * The Shippify Thank you page. Handles instant delivery and Delivery data shown
 * to front user
 *
 * @package wp-wc-shippify-v2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shippify thankyou class. Handles the Thankyou page action and filters.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
class WC_Shippify_Thankyou {

	/**
	 * Creates a new WC_Shippify_Thankyou intance. Adds actions.
	 */
	public function __construct() {
		add_action( 'woocommerce_thankyou_cod', array( $this, 'display_shippify_order_data' ), 20 );
	}

	/**
	 * Displays the shippify data related to this order.
	 *
	 * @param int $order_id The order id.
	 * @return void
	 */
	public function display_shippify_order_data( $order_id ) {

		?>
		<h2><?php _e( 'Shippify' ); ?></h2>
		<table class="shop_table shop_table_responsive additional_info">
			<tbody>
				<tr>
					<th><?php _e( 'Instrucciones:' ); ?></th>
					<td><?php echo get_post_meta( $order_id, 'Instructions', true ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php

		// Check for automatic dispatch.
		if ( get_option( 'shippify_instant_dispatch' ) == 'yes' ) {
			$status = $this->create_shippify_task( $order_id );
			if ( false == $status ) {
				echo __( 'Your order was not dispatched instantly. Please, wait for the store admin to dispatch it manually. Thanks you for choosing shippify!', 'woocommerce-shippify' );
			} else {
				echo __( 'Your order has been dispatched! It will soon arrive. Thanks you for choosing shippify!', 'woocommerce-shippify' );
			}
		} else {
			echo __( 'Your order was not dispatched instantly. Please, wait for the store admin to dispatch it manually. Thanks you for choosing shippify!', 'woocommerce-shippify' );
		}
	}

	/**
	 * Creates the Shippify Delivery of a shop order.
	 *
	 * @param string $order_id Shop order identifier.
	 */
	public function create_shippify_task( $order_id ) {

		$task_endpoint = 'https://api.shippify.co/v1/deliveries';

		$order = wc_get_order( $order_id );

		// Sender Email.
		$sender_mail = get_option( 'shippify_sender_email' );
		session_start();
		$deliveries = json_decode( $_SESSION['deliveries'], true );
		$quote_id   = $_SESSION['quoteId'];
		$ref_id     = $order_id;

		// Checking if Cash on Delivery.
		$payment_method = get_post_meta( $order_id, '_payment_method', true );
		$cod            = 'cod' == $payment_method;

		// Credentials.
		$api_id     = get_option( 'shippify_id' );
		$api_secret = get_option( 'shippify_secret' );
		for ( $i = 0; $i < count( $deliveries ); ++$i ) {
			$deliveries[ $i ]['referenceId'] = '' . $ref_id;
			if ( $cod ) {
				$deliveries[ $i ]['cod'] = $this->get_cod( $deliveries[ $i ]['packages'] );
			}
		}

		// Constructing the POST request.
		$request_body = '{ "flexible": true, "express": false, "timeslots": false, "limit": 2, "deliveries":' . json_encode( array_values( $deliveries ) ) . ',"quoteId":' . $quote_id . '}';

		// Basic Authorization.
		$args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $api_id . ':' . $api_secret ),
				'Content-Type'  => 'application/json',
			),
			'method'  => 'POST',
			'body'    => $request_body,
		);

		$order->add_order_note( 'shippify_data -> </br>' . $request_body );
		$order->save();
		$response = wp_remote_post( $task_endpoint, $args );
		if ( ! is_wp_error( $response ) && 200 == $response['response']['code'] ) {
			$response = json_decode( $response['body'], true );
			if ( count( $response['payload'] ) == count( $deliveries ) ) {
				$order->update_meta_data( 'is_dispatched', 'yes' );
				$order->update_meta_data( 'shippify_id', json_encode( array_map( array( $this, 'to_ids' ), $response['payload'] ) ) );
				$order->add_order_note( 'Shippify: Item shipped! - COD: ' . $cod );
				$order->save();
				return true;
			} else {
				$order->add_order_note( 'Shippify: Count mismatch' );
				$order->add_order_note( 'Shippify: shipppify_errors_response \n' . $response );
				$order->save();
				return false;
			}
		} else {
			$order->add_order_note( 'Shippify: Response code not 200' );
			$order->add_order_note( 'Shippify: shipppify_errors_response \n' . json_encode( $response ) );
			$order->save();
			return false;
		}
	}

	/**
	 * Map function to return only 'id' field.
	 *
	 * @param array $el The elemennt.
	 * @return mixed The id.
	 */
	public function to_ids( $el ) {
		return $el['id'];
	}

	/**
	 * Gets the total cash on delivery to be collected.
	 *
	 * @param array $packages The delivery packages.
	 * @return int Total cash on delivery.
	 */
	public function get_cod( $packages ) {
		$cod = 0;
		foreach ( $packages as $package ) {
			$product = wc_get_product( $package['id'] );
			$cod    += $product->get_price() * $package['qty'];
		}
		return $cod;
	}

	/**
	 * Diffuse Logic Algorithm used to calculate Shippify product size based on the product dimensions.
	 *
	 * @param WC_Product $product The product to calculate the size.
	 */
	public function calculate_product_shippify_size( $product ) {

		$height = $product->get_height();
		$width  = $product->get_width();
		$length = $product->get_length();

		if ( ! isset( $height ) || '' == $height ) {
			return '3';
		}
		if ( ! isset( $width ) || '' == $width ) {
			return '3';
		}
		if ( ! isset( $length ) || '' == $length ) {
			return '3';
		}

		$width  = floatval( $width );
		$height = floatval( $height );
		$length = floatval( $length );

		$array_size        = array( 1, 2, 3, 4, 5 );
		$array_dimensions  = array( 50, 80, 120, 150, 150 );
		$radio_membership  = 10;
		$dimensions_array  = array( 10, 10, 10 );
		$final_percentages = array();

		foreach ( $array_size as $size ) {
			$percentage     = 0;
			$max_percentage = 100 / 3;
			foreach ( $dimensions_array as $dimension ) {
				if ( $dimension < $array_dimensions[ $size - 1 ] ) {
					$percentage = $percentage + $max_percentage;
				} elseif ( $dimension < $array_dimensions[ $size - 1 ] + $radio_membership ) {
					$pre_result = ( 1 - ( abs( $array_dimensions[ $size - 1 ] - $dimension ) / ( 2 * $radio_membership ) ) );
					$tmp_p      = $pre_result < 0 ? 0 : $pre_result;
					$percentage = $percentage + ( ( ( $pre_result * 100 ) * $max_percentage ) / 100 );
				} else {
					$percentage = $percentage + 0;
				}
			}
			$final_percentages[] = $percentage;
		}
		$maxs = array_keys( $final_percentages, max( $final_percentages ) );
		return $array_size[ $maxs[0] ];
	}
}

new WC_Shippify_Thankyou();
