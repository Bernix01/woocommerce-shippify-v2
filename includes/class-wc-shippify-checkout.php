<?php
/**
 * Shippify V2 Woocommerce checkout process
 * /////
 * By: Bernix01 @ GrupoPulpo
 *
 * @package wp-wc-shippify-v2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shippify Checkout class handles the Checkout page action and filter hooks.
 *
 * @since   1.0.0
 * @version 1.2.3
 */
class WC_Shippify_Checkout {

	/**
	 * Quotes (Aka fare) endpoint
	 *
	 * @var string
	 */
	public $fare_api = 'https://api.shippify.co/v1/deliveries/quotes';

	/**
	 * Places api (aka warehouse)
	 *
	 * @var string
	 */
	public $places_api = 'https://api.shippify.co/v1/places';


	/**
	 * Adding Actions and Filters
	 */
	public function __construct() {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'customize_checkout_fields' ) );
		add_action( 'woocommerce_after_order_notes', array( $this, 'display_custom_checkout_fields' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_custom_checkout_fields' ) );

		// Enqueueing CSS and JS files.
		wp_enqueue_script( 'wc-shippify-checkout', plugins_url( '../assets/js/shippify-checkout.js', __FILE__ ), array( 'jquery' ) );
		wp_enqueue_style( 'wc-shippify-map-css', plugins_url( '../assets/css/shippify-checkout.css', __FILE__ ) );
		wp_enqueue_script( 'wc-shippify-map-js', plugins_url( '../assets/js/shippify-map.js', __FILE__ ) );

		add_action( 'woocommerce_after_checkout_form', array( $this, 'add_map' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'shippify_validate_order' ), 10 );
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( $this, 'change_shipping_label' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'action_woocommerce_checkout_update_order_review' ), 10, 2 );
	}


	/**
	 * Hooked to Action: woocommerce_checkout_update_order_review
	 * Everytime the order is updated, if Shippify is selected as shipping method, the calculate shipping method of the cart is called.
	 *
	 * @param array $array the array passed to this, unused.
	 * @param int   $int the int passed to this, unused.
	 * @return NULL
	 */
	public function action_woocommerce_checkout_update_order_review( $array, $int ) {
			WC()->cart->calculate_shipping();
		return;
	}

	/**
	 * Hooked to Filter: woocommerce_cart_shipping_method_full_label
	 * Change Shippify label depending on the page the user is.
	 *
	 * @param string $full_label The current shipping method label.
	 * @param string $method The shipping method id.
	 * @return string The label to show.
	 */
	public function change_shipping_label( $full_label, $method ) {
		if ( 'shippify' == $method->id ) {
			$sameday_label = ( 'yes' == get_option( 'shippify_sameday' ) ) ? __( 'Same Day Delivery ', 'woocommerce-shippify' ) : '';

			if ( is_cart() ) {
				$full_label = 'Shippify: ' . $sameday_label . ' ' . __( 'Proceed to Checkout for fares', 'woocommerce-shippify' );
			} elseif ( is_checkout() ) {
				$full_label = $full_label . ' ' . $sameday_label;

				if ( 'yes' == get_option( 'shippify_free_shipping' ) ) {
					$full_label = $full_label . '- ' . __( 'FREE!', 'woocommerce-shippify' );
				}
			}
			if ( is_cart() && 'yes' == get_option( 'shippify_free_shipping' ) ) {
				$full_label = 'Shippify: ' . $sameday_label . __( 'FREE!', 'woocommerce-shippify' );
			}
		}
		return $full_label;
	}

	/**
	 * Get latlng of address
	 *
	 * @param string $address the string address.
	 * @return mixed return latlng if found, false if not.
	 */
	public function get_lat_lng( $address ) {
		if ( ! empty( $address ) ) {
			// Formatted address.
			$formated_addrs = str_replace( ' ', '+', $address );
			// Send request and receive json data by address.
			$geocode_from_addrs = file_get_contents( 'http://maps.googleapis.com/maps/api/geocode/json?address=' . $formated_addrs . '&sensor=false' );
			$output             = json_decode( $geocode_from_addrs );
			// Get latitude and longitute from json data.
			$data['latitude']  = $output->results[0]->geometry->location->lat;
			$data['longitude'] = $output->results[0]->geometry->location->lng;
			// Return latitude and longitude of the given address.
			if ( ! empty( $data ) ) {
				return $data;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}
	/**
	 * Hooked to Action: woocommerce_after_checkout_form
	 * Insert our interactive map in checkout.
	 *
	 * @param array $after Every field after the checkout form.
	 */
	public function add_map( $after ) {
		$google_api_id = get_option( 'google_secret' ) != null ? get_option( 'google_secret' ) : '';

		echo '<div id="shippify_map">';
		echo '<h4>' . __( 'Delivery Position', 'woocommerce-shippify' ) . '</h4> <p>' . __( 'Confirm that the delivery address is correct on the map, if not, updated it by moving the marker.', 'woocommerce-shippify' ) . ' </p>';
		echo '<input id="pac-input" class="controls" type="text" placeholder="' . __( 'Search Box', 'woocommerce-shippify' ) . '">';
		echo '<div id="map"></div>';
		wp_enqueue_script( 'wc-shippify-google-maps', 'https://maps.googleapis.com/maps/api/js?key=' . $google_api_id . '&libraries=places&callback=initMap', $in_footer = true );
		echo '</div>';
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

	/**
	 * Hooked to Action: woocommerce_after_order_notes.
	 * Display in checkout our custom Shippify fields.
	 *
	 * @param array $checkout The checkout fields array.
	 */
	public function display_custom_checkout_fields( $checkout ) {
		// Set shipping price to $0 to not confuse the user.
		setcookie( 'shippify_longitude', '', time() - 3600 );
		setcookie( 'shippify_latitude', '', time() - 3600 );
		echo '<div id="shippify_checkout" class="col3-set"><h2>' . __( 'Shippify' ) . '</h2>';

		foreach ( $checkout->checkout_fields['shippify'] as $key => $field ) :
				woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
		endforeach;
		echo '</div>';

		WC()->cart->calculate_shipping();
	}


	/**
	 * Hooked to Action: woocommerce_checkout_update_order_meta.
	 * Save Shippify custom checkout fields to the order when checkout is processed.
	 *
	 * @param string $order_id The order id.
	 */
	public function save_custom_checkout_fields( $order_id ) {
		if ( ! empty( $_POST['shippify_instructions'] ) ) {
			update_post_meta( $order_id, 'Instructions', sanitize_text_field( $_POST['shippify_instructions'] ) );
		}
		if ( ! empty( $_POST['shippify_latitude'] ) ) {
			update_post_meta( $order_id, 'Latitude', sanitize_text_field( $_POST['shippify_latitude'] ) );
		}
		if ( ! empty( $_POST['shippify_longitude'] ) ) {
			update_post_meta( $order_id, 'Longitude', sanitize_text_field( $_POST['shippify_longitude'] ) );
		}

		update_post_meta( $order_id, 'pickup_latitude', sanitize_text_field( $_COOKIE['warehouse_latitude'] ) );
		update_post_meta( $order_id, 'pickup_longitude', sanitize_text_field( $_COOKIE['warehouse_longitude'] ) );
		update_post_meta( $order_id, 'pickup_address', sanitize_text_field( $_COOKIE['warehouse_address'] ) );
		update_post_meta( $order_id, 'pickup_id', sanitize_text_field( $_COOKIE['warehouse_id'] ) );
	}


	/**
	 * Hooked to Filter: woocommerce_checkout_fields.
	 * Add Shippify custom checkout fields to the checkout form.
	 *
	 * @param array $fields The checkout form fields.
	 * @return array The checkout form fields
	 */
	public function customize_checkout_fields( $fields ) {
		global $woocommerce;

		$fields['shippify'] = array(
			'shippify_instructions' => array(
				'type'        => 'text',
				'class'       => array( 'form-row form-row-wide' ),
				'label'       => __( 'Reference', 'woocommerce-shippify' ),
				'placeholder' => __( 'Reference to get to the delivery place.', 'woocommerce-shippify' ),
				'required'    => false,
			),
			'shippify_latitude'     => array(
				'type'     => 'text',
				'class'    => array( 'form-row form-row-wide' ),
				'label'    => __( 'Latitude', 'woocommerce-shippify' ),
				'required' => false,
				'class'    => array( 'address-field', 'update_totals_on_change' ),
			),
			'shippify_longitude'    => array(
				'type'     => 'text',
				'class'    => array( 'form-row form-row-wide' ),
				'label'    => __( 'Longitude', 'woocommerce-shippify' ),
				'required' => false,
				'class'    => array( 'address-field', 'update_totals_on_change' ),
			),
		);

		return $fields;
	}

	/**
	 * Hooked to Action: woocommerce_checkout_process.
	 * Validate the Shippify fields in checkout.
	 * The methods tries to obtain the shippify fare of the task the API would create if the order is placed just as it is right now.
	 * Warning messages appear and the order does not place if the request fails or any fields are empty.
	 */
	public function shippify_validate_order() {
		if ( in_array( 'shippify', $_POST['shipping_method'] ) ) {

			// No marker on Map.
			if ( '' == $_POST['shippify_latitude'] || '' == $_POST['shippify_longitude'] ) {
				wc_add_notice( __( 'Shippify: Please, locate the marker of your address in the map.', 'woocommerce-shippify' ), 'error' );
			}
			if ( '' == $_POST['shippify_instructions'] || 10 > strlen( $_POST['shippify_instructions'] ) ) {
				wc_add_notice( __( 'Shippify: Please, write descriptive instructions.', 'woocommerce-shippify' ), 'error' );
			}

			$api_id          = get_option( 'shippify_id' );
			$api_secret      = get_option( 'shippify_secret' );
			$items           = WC()->cart->get_cart();
			$warehouse_items = array();

			// Getting pickup information based on shipping zone.
			$pickup_warehouse = $_COOKIE['warehouse_id'];
			$pickup_latitude  = $_COOKIE['warehouse_latitude'];
			$pickup_longitude = $_COOKIE['warehouse_longitude'];

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
				wc_add_notice( __( 'Shippify: We are unable to make a route to your address. Verify the marker in the map is correctly positioned.', 'woocommerce-shippify' ), 'error' );
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
						// key => value
						// warehouseID => [warehouse,].
						$warehouses[ $place['id'] ] = $m_place;
					}
					if ( ! $warehouses ) {
						wc_add_notice( __( 'Shippify: We are unaable to make a route to your address. Verify the marker in the map is correctly positioned.', 'woocommerce-shippify' ), 'error' );
						return;
					}
				} else {
					wc_add_notice( __( 'Shippify: We are unable too make a route to your address. Verify the marker in the map is correctly positioned.', 'woocommerce-shippify' ), 'error' );
					return;
				}
			}

			// verify all items support shippify
			// grouping items by warehouse btw.
			$valid                 = true;
			$delivery_name         = $_POST['billing_first_name'] . ' ' . $_POST['billing_last_name'];
			$delivery_email        = $_POST['billing_email'];
			$delivery_phone        = $_POST['billing_phone'];
			$delivery_latitude     = $_POST['shippify_latitude'];
			$delivery_longitude    = $_POST['shippify_longitude'];
			$delivery_instructions = $_POST['shippify_instructions'];
			$delivery_address      = $_POST['shipping_address_1'] ?: $_POST['billing_address_1'];
			$warehouse_items       = array();
			foreach ( $items as $item => $values ) {
				if ( array_key_exists( 'variation_id', $values ) ){
					$_id = $values['product_id'];
				} else {
					$_id = $values['data']->get_id();
				}
				$_product     = wc_get_product( $_id );
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
									'lat'          => $delivery_latitude,
									'lng'          => $delivery_longitude,
									'instructions' => $delivery_instructions,
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
				wc_add_notice( __( 'Shippify: We are unable to make a route to your address. Verify the marker in the map is correctly positioned.', 'woocommerce-shippify' ), 'error' );
				return;
			}// If integration settings are not configured, method doesnt show.
			if ( '' == $api_id || '' == $api_secret ) {
				wc_add_notice( __( 'Shippify: We are unable to make a route to your address. Verify the marker in the map is correctly positioned.', 'woocommerce-shippify' ), 'error' );
				return;
			}

			// parsing deliveries into a quote.
			$deliveries_str = json_encode( array_values( $warehouse_items ) );
			$data_value     = '{"flexible":true, "express":false, "timeslots":false, "limit": 2,"deliveries":' . $deliveries_str . '}';
			// Final request url.
			$request_url = $this->fare_api;

			// Basic Authorization.
			$args = array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $api_id . ':' . $api_secret ),
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'method'  => 'POST',
				'body'    => $data_value,
			);

			$response = wp_remote_post( $request_url, $args );

			if ( is_wp_error( $response ) ) {
				wc_add_notice( __( 'Shippify: We are unable to make a route to your address. Verify the marker in the map is correctly positioned.', 'woocommerce-shippify' ), 'error' );
			} else {
				$code    = $response['response']['code'];
				$decoded = json_decode( $response['body'], true );
				if ( 200 == $code && null !== $decoded ) {
					// if quote then save the quoteid and sum up the shipping rate.
					session_start();
					$_SESSION['quoteId']    = $decoded['payload']['quotes'][0]['quoteId'];
					$_SESSION['deliveries'] = $deliveries_str;
					$price                  = $decoded['payload']['quotes'][0]['cost'];
				} else {
					wc_add_notice( __( 'Shippify: We are unable to make a route to your address. Verify the marker in the map is correctly positioned.', 'woocommerce-shippify' ), 'error' );
				}
			}
			echo '<script> console.log(' . json_encode( $data_value ) . ' , ' . json_encode( $_POST ) . ') </script>';
		}
	}
}

new WC_Shippify_Checkout();
