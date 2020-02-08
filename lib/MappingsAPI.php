<?php
require_once __DIR__ . '/MappingsManager.php';

/**
 * RESTful API for manipulating mappings
 * @author amekusa
 */
class WPLR_MappingsAPI {
	const
		NS = 'wplr-maps/v1'; // Namespace

	private static
		$instance; // Singleton instance

	/**
	 * Returns a singleton instance
	 * @return WPLR_MappingsAPI
	 */
	public static function instance() {
		if ( !self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array ( $this, 'setup' ) );
	}

	public function setup() {
		$this->addRoute( '/maps/schema/', 'GET', 'getMappingFieldSchema' );

		// CRUD APIs
		$id    = '(?P<id>[\d]+)';
		$index = '(?P<index>[\d]+)';
		$this->addRoute( '/maps/',         'POST',   'create' );
		$this->addRoute( "/maps/{$index}", 'POST',   'create' );
		$this->addRoute( '/maps/',         'GET',    'fetch' );
		$this->addRoute( "/maps/{$id}",    'GET',    'fetch' );
		$this->addRoute( "/maps/{$id}",    'PUT',    'update' );
		$this->addRoute( "/maps/{$id}",    'DELETE', 'delete' );
	}

	private function addRoute( $Route, $Method, $Fn, $Args = array () ) {
		register_rest_route( self::NS, $Route, array (
			'methods' => $Method,
			'callback' => array ( $this, $Fn ),
			'args' => $Args,
			'permission_callback' => array ( $this, 'isAvailable' )
		) );
	}

	/**
	 * Returns if the entire API is available at the current context
	 * @return boolean
	 */
	public function isAvailable() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param mixed $Payload
	 * @param number $Status
	 * @param array|string $Headers
	 * @return WP_REST_Response
	 */
	private function response( $Payload, $Status = 200, $Headers = array () ) {
		return new WP_REST_Response(
			$Payload,
			$Status,
			is_array( $Headers ) ? $Headers : array ( $Headers )
		);
	}

	/**
	 * Returns the mapping field schema
	 * @method GET
	 * @return WP_REST_Response
	 */
	public function getMappingFieldSchema() {
		return $this->response( WPLR_Mapping::getFieldSchema() );
	}

	/**
	 * Returns the mapping specified by id.
	 * If id was omitted, returns all the existing mappings
	 * @method GET
	 * @return WP_REST_Response
	 */
	public function fetch( WP_REST_Request $Rq ) {
		$id = $Rq->get_param( 'id' );

		$maps = WPLR_MappingsManager::instance();
		if ( is_null( $id ) ) return $this->response( $maps->toArray() );

		$id = (int) $id;
		$map = $maps->getById( $id );
		if ( !$map ) return $this->response( "Resource Not Found", 404 );

		return $this->response( $map->toArray() );
	}

	/**
	 * Creates a new mapping
	 * @method POST
	 */
	public function create( WP_REST_Request $Rq ) {
		$index  = $Rq->get_param( 'index' );
		$fields = $Rq->get_param( 'fields' );
		if ( is_numeric( $index ) ) $index = (int) $index;

		$maps = WPLR_MappingsManager::instance();
		$map = new WPLR_Mapping();
		if ( is_array( $fields ) ) {
			if ( isset( $fields['id'] ) ) unset( $fields['id'] );
			$map->setFields( $fields, true );
		}
		$maps->add( $map, $index );
		try {
			$maps->save();
		} catch ( Exception $e ) {
			return $this->response( $e->getMessage(), 500 );
		}
		return $this->response( $map->toArray(), 201 ); // 201: Created
	}

	/**
	 * Updates an existing mapping
	 * @method PUT
	 * @param WP_REST_Request $Rq
	 * @return WP_REST_Response
	 */
	public function update( WP_REST_Request $Rq ) {
		$id     = (int) $Rq->get_param( 'id' );
		$fields = $Rq->get_param( 'fields' );

		$maps = WPLR_MappingsManager::instance();
		$map = $maps->getById( $id );
		if ( !$map ) return $this->response( "Resource Not Found", 404 );
		$map->setFields( $fields, true );
		try {
			$maps->save();
		} catch ( Exception $e ) {
			return $this->response( $e->getMessage(), 500 );
		}
		return $this->response( $map->toArray() );
	}

	/**
	 * Deletes a mapping
	 * @method DELETE
	 * @param WP_REST_Request $Rq
	 * @return WP_REST_Response
	 */
	public function delete( WP_REST_Request $Rq ) {
		$id = (int) $Rq->get_param( 'id' );

		$maps = WPLR_MappingsManager::instance();
		$map = $maps->removeById( $id );
		if ( !$map ) return $this->response( "Resource Not Found", 404 );
		try {
			$maps->save();
		} catch ( Exception $e ) {
			return $this->response( $e->getMessage(), 500 );
		}
		return $this->response( "Deleted" );
	}
}
