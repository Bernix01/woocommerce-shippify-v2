<?php
/**
 * Shippify shipping method.
 *
 * @since   1.0.0
 * @version 1.2.3
 * @package wp-wc-shippify-v2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$active_plugins = (array) get_option( 'active_plugins', array() );

// Check for multisite configuration.
if ( is_multisite() ) {
	$network_activated_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
	$active_plugins            = array_merge( $active_plugins, $network_activated_plugins );
}

// Check if woocommerce is active.
if ( in_array( 'woocommerce/woocommerce.php', $active_plugins ) ) {
	if ( ! class_exists( 'WC_Shippify_Shipping' ) ) {

		/**
		 *
		 * Shippify shiping method class. Supports shipping-zones and instance settings.
		 * Shipping calculations are based on Shippify API.
		 */
		class WC_Shippify_Shipping extends WC_Shipping_Method {
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
			 * Initialize Shippify shipping method.
			 *
			 * @param int $instance_id Shipping zone instance ID.
			 */
			public function __construct( $instance_id = 0 ) {
				$this->id      = 'shippify';
				$this->enabled = 'yes';
				// translators: Shippify is a shipping option.
				$this->method_title = __( 'Shippify', 'woocommerce-shippify' );
				$this->more_link    = 'http://shippify.co/';
				$this->instance_id  = absint( $instance_id );
				// translators: Shippify is a shipping option.
				$this->method_description = sprintf( __( '%s is a shipping option.', 'woocommerce-shippify' ), $this->method_title );
				$this->supports           = array(
					'shipping-zones',
					'instance-settings',
				);
				$this->title              = 'Shippify';
				$this->countries          = array(
					'EC',
					'BR',
					'CL',
					'MX',
				);

				// Load the form fields.
				$this->init_form_fields();
				// $this->init_settings();
				// Set instance options values if they are defined.
				$this->warehouse_id        = $this->get_instance_option( 'warehouse_id' );
				$this->warehouse_adress    = $this->get_instance_option( 'warehouse_adress' );
				$this->warehouse_latitude  = $this->get_instance_option( 'warehouse_latitude' );
				$this->warehouse_longitude = $this->get_instance_option( 'warehouse_longitude' );

				add_action( 'woocommerce_update_options_shipping_shippify', array( $this, 'process_admin_options' ), 3 );
			}

			/**
			 *
			 * Admin instance options fields.
			 */
			public function init_form_fields() {
				$this->instance_form_fields = array(
					'warehouse_info'      => array(
						'title'   => __( 'Warehouse Information', 'woocommerce-shippify' ),
						'type'    => 'title',
						'default' => '',
					),
					'warehouse_id'        => array(
						'title'       => __( 'Warehouse ID', 'woocommerce-shippify' ),
						'type'        => 'text',
						'description' => __( 'The id of the warehouse from which the product is going to be dispatched' ),
						'desc_tip'    => true,
						'default'     => '',
					),
					'warehouse_adress'    => array(
						'title'       => __( 'Warehouse Adress', 'woocommerce-shippify' ),
						'type'        => 'text',
						'description' => __( 'The adress of the warehouse from which the product is going to be dispatched' ),
						'desc_tip'    => true,
						'default'     => '',
					),
					'warehouse_latitude'  => array(
						'title'       => __( 'Warehouse Latitude', 'woocommerce-shippify' ),
						'type'        => 'text',
						'description' => __( 'The latitude coordinate of the warehouse from which the product is going to be dispatched' ),
						'desc_tip'    => true,
						'default'     => '',
					),
					'warehouse_longitude' => array(
						'title'       => __( 'Warehouse Longitude', 'woocommerce-shippify' ),
						'type'        => 'text',
						'description' => ( 'The longitude coordinate of the warehouse from which the product is going to be dispatched' ),
						'desc_tip'    => true,
						'default'     => '',
					),
				);
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
			 * Calculates the shipping rate. This calculations are based on the dinamically produced coordinates in checkout, warehouse information
			 * of the shippign zone and package information.
			 *
			 * @param array $package Order package.
			 */
			public function calculate_shipping( $package = array() ) {

				// Check if valid to be calculeted.
				if ( ! in_array( $package['destination']['country'], $this->countries ) ) {
					return;
				}
				$items           = WC()->cart->get_cart();
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
							// key => value
							// warehouseID => [warehouse,].
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
				if ( ! isset( $_POST['post_data'] ) ) {
					return;
				}
				$chunks             = array_chunk( preg_split( '/(=|&)/', $_POST['post_data'] ), 2 );
				$post_data          = array_combine( array_column( $chunks, 0 ), array_column( $chunks, 1 ) );
				$delivery_name      = $post_data['billing_first_name'] . ' ' . $post_data['billing_last_name'];
				$delivery_email     = $post_data['billing_email'];
				$delivery_phone     = $post_data['billing_phone'];
				$delivery_latitude  = $post_data['shippify_latitude'];
				$delivery_longitude = $post_data['shippify_longitude'];
				$warehouse_items    = array();
				foreach ( $items as $item => $values ) {
					if ( array_key_exists( 'variation_id', $values ) ){
						$_id = $values['product_id'];
					} else {
						$_id = $values['data']->get_id();
					}
					$_product     = wc_get_product( $_id );
					$warehouse_id = $_product->get_meta( 'warehouse_id' );

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
										'address' => $package['destination']['address'],
										'lat'     => $delivery_latitude,
										'lng'     => $delivery_longitude,
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
					return;
				} else {
					$code    = $response['response']['code'];
					$decoded = json_decode( $response['body'], true );
					if ( 200 == $code && null !== $decoded ) {
						// if quote then save the quoteid and sum up the shipping rate.
						session_start();
						$_SESSION['quoteId']    = $decoded['payload']['quotes'][0]['quoteId'];
						$_SESSION['deliveries'] = $deliveries_str;
						$cost                  += $decoded['payload']['quotes'][0]['cost'];
					} else {
						return;
					}
				}

				if ( is_cart() || 'yes' == get_option( 'shippify_free_shipping' ) ) {
					$cost = 0;
				} elseif ( 'yes' == get_option( 'shippify_350_shipping' ) ) {
					$cost = 3.5;
				}

				$rate = array(
					'id'    => $this->id,
					'label' => $this->title,
					'cost'  => $cost,
				);

				$this->add_rate( $rate );
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
	}
}
