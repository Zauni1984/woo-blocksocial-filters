<?php
/**
 * Keeps the index in sync with product changes.
 *
 * Writes are queued rather than applied inline so that bulk imports, stock
 * syncs and order processing never pay the indexing cost inside the request.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Index;

use BlockSocial\Filters\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks that feed the reindex queue.
 */
class Writer {

	/** @var int[] Product ids collected during the current request. */
	private $pending = array();

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'woocommerce_new_product', array( $this, 'touch' ) );
		add_action( 'woocommerce_update_product', array( $this, 'touch' ) );
		add_action( 'woocommerce_new_product_variation', array( $this, 'touch_variation' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'touch_variation' ) );
		add_action( 'woocommerce_variable_product_sync', array( $this, 'touch' ) );

		add_action( 'woocommerce_product_set_stock', array( $this, 'touch_object' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'touch_object' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'touch' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'touch_variation' ) );

		add_action( 'save_post_product', array( $this, 'touch' ) );
		add_action( 'set_object_terms', array( $this, 'on_set_terms' ), 10, 6 );
		add_action( 'trashed_post', array( $this, 'on_removed' ) );
		add_action( 'untrashed_post', array( $this, 'touch' ) );
		add_action( 'before_delete_post', array( $this, 'on_removed' ) );

		add_action( 'edited_term', array( $this, 'on_term_changed' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_changed' ), 10, 3 );

		add_action( 'shutdown', array( $this, 'flush' ), 5 );
	}

	/**
	 * Queue a product id.
	 *
	 * @param mixed $product_id Product id or object.
	 */
	public function touch( $product_id ): void {
		if ( is_object( $product_id ) && method_exists( $product_id, 'get_id' ) ) {
			$product_id = $product_id->get_id();
		}

		$product_id = absint( $product_id );

		if ( $product_id ) {
			$this->pending[ $product_id ] = $product_id;
		}
	}

	/**
	 * Queue the parent of a variation.
	 *
	 * @param mixed $variation_id Variation id or object.
	 */
	public function touch_variation( $variation_id ): void {
		if ( is_object( $variation_id ) && method_exists( $variation_id, 'get_parent_id' ) ) {
			$this->touch( $variation_id->get_parent_id() );

			return;
		}

		$variation_id = absint( $variation_id );

		if ( ! $variation_id ) {
			return;
		}

		$parent = (int) wp_get_post_parent_id( $variation_id );

		$this->touch( $parent ? $parent : $variation_id );
	}

	/**
	 * Queue from a WC_Product object passed by stock hooks.
	 *
	 * @param mixed $product Product object.
	 */
	public function touch_object( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$parent = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;

		$this->touch( $parent ? $parent : $product->get_id() );
	}

	/**
	 * Queue when terms are assigned to a product.
	 *
	 * @param int    $object_id  Object id.
	 * @param array  $terms      Terms.
	 * @param array  $tt_ids     Term taxonomy ids.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Append flag.
	 * @param array  $old_tt_ids Previous term taxonomy ids.
	 */
	public function on_set_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		unset( $terms, $tt_ids, $append, $old_tt_ids );

		$post_type = get_post_type( $object_id );

		if ( 'product' === $post_type ) {
			$this->touch( $object_id );
		} elseif ( 'product_variation' === $post_type ) {
			$this->touch_variation( $object_id );
		}
	}

	/**
	 * Remove a deleted or trashed product from the index.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_removed( $post_id ): void {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return;
		}

		$type = get_post_type( $post_id );

		if ( 'product' === $type ) {
			bsf()->indexer()->delete_product( $post_id );
			Cache::flush();
		} elseif ( 'product_variation' === $type ) {
			$this->touch_variation( $post_id );
		}
	}

	/**
	 * Flush caches when a filterable term changes.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_changed( $term_id, $tt_id, $taxonomy ): void {
		unset( $term_id, $tt_id );

		if ( 0 === strpos( (string) $taxonomy, 'pa_' ) || in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true ) ) {
			Cache::flush();
		}
	}

	/**
	 * Persist the queue at the end of the request.
	 */
	public function flush(): void {
		if ( empty( $this->pending ) ) {
			return;
		}

		$ids           = array_values( $this->pending );
		$this->pending = array();

		$indexer = bsf()->indexer();
		$async   = bsf()->settings()->bool( 'async_index', true );

		// A handful of edits is applied straight away so the shop stays
		// consistent; bulk changes (imports, stock syncs) go through the queue.
		if ( ! $async || count( $ids ) <= 5 ) {
			$indexer->index_products( $ids );
			Cache::flush();

			return;
		}

		$indexer->enqueue( $ids );
	}
}
