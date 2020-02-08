<?php
require_once __DIR__ .'/Mapping.php';

/**
 * Mappings manager.
 * Designed as Singleton
 */
class WPLR_MappingsManager {
	const
		SAVE_KEY = 'wplr_themex_mappings';

	private static
		$instance; // Singleton instance

	private
		$items; // WPLR_Mapping[]

	/**
	 * Returns a singleton instance
	 * @return WPLR_MappingsManager
	 */
	public static function instance() {
		if ( !self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		$this->items = array ();
		$this->load();
	}

	/**
	 * @param int $Index
	 * @return WPLR_Mapping
	 */
	public function get( $Index ) {
		return array_key_exists( $Index, $this->items ) ? $this->items[$Index] : null;
	}

	/**
	 * Returns all the mappings
	 * @return WPLR_Mapping[]
	 */
	public function getAll() {
		return $this->items;
	}

	/**
	 * @param int $Id
	 * @return WPLR_Mapping
	 */
	public function getById( $Id ) {
		$id = (int) $Id;
		foreach ( $this->items as $item ) {
			if ( $item->getField( 'id' ) == $id ) return $item;
		}
		return null;
	}

	public function clear() {
		array_splice( $this->items, 0, count( $this->items ) );
		return $this;
	}

	/**
	 * Generates an ID number for the next incoming item
	 * @return int
	 */
	private function nextId() {
		$r = 1;
		foreach ( $this->items as $item ) {
			$id = $item->getField( 'id' );
			if ( $id >= $r ) $r = $id + 1;
		}
		return $r;
	}

	/**
	 * Creates & Adds a new draft mapping
	 * @return WPLR_Mapping
	 */
	public function newDraft() {
		$newItem = new WPLR_Mapping();
		$this->add( $newItem );
		return $newItem;
	}

	/**
	 * Adds a mapping to this manager
	 * @param WPLR_Mapping $Item
	 * @param int $Index=null
	 * @return WPLR_MappingsManager This
	 */
	public function add( $Item, $Index = null ) {
		if ( !$Item->getField( 'id' ) ) $Item->setField( 'id', $this->nextId() ); // Generate ID
		if ( is_int( $Index ) ) {
			// Insert $Item
			array_splice( $this->items, $Index, 0, array ( $Item ) ); // This is ugly hack :P
				// NOTE: To insert an object into an array by array_splice(),
				//       you need to wrap the object with another array.
				//       @see also http://php.net/manual/en/function.array-splice.php
		}
		else $this->items[] = $Item;
		return $this;
	}

	/**
	 * Removes a mapping from this manager
	 * @param int $Index
	 * @return WPLR_Mapping The removed item
	 */
	public function remove( $Index ) {
		$r = $this->get( $Index );
		if ( !$r ) return null; // Index out of bounds
		array_splice( $this->items, $Index, 1 );
		return $r;
	}

	/**
	 * @param int $Id
	 * @return WPLR_Mapping The removed item
	 */
	public function removeById( $Id ) {
		$id = (int) $Id;
		foreach ( $this->items as $i => $item ) {
			if ( $item->getField( 'id' ) == $id ) return $this->remove( $i );
		}
		return null;
	}

	/**
	 * Converts to an array
	 * @return array[]
	 */
	public function toArray() {
		$r = array ();
		foreach ( $this->items as $item ) $r[] = $item->toArray();
		return $r;
	}

	/**
	 * Stores all the mappings to DB
	 */
	public function save() {
		update_option( self::SAVE_KEY, $this->toArray(), false );
	}

	/**
	 * Restores all the mappings from DB
	 * @param string $Data=null
	 */
	public function load( $Data = null ) {
		$this->clear();
		$data = is_null( $Data ) ? get_option( self::SAVE_KEY, null ) : $Data;
		if ( !$data ) return; // No data
		if ( !is_array( $data ) ) return; // Broken data

		foreach ( $data as $item ) {
			if ( !is_array( $item ) ) continue; // Broken data
			$this->add( WPLR_Mapping::createFromArray( $item ) );
		}
		return true;
	}
}
