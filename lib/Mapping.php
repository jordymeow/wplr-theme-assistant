<?php

/**
 * Mapping
 */
class WPLR_Mapping {
	private
		$fields; // mixed[]

	/**
	 * Creates an instance which has the fields specified with an associative array
	 * @param mixed[] $Fields
	 * @return WPLR_Mapping
	 */
	public static function createFromArray( $Fields ) {
		$r = new self();
		$r->setFields( $Fields );
		return $r;
	}

	/**
	 * Returns the mapping field schema
	 * @return mixed[][][]
	 */
	public static function getFieldSchema() {
		static $r;
		if ( isset( $r ) ) return $r;

		$r = array (
			'id'   => array ( 'default' => 0 ),
			'name' => array ( 'default' => '' ),
			// Collection to Post Type
			'posttype'              => array ( 'default' => '' ),
			'posttype_status'       => array ( 'default' => '' ),
			'posttype_reuse'        => array ( 'default' => false ),
			'posttype_hierarchical' => array ( 'default' => false ),
			'posttype_mode'         => array ( 'default' => '' ),
			'posttype_meta'         => array ( 'default' => '' ),
			// Folder to Taxonomy
			'taxonomy'       => array ( 'default' => '' ),
			'taxonomy_reuse' => array ( 'default' => false ),
			// Keywords to Taxonomy
			'taxonomy_tags'       => array ( 'default' => '' ),
			'taxonomy_tags_reuse' => array ( 'default' => false )
		);

		// Available post types
		$types = self::getPostTypes();
		$r['posttype']['options'] = array ();

		// Available taxonomies
		$taxes = array ();
		$r['taxonomy']['options']      = &$taxes;
		$r['taxonomy_tags']['options'] = &$taxes;

		foreach ( $types as $iType ) {
			// Populate options for the field 'posttype'
			$r['posttype']['options'][$iType->name] = array (
				'value'        => $iType->name,
				'label'        => $iType->label,
				'hierarchical' => $iType->hierarchical
			);
			// Populate taxonomies
			$iTaxes = get_object_taxonomies( $iType->name, 'objects' );
			foreach ( $iTaxes as $jTax ) {
				if ( isset( $taxes[$jTax->name] ) ) {
					$taxes[$jTax->name]['posttype'][] = $iType->name;
					continue;
				}
				$taxes[$jTax->name] = array (
					'value'    => $jTax->name,
					'label'    => $jTax->label,
					'posttype' => array ( $iType->name )
				);
			}
		}
		// Set the default posttype
		$r['posttype']['default'] = reset( $types )->name;

		// Populate 'posttype_status' options
		$statuses = get_post_statuses(); // [name => label]
		$r['posttype_status']['options'] = array ();
		foreach ( $statuses as $i => $iStatus ) {
			$r['posttype_status']['options'][$i] = array (
				'value' => $i,
				'label' => $iStatus
			);
		}
		// Set the default posttype_status
		$r['posttype_status']['default'] = reset( $r['posttype_status']['options'] )['value'];

		// Populate 'posttype_mode' options
		$r['posttype_mode']['options'] = array (
			'gallery' => array (
				'value' => 'gallery',
				'label' => "WP Gallery"
			),
			'meow-gallery-block' => array (
				'value' => 'meow-gallery-block',
				'label' => "Block for Meow Gallery"
			),
			'gallery-shortcode-block' => array (
				'value' => 'gallery-shortcode-block',
				'label' => "Shortcode Block for Gallery"
			),
			'ids_in_post_meta' => array (
				'value' => 'ids_in_post_meta',
				'label' => "Array in Post Meta"
			),
			'ids_in_post_meta_imploded' => array (
				'value' => 'ids_in_post_meta_imploded',
				'label' => "Array in Post Meta (Imploded)"
			),
			'urls_in_post_meta' => array (
				'value' => 'urls_in_post_meta',
				'label' => "Array of (ID -> FullSize) in Post Meta"
			),
			'ids_as_string_in_post_meta' => array (
				'value' => 'ids_as_string_in_post_meta',
				'label' => "Array of IDs as String in Post Meta"
			)
		);
		// Set the default posttype_mode
		$r['posttype_mode']['default'] = reset( $r['posttype_mode']['options'] )['value'];

		return $r;
	}

	/**
	 * Returns all the post types which can be mapped
	 * @return WP_Post_Type[]
	 */
	public static function getPostTypes() {
		static $r;
		if ( isset( $r ) ) return $r;

		$r = get_post_types(
			array (
				/* Have some options? */
				// 'public' => true,
				// '_builtin' => true
			),
			'objects'
		);
		$excludes = array (
			'attachment',
			'revision',
			'nav_menu_item'
		);
		foreach ( $r as $i => $item ) {
			if ( in_array( $item->name, $excludes ) ) unset( $r[$i] );
		}
		return $r;
	}

	public function __construct() {
		$this->fields = array ();
		$this->resetFields();
	}

	public function __get( $Prop ) {
		return $this->getField( $Prop );
	}

	public function __set( $Prop, $Value ) {
		$this->setField( $Prop, $Value );
	}

	/**
	 * Gets a specified field value
	 * @param string $Field
	 * @return mixed
	 */
	public function getField( $Field ) {
		if ( !array_key_exists( $Field, $this->fields ) ) return null; // No such field
		return $this->fields[$Field];
	}

	/**
	 * @return mixed[]
	 */
	public function getFields() {
		return $this->fields;
	}

	/**
	 * Sets a specified field value
	 * @param string $Field
	 * @param mixed $Value
	 * @return WPLR_Mapping This
	 */
	public function setField( $Field, $Value ) {
		if ( !array_key_exists( $Field, $this->fields ) ) return $this; // No such field
		$this->fields[$Field] = $Value;
		return $this;
	}

	/**
	 * Sets multiple field values in bulk
	 * @param mixed[] $Fields
	 * @param boolean $Sanitizes=false Whether or not to sanitize the fields
	 * @return WPLR_Mapping This
	 */
	public function setFields( $Fields, $Sanitizes = false ) {
		foreach ( $Fields as $i => $item ) $this->setField( $i, $item );
		if ( $Sanitizes ) $this->sanitizeFields();
		return $this;
	}

	/**
	 * Reset all the fields to its default value
	 * @return WPLR_Mapping This
	 */
	public function resetFields() {
		$schema = self::getFieldSchema();
		foreach ( $schema as $i => $item ) $this->fields[$i] = $item['default'];
		return $this;
	}

	/**
	 * Sanitizes all the field values
	 */
	public function sanitizeFields() {
		$schema = self::getFieldSchema();

		// Set default values
		foreach ( $schema as $i => $item ) {
			$default = $item['default'];
			if ( $default === $this->fields[$i] ) continue;
			$type = gettype( $default );
			if ( $type != gettype( $this->fields[$i] ) ) settype( $this->fields[$i], $type );
			if ( $type == 'boolean' ) continue;
			if ( $this->fields[$i] ) continue;
			$this->fields[$i] = $default;
		}

		$pType = $this->fields['posttype'];

		// Sanitize posttype_hierarchical
		if ( $this->fields['posttype_hierarchical'] ) {
			if (
				!$pType ||
				!isset( $schema['posttype']['options'][$pType] ) ||
				!$schema['posttype']['options'][$pType]['hierarchical']

			) $this->fields['posttype_hierarchical'] = false;
		}

		// Sanitize taxonomy
		if ( $tax = $this->fields['taxonomy'] ) {
			if (
				!$pType ||
				!isset( $schema['taxonomy']['options'][$tax] ) ||
				!in_array( $pType, $schema['taxonomy']['options'][$tax]['posttype'] )
			) {
				$this->fields['taxonomy'] = '';
				$this->fields['taxonomy_reuse'] = false;
			}
		}

		// Sanitize taxonomy_tags
		if ( $tax = $this->fields['taxonomy_tags'] ) {
			if (
				!$pType ||
				!isset( $schema['taxonomy_tags']['options'][$tax] ) ||
				!in_array( $pType, $schema['taxonomy_tags']['options'][$tax]['posttype'] )
			) {
				$this->fields['taxonomy_tags'] = '';
				$this->fields['taxonomy_tags_reuse'] = false;
			}
		}
	}

	/**
	 * Converts this mapping to an array
	 * @return mixed[]
	 */
	public function toArray() {
		return $this->fields;
	}
}
