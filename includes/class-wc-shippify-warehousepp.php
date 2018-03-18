<?php

/*
 * Warehouse per product field
 */
class WC_Shippify_Warehousepp {


	/**
	 * Maintains a value to the text field ID for serialization.
	 *
	 * @access private
	 * @var    string
	 */
	private $textfield_id;

	/**
	 * Initializes the class and the instance variables.
	 */
	public function __construct( $id ) {
		$this->textfield_id = $id;
		// add_action('woocommerce_product_options_pricing', array($this, 'wc_warehouse_id_field'));
		add_action( 'woocommerce_product_data_panels', array( $this, 'custom_tab_panel' ) );
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'custom_product_tabs' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_custom_field' ) );

	}

	// public function wc_warehouse_id_field()
	// {
	// $field = array(
	// 'id' => 'christmas_price',
	// 'label' => __('Christmas Price', 'textdomain'),
	// 'data_type' => 'price', //Let WooCommerce formats our field as price field
	// );
	// woocommerce_wp_text_input($field);
	// }
	public function custom_tab_panel() {
		?>
		<div id="shippify" class="panel woocommerce_options_panel">
		  <div class="options_group">
			<?php
			$field = array(
				'id'    => $this->textfield_id,
				'label' => __( 'Warehouse ID', 'textdomain' ),
			);
			woocommerce_wp_text_input( $field );
		?>
		  </div>
		</div>
		<?php
	}

	public function custom_product_tabs( $tabs ) {
		$tabs['giftcard'] = array(
			'label'  => __( 'Shippify', 'woocommerce' ),
			'target' => 'shippify',
			'class'  => array( 'show_if_simple', 'show_if_variable' ),
		);
		return $tabs;
	}

	public function save_custom_field( $post_id ) {

		$custom_field_value = isset( $_POST[ $this->textfield_id ] ) ? $_POST[ $this->textfield_id ] : '';

		$product = wc_get_product( $post_id );
		$product->update_meta_data( $this->textfield_id, $custom_field_value );
		$product->save();
	}
}

new WC_Shippify_Warehousepp( 'warehouse_id' );
