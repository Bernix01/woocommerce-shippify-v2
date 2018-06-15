<?php
/**
 * Plugin Name: WooCommerce Shippify V2
 * Plugin URI: https://github.com/bernix01/woocommerce-shippify-v2/
 * Description: Adds Shippify shipping method to your WooCommerce store.
 * Version: 1.0.0
 * Author: Grupo Pulpo
 * Author URI: http://www.grupopulpo.ec/
 * Developer: Guillermo
 * Developer URI: https://github.com/bernix01/
 * Text Domain: woocommerce-shippify
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * WooCommerce Shippify is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * WooCommerce Shippify is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with WooCommerce Shippify. If not, see http://www.gnu.org/licenses/gpl-3.0.html.
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


/**
 * Check if WooCommerce is active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

	if ( ! class_exists( 'WC_Shippify' ) ) {

		class WC_Shippify {

			protected static $instance  = null;
			private $task_endpoint 	    = 'https://api.shippify.co/v1/deliveries';

			/**
			 * Places api (aka warehouse)
			 *
			 * @var string
			 */
			private $places_api         = 'https://api.shippify.co/v1/places';

			public function __construct() {

				if ( class_exists( 'WC_Integration' ) ) {

					$this->includes();
					add_filter( 'woocommerce_shipping_methods', array( $this, 'include_shipping_methods' ) );
					add_action( 'woocommerce_shipping_init', array( $this, 'shipping_method_init' ), 1 );
					add_filter( 'woocommerce_integrations', array( $this, 'add_shippify_integration' ) );
					add_action( 'woocommerce_order_details_after_order_table', array( $this, 'nolo_custom_field_display_cust_order_meta' ), 10, 1 );
					add_action( 'woocommerce_order_status_completed', array( $this, 'ship_other_methods' ), 10, 2 );

				}
			}


			public function ship_other_methods( $id, $order ) {
				if ( 'canceled' == $order->get_status() ) {
					return;
				}
				if ( '' == get_post_meta( $id, 'is_dispatched', true ) ) {
					$deliveries_str = $this->build_deliveries( $order );

					$deliveries = json_decode( $deliveries_str, true );
					$quote_id   = $_SESSION['quoteId'];
					$ref_id     = $order_id;

					// Checking if Cash on Delivery.
					$payment_method = $order->get_payment_method();
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
					if ( null != $quote_id && '' != $quote_id ) {
						// Constructing the POST request.
						$request_body = '{ "flexible": true, "express": false, "timeslots": false, "limit": 2, "deliveries":' . json_encode( array_values( $deliveries ) ) . ',"quoteId":' . $quote_id . '}';
					} else {
						// Constructing the POST request.
						$request_body = '{ "flexible": true, "express": false, "timeslots": false, "limit": 2, "deliveries":' . json_encode( array_values( $deliveries ) ) . '}';
					}


					// Basic Authorization.
					$args = array(
						'headers' => array(
							'Authorization' => 'Basic ' . base64_encode( $api_id . ':' . $api_secret ),
							'Content-Type'  => 'application/json',
						),
						'method'  => 'POST',
						'body'    => $request_body,
					);
					$order->add_order_note( 'shippify_data -> <br />' . $request_body );
					$order->save();
					$response = wp_remote_post( $this->task_endpoint, $args );
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

			private function build_deliveries( $order ) {
				$items           = $order->get_items();
				$cost            = 0;
				$warehouse_items = array();

				// Credentials.
				$api_id     = get_option( 'shippify_id' );
				$api_secret = get_option( 'shippify_secret' );

				// get all warehouses
				// Final request url.
				$request_url = $this->places_api;
				// Basic Authorization.
				$args = array(
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( $api_id . ':' . $api_secret ),
					),
					'method'  => 'GET',
				);

				$response = wp_remote_get( $request_url, $args );

				if ( is_wp_error( $response ) ) {
					return;
				} else {
					$decoded = json_decode( $response['body'], true );
					if ( decoded ) {
						$warehouses = array();
						foreach ( $decoded['places'] as $index => $place ) {
							$m_place = array(
								'contact'  => $place['contact'],
								'location' => array(
									'address' => $place['address'],
									'lat'     => $place['lat'],
									'lng'     => $place['lng'],
								),
							);
							$warehouses[ $place['id'] ] = $m_place;
						}
						if ( ! $warehouses ) {
							return;
						}
					} else {
						return;
					}
				}
				// verify all items support shippify
				// grouping items by warehouse btw.
				$valid = true;
				$delivery_name         = $order->get_shipping_first_name() . $order->get_billing_last_name();
				$delivery_email        = $order->get_billing_email();
				$delivery_phone        = $order->get_billing_phone();
				$delivery_latitude     = get_post_meta( $order->id, 'Latitude', true );
				$delivery_longitude    = get_post_meta( $order->id, 'Longitude', true );
				$delivery_address      = $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2();
				$delivery_instructions = get_post_meta( $order->id, 'Instructions', true );
				$warehouse_items       = array();

				foreach ( $items as $item_id => $item_product ) {
					$_id          = $item_product->get_product_id();
					$_product     = $item_product->get_product();
					$warehouse_id = get_post_meta( $_id, 'warehouse_id', true );
					if ( ! warehouse_id ) {
						$valid = false;
						break;
					}
					if ( array_key_exists( $warehouse_id, $warehouses ) ) {
						if ( array_key_exists( $warehouse_id, $warehouse_items ) ) {
							array_push(
								$warehouse_items[ $warehouse_id ]['packages'],
								array(
									'qty'   => $values['quantity'],
									'name'  => $_product->get_title(),
									'size'  => $this->calculate_product_shippify_size( $_product ),
									'price' => $_product->get_price(),
								)
							);
						} else {
							$warehouse_items[ $warehouse_id ] = array(
								'pickup'    => $warehouses[ $warehouse_id ],
								'sendEmail' => true,
								'dropoff'   => array(
									'contact'  => array(
										'name'        => $delivery_name,
										'email'       => $delivery_email,
										'phonenumber' => $delivery_phone,
									),
									'location' => array(
										'address'      => $delivery_address,
										'instructions' => $delivery_instructions,
										'lat'          => $delivery_latitude,
										'lng'          => $delivery_longitude,
									),
								),
								'packages'  => array(),
							);
							array_push(
								$warehouse_items[ $warehouse_id ]['packages'],
								array(
									'id'    => $_product->get_id(),
									'qty'   => $values['quantity'],
									'name'  => $_product->get_title(),
									'size'  => $this->calculate_product_shippify_size( $_product ),
									'price' => $_product->get_price(),
								)
							);
						}
					} else {
						$valid = false;
						break;
					}
				}
				if ( ! valid ) {
					return;
				}

				// If integration settings are not configured, method doesnt show.
				if ( '' == $api_id || '' == $api_secret ) {
					return;
				}

				// parsing deliveries into a quote.
				return json_encode( array_values( $warehouse_items ) );
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

			function nolo_custom_field_display_cust_order_meta( $order ) {
				$order_data = $order->get_meta( 'shippify_id' ); // The Order data.
				if ( null == $order_data ) {
					return;
				}
				$deliveries = json_decode( $order_data );
				// echo '<pre>' . json_encode( $deliveries ) . '</pre>';
				echo '<h1>Entregas</h1>';
				foreach ( $deliveries as $value ) {
					$api_id          = get_option( 'shippify_id' );
					$api_secret      = get_option( 'shippify_secret' );
					$request_url     = 'https://api.shippify.co/v1/deliveries/';
					// Basic Authorization.
					$args = array(
						'headers' => array(
							'Authorization' => 'Basic ' . base64_encode( $api_id . ':' . $api_secret ),
						),
						'method'  => 'GET',
					);

					$response = wp_remote_get( $request_url . $value . '/complete', $args );

					if ( is_wp_error( $response ) ) {
						wc_add_notice( __( 'Shippify: Cannot get delivery details.', 'woocommerce-shippify' ), 'error' );
						return;
					} else {
						$decoded = json_decode( $response['body'], true );
						echo '<h2> #' . $value;
						echo '<a href="https://api.shippify.co/track/' . $value . '" class="pull-right btn btn-primary btn-lg"> Trackear </a></h2>';
						echo '<h3> Items: </h3>';
						foreach ( $decoded['items'] as $key => $item ) {
							echo '<p>' . $item['name'] . ' <span class="pull-right"> x' . $item['qty'] . '</p>';
						}
						// echo '<pre>' . json_encode( $decoded ) . '</pre>';
					}
				}
				// echo '<p><strong>' . __( 'Pickup Location' ) . ':</strong> asadasds </p>';
			}


			/**
			 * Include Shippify integration to WooCommerce.
			 *
			 * @param  array $integrations Default integrations.
			 * @return array
			 */
			public function add_shippify_integration( $integrations ) {
				$integrations[] = 'WC_Shippify_Integration';

				return $integrations;
			}

			/**
			 * Return an instance of this class.
			 *
			 * @return object A single instance of this class.
			 */
			public static function get_instance() {
				// If the single instance hasn't been set, set it now.
				if ( null === self::$instance ) {
					self::$instance = new self();
				}

				return self::$instance;
			}

			/**
			 * Hooked to filter: woocommerce_shipping_methods,
			 * Add our Shippify shipping method to the shipping methods.
			 *
			 * @param array $methods Contains all the shop shipping methods.
			 */
			public function include_shipping_methods( $methods ) {
				$methods['shippify'] = 'WC_Shippify_Shipping';
				return $methods;
			}

			/**
			 * Hooked to action: woocommerce_shipping_init,
			 * Include our shipping method class.
			 */
			public function shipping_method_init() {
				include_once dirname( __FILE__ ) . '/includes/class-wc-shippify-shipping.php';
			}

			/**
			 *
			 * Include every other class.
			 */
			public function includes() {
				include_once dirname( __FILE__ ) . '/includes/class-wc-shippify-integration.php';
				include_once dirname( __FILE__ ) . '/includes/class-wc-shippify-admin-back-office.php';
				include_once dirname( __FILE__ ) . '/includes/class-wc-shippify-thankyou.php';
				include_once dirname( __FILE__ ) . '/includes/class-wc-shippify-checkout.php';
				include_once dirname( __FILE__ ) . '/includes/class-wc-shippify-warehousepp.php';
			}
		}
	}
	// Get the instance of the plugin.
	add_action( 'plugins_loaded', array( 'WC_Shippify', 'get_instance' ) );
	// Load the plugin Text Domain.
	add_action( 'plugins_loaded', 'wan_load_textdomain' );

	function wan_load_textdomain() {
		load_plugin_textdomain( 'woocommerce-shippify', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
}
