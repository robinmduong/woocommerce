<?php

use Automattic\WooCommerce\Internal\AttributesHelper;

/**
 * Tests related to filtering for WC_Query.
 */
class WC_Query_Filtering_Test extends \WC_Unit_Test_Case {

	/**
	 * Runs before all the tests in the class.
	 */
	public static function setupBeforeClass() {
		global $wpdb, $wp_post_types;

		parent::setUpBeforeClass();

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_product_attributes_lookup" );
		$wpdb->query(
			"
          CREATE TABLE {$wpdb->prefix}wc_product_attributes_lookup (
		  product_id bigint(20) NOT NULL,
		  product_or_parent_id bigint(20) NOT NULL,
		  taxonomy varchar(32) NOT NULL,
		  term_id bigint(20) NOT NULL,
		  is_variation_attribute tinyint(1) NOT NULL,
		  in_stock tinyint(1) NOT NULL
 		  );
		"
		);

		// This is required too for WC_Query to act on the main query.
		$wp_post_types['product']->has_archive = true;
	}


	/**
	 * Runs after all the tests in the class.
	 */
	public static function tearDownAfterClass() {
		global $wpdb;

		parent::tearDownAfterClass();

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_product_attributes_lookup" );
	}


	/**
	 * Runs after each test.
	 */
	public function tearDown() {
		global $wpdb;

		parent::tearDown();

		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wc_product_attributes_lookup" );

		// Unregister all product attributes.

		$attribute_ids_by_name = wc_get_attribute_taxonomy_ids();
		if ( ! empty( $attribute_ids_by_name ) ) {
			$attribute_names = array_keys( $attribute_ids_by_name );
			foreach ( $attribute_names as $name ) {
				AttributesHelper::remove_taxonomy_for_attribute( $name );
			}
			$this->do_rest_request( 'products/attributes/batch', array( 'delete' => array_values( $attribute_ids_by_name ) ), 'POST' );
		}

		// Remove all products.

		$product_ids = wc_get_products( array( 'return' => 'ids' ) );
		if ( ! empty( $product_ids ) ) {
			$this->do_rest_request( 'products/batch', array( 'delete' => $product_ids ), 'POST' );
		}

		// Force the next query to re-read the filtering attributes from the query parameters.

		WC_Query::reset_chosen_attributes();
	}


	/**
	 * Creates a simple product.
	 *
	 * @param array $attributes An array of product attributes, keys are attribute names, values are arrays of attribute term names.
	 * @param bool  $in_stock True if the poroduct is in stock, false otherwise.
	 * @return array The product data, as generated by the REST API product creation entry point.
	 */
	private function create_simple_product( $attributes, $in_stock ) {
		global $wpdb;

		$converted_attributes  = array();
		$lookup_insert_clauses = array();
		$lookup_insert_values  = array();

		$attribute_ids_by_name = wc_get_attribute_taxonomy_ids();
		foreach ( $attributes as $name => $terms ) {
			$sanitized_name = wc_sanitize_taxonomy_name( $name );
			$attribute_id   = $attribute_ids_by_name[ $sanitized_name ];

			$converted_attributes[] = array(
				'id'      => $attribute_id,
				'options' => $terms,
			);
		}

		$product = $this->do_rest_request(
			'products',
			array(
				'name'          => 'Product',
				'type'          => 'simple',
				'regular_price' => '1',
				'stock_status'  => $in_stock ? 'instock' : 'outofstock',
				'attributes'    => $converted_attributes,
			),
			'POST'
		);

		if ( empty( $attributes ) ) {
			return $product;
		}

		foreach ( $attributes as $name => $terms ) {
			$this->compose_lookup_table_insert( $product['id'], $product['id'], $name, $terms, $lookup_insert_clauses, $lookup_insert_values, $in_stock );
		}

		$this->run_lookup_table_insert( $lookup_insert_clauses, $lookup_insert_values );

		return $product;
	}


	/**
	 * Creates a variable product.
	 * Format for the supplied data:
	 *
	 * variation_attributes => [
	 *     Color => [Red, Blue, Green],
	 *     Size  => [Big, Medium, Small]
	 * ],
	 * non_variation_attributes => [
	 *     Features => [Washable, Ironable]
	 * ],
	 * variations => [
	 *     [
	 *       defining_attributes => [
	 *         Color => Red,
	 *         Size  => Small
	 *       ],
	 *       in_stock => true
	 *     ],
	 *     [
	 *       defining_attributes => [
	 *         Color => Red,
	 *         Size  => null  //Means "Any"
	 *       ],
	 *       in_stock => false
	 *     ],
	 * ]
	 *
	 * Format for the returned data:
	 *
	 * [
	 *   id => 1,
	 *   variation_ids => [2,3]
	 * ]
	 *
	 * @param array $data The data for creating the product.
	 * @returns array The product and variation ids.
	 */
	private function create_variable_product( $data ) {

		// * First create the main product.

		$converted_attributes = array();

		$attribute_ids_by_name = wc_get_attribute_taxonomy_ids();
		foreach ( $data['non_variation_attributes'] as $name => $terms ) {
			$sanitized_name = wc_sanitize_taxonomy_name( $name );
			$attribute_id   = $attribute_ids_by_name[ $sanitized_name ];

			$converted_attributes[] = array(
				'id'        => $attribute_id,
				'options'   => $terms,
				'variation' => false,
			);
		}
		foreach ( $data['variation_attributes'] as $name => $terms ) {
			$sanitized_name = wc_sanitize_taxonomy_name( $name );
			$attribute_id   = $attribute_ids_by_name[ $sanitized_name ];

			$converted_attributes[] = array(
				'id'        => $attribute_id,
				'options'   => $terms,
				'variation' => true,
			);
		}

		$product = $this->do_rest_request(
			'products',
			array(
				'name'       => 'Product',
				'type'       => 'variable',
				'attributes' => $converted_attributes,
			),
			'POST'
		);

		$product_id = $product['id'];

		// * Now create the variations.

		$variation_ids = array();

		foreach ( $data['variations'] as $variation_data ) {
			$variation_defining_attributes = array();

			foreach ( $variation_data['defining_attributes'] as $attribute_name => $attribute_value ) {
				if ( is_null( $attribute_value ) ) {
					continue;
				}

				$sanitized_name = wc_sanitize_taxonomy_name( $name );
				$attribute_id   = $attribute_ids_by_name[ $sanitized_name ];

				$variation_defining_attributes[] = array(
					'id'     => $attribute_id,
					'option' => $attribute_value,
				);
			}

			$variation = $this->do_rest_request(
				"products/{$product_id}/variations",
				array(
					'regular_price' => '10',
					'stock_status'  => $variation_data['in_stock'] ? 'instock' : 'outofstock',
					'attributes'    => $variation_defining_attributes,
				),
				'POST'
			);

			$variation_ids[] = $variation['id'];
		}

		// This is needed because it's not done by the REST API.
		WC_Product_Variable::sync_stock_status( $product_id );

		// * And finally, insert the data in the lookup table.

		$lookup_insert_clauses = array();
		$lookup_insert_values  = array();

		if ( ! empty( $data['non_variation_attributes'] ) ) {
			$main_product_in_stock = ! empty(
				array_filter(
					$data['variations'],
					function( $variation ) {
						return $variation['in_stock'];
					}
				)
			);

			foreach ( $data['non_variation_attributes'] as $name => $terms ) {
				$this->compose_lookup_table_insert( $product['id'], $product['id'], $name, $terms, $lookup_insert_clauses, $lookup_insert_values, $main_product_in_stock );
			}
		}

		reset( $variation_ids );
		foreach ( $data['variations'] as $variation_data ) {
			$variation_id = current( $variation_ids );

			foreach ( $variation_data['defining_attributes'] as $attribute_name => $attribute_value ) {
				if ( is_null( $attribute_value ) ) {
					$attribute_values = $data['variation_attributes'][ $attribute_name ];
				} else {
					$attribute_values = array( $attribute_value );
				}
				$this->compose_lookup_table_insert( $product['id'], $variation_id, $attribute_name, $attribute_values, $lookup_insert_clauses, $lookup_insert_values, $variation_data['in_stock'] );
			}

			next( $variation_ids );
		}

		$this->run_lookup_table_insert( $lookup_insert_clauses, $lookup_insert_values );

		return array(
			'id'            => $product_id,
			'variation_ids' => $variation_ids,
		);
	}


	/**
	 * Compose the values part of a query to insert data in the lookup table.
	 *
	 * @param int    $product_id Value for the "product_id" column.
	 * @param int    $product_or_parent_id Value for the "product_or_parent_id" column.
	 * @param string $attribute_name Taxonomy name of the attribute.
	 * @param array  $terms Term names to insert for the attribute.
	 * @param array  $insert_query_parts Array of strings to add the new query parts to.
	 * @param array  $insert_query_values Array of values to add the new query values to.
	 * @param bool   $in_stock True if the product/variation is in stock, false otherwise.
	 */
	private function compose_lookup_table_insert( $product_id, $product_or_parent_id, $attribute_name, $terms, &$insert_query_parts, &$insert_query_values, $in_stock ) {
		$taxonomy_name     = wc_attribute_taxonomy_name( $attribute_name );
		$term_objects      = get_terms( $taxonomy_name );
		$term_ids_by_names = wp_list_pluck( $term_objects, 'term_id', 'name' );

		foreach ( $terms as $term ) {
			$insert_query_parts[]  = '(%d, %d, %s, %d, %d, %d )';
			$insert_query_values[] = $product_id;
			$insert_query_values[] = $product_or_parent_id;
			$insert_query_values[] = wc_attribute_taxonomy_name( $attribute_name );
			$insert_query_values[] = $term_ids_by_names[ $term ];
			$insert_query_values[] = 0;
			$insert_query_values[] = $in_stock ? 1 : 0;
		}
	}


	/**
	 * Runs an insert clause in the lookup table.
	 * The clauses and values are to be generated with compose_lookup_table_insert.
	 *
	 * @param array $insert_query_parts Array of strings with query parts.
	 * @param array $insert_values Array of values for the query.
	 */
	private function run_lookup_table_insert( $insert_query_parts, $insert_values ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared

		$insert_query =
			"INSERT INTO {$wpdb->prefix}wc_product_attributes_lookup ( product_id, product_or_parent_id, taxonomy, term_id, is_variation_attribute, in_stock ) VALUES "
			. join( ',', $insert_query_parts );

		$prepared_insert = $wpdb->prepare( $insert_query, $insert_values );

		$wpdb->query( $prepared_insert );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}


	/**
	 * Create a product attribute.
	 *
	 * @param string $name The attribute name.
	 * @param array  $terms The terms that will be created for the attribute.
	 */
	private function create_product_attribute( $name, $terms ) {
		$result = $this->do_rest_request( 'products/attributes', array( 'name' => $name ), 'POST' );
		$this->get_instance_of( AttributesHelper::class )->create_taxonomy_for_attribute( $name );
		$attribute_id = $result['id'];

		$attribute_ids_by_name[ wc_sanitize_taxonomy_name( $name ) ] = $attribute_id;

		foreach ( $terms as $term ) {
			$this->do_rest_request( "products/attributes/{$attribute_id}/terms", array( 'name' => $term ), 'POST' );
		}
	}


	/**
	 * Set the "hide out of stock products" option.
	 *
	 * @param bool $hide The value to set the option to.
	 */
	private function set_hide_out_of_stock_items( $hide ) {
		update_option( 'woocommerce_hide_out_of_stock_items', $hide ? 'yes' : 'no' );
	}


	/**
	 * Simulate a product query.
	 *
	 * @param array $filters The attribute filters as an array of attribute name => attribute terms.
	 * @param array $query_types The query types for each attribute as an array of attribute name => "or"/"and".
	 * @return mixed
	 */
	private function do_product_request( $filters, $query_types = array() ) {
		global $wp_the_query;

		foreach ( $filters as $name => $values ) {
			$_GET[ 'filter_' . wc_sanitize_taxonomy_name( $name ) ] = join( ',', array_map( 'wc_sanitize_taxonomy_name', $values ) );
		}

		foreach ( $query_types as $name => $value ) {
			$_GET[ 'query_type_' . wc_sanitize_taxonomy_name( $name ) ] = $value;
		}

		return $wp_the_query->query(
			array(
				'post_type' => 'product',
				'fields'    => 'ids',
			)
		);
	}

	/**
	 * @testdox The product query shows a simple product only if it's not filtered out by the specified attribute filters.
	 *
	 * @testWith [[], "and", true]
	 *           [[], "or", true]
	 *           [["Blue"], "and", true]
	 *           [["Blue"], "or", true]
	 *           [["Blue", "Red"], "and", true]
	 *           [["Blue", "Red"], "or", true]
	 *           [["Green"], "and", false]
	 *           [["Green"], "or", false]
	 *           [["Blue", "Green"], "and", false]
	 *           [["Blue", "Green"], "or", true]
	 *
	 * @param array  $attributes The color attribute names that will be included in the query.
	 * @param string $filter_type The filtering type, "or" or "and".
	 * @param bool   $expected_to_be_visible True if the product is expected to be returned by the query, false otherwise.
	 */
	public function test_filtering_simple_product_in_stock( $attributes, $filter_type, $expected_to_be_visible ) {
		$this->create_product_attribute( 'Color', array( 'Blue', 'Red', 'Green' ) );

		$product = $this->create_simple_product(
			array(
				'Color' => array(
					'Blue',
					'Red',
				),
			),
			true
		);

		$filtered_product_ids = $this->do_product_request( array( 'Color' => $attributes ), array( 'Color' => $filter_type ) );

		if ( $expected_to_be_visible ) {
			$this->assertEquals( array( $product['id'] ), $filtered_product_ids );
		} else {
			$this->assertEmpty( $filtered_product_ids );
		}
	}


	/**
	 * @testdox The product query shows a simple product only if it's in stock OR we don't have "hide out of stock items" set.
	 *
	 * @testWith [false, true, true]
	 *           [false, false, true]
	 *           [true, true, true]
	 *           [true, false, false]
	 *
	 * @param bool $hide_out_of_stock The value of the "hide out of stock products" option.
	 * @param bool $is_in_stock True if the product is in stock, false otherwise.
	 * @param bool $expected_to_be_visible True if the product is expected to be returned by the query, false otherwise.
	 */
	public function test_filtering_simple_product_out_of_stock( $hide_out_of_stock, $is_in_stock, $expected_to_be_visible ) {
		$product = $this->create_simple_product(
			array(),
			$is_in_stock
		);

		$this->set_hide_out_of_stock_items( $hide_out_of_stock );

		$filtered_product_ids = $this->do_product_request( array() );

		if ( $expected_to_be_visible ) {
			$this->assertEquals( array( $product['id'] ), $filtered_product_ids );
		} else {
			$this->assertEmpty( $filtered_product_ids );
		}
	}


	/**
	 * @testdox The product query shows a variable product only if it's not filtered out by the specified attribute filters (for non-variation-defining attributes).
	 *
	 * @testWith [[], "and", true]
	 *           [[], "or", true]
	 *           [["Washable"], "and", true]
	 *           [["Washable"], "or", true]
	 *           [["Washable", "Ironable"], "and", true]
	 *           [["Washable", "Ironable"], "or", true]
	 *           [["Elastic"], "and", false]
	 *           [["Elastic"], "or", false]
	 *           [["Washable", "Elastic"], "and", false]
	 *           [["Washable", "Elastic"], "or", true]
	 *
	 * @param array  $attributes The feature attribute names that will be included in the query.
	 * @param string $filter_type The filtering type, "or" or "and".
	 * @param bool   $expected_to_be_visible True if the product is expected to be returned by the query, false otherwise.
	 */
	public function test_filtering_variable_product_in_stock_for_non_variation_defining_attributes( $attributes, $filter_type, $expected_to_be_visible ) {
		$this->create_product_attribute( 'Color', array( 'Blue', 'Red' ) );
		$this->create_product_attribute( 'Features', array( 'Washable', 'Ironable', 'Elastic' ) );

		$product = $this->create_variable_product(
			array(
				'variation_attributes'     => array(
					'Color' => array( 'Blue', 'Red' ),
				),
				'non_variation_attributes' => array(
					'Features' => array( 'Washable', 'Ironable' ),
				),
				'variations'               => array(
					array(
						'in_stock'            => true,
						'defining_attributes' => array(
							'Color' => 'Blue',
						),
					),
					array(
						'in_stock'            => true,
						'defining_attributes' => array(
							'Color' => 'Red',
						),
					),
				),
			)
		);

		$filtered_product_ids = $this->do_product_request( array( 'Features' => $attributes ), array( 'Features' => $filter_type ) );

		if ( $expected_to_be_visible ) {
			$this->assertEquals( array( $product['id'] ), $filtered_product_ids );
		} else {
			$this->assertEmpty( $filtered_product_ids );
		}
	}


	/**
	 * @testdox The product query shows a variable product only if at least one of the variations is in stock OR we don't have "hide out of stock items" set.
	 *
	 * @testWith [false, true, true, true]
	 *           [false, true, false, true]
	 *           [false, false, false, true]
	 *           [true, true, true, true]
	 *           [true, true, false, true]
	 *           [true, false, false, false]
	 *
	 * @param bool $hide_out_of_stock The value of the "hide out of stock products" option.
	 * @param bool $variation_1_is_in_stock True if the first variation is in stock, false otherwise.
	 * @param bool $variation_2_is_in_stock True if the second variation is in stock, false otherwise.
	 * @param bool $expected_to_be_visible True if the product is expected to be returned by the query, false otherwise.
	 */
	public function test_fii_filtering_variable_product_out_of_stock( $hide_out_of_stock, $variation_1_is_in_stock, $variation_2_is_in_stock, $expected_to_be_visible ) {
		$this->create_product_attribute( 'Color', array( 'Blue', 'Red' ) );

		$product = $this->create_variable_product(
			array(
				'variation_attributes'     => array(
					'Color' => array( 'Blue', 'Red' ),
				),
				'non_variation_attributes' => array(),
				'variations'               => array(
					array(
						'in_stock'            => $variation_1_is_in_stock,
						'defining_attributes' => array(
							'Color' => 'Blue',
						),
					),
					array(
						'in_stock'            => $variation_2_is_in_stock,
						'defining_attributes' => array(
							'Color' => 'Red',
						),
					),
				),
			)
		);

		$this->set_hide_out_of_stock_items( $hide_out_of_stock );

		$filtered_product_ids = $this->do_product_request( array() );

		if ( $expected_to_be_visible ) {
			$this->assertEquals( array( $product['id'] ), $filtered_product_ids );
		} else {
			$this->assertEmpty( $filtered_product_ids );
		}
	}
}