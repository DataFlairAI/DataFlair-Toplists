<?php
/**
 * Toplist Model - Eloquent-like helper for toplists
 * 
 * Usage:
 *   $toplist = Toplist::find(5);
 *   $toplist = Toplist::findByApiId(123);
 *   $toplists = Toplist::where('version', '1.0')->get();
 *   $toplists = Toplist::all();
 */

namespace DataFlair\Toplists\Models;

if (!defined('ABSPATH')) {
    exit;
}

class Toplist {
    
    protected static $table_name = DATAFLAIR_TABLE_NAME;
    
    protected $attributes = array();
    protected $original = array();
    
    protected static $query_where = array();
    protected static $query_order = null;
    protected static $query_limit = null;
    
    /**
     * Find toplist by local ID
     * 
     * @param int $id Local database ID
     * @return Toplist|null
     */
    public static function find($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $id
        ), ARRAY_A);
        
        if (!$row) {
            return null;
        }
        
        return self::make($row);
    }
    
    /**
     * Find toplist by API toplist ID
     * 
     * @param int $api_toplist_id API toplist ID
     * @return Toplist|null
     */
    public static function findByApiId($api_toplist_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE api_toplist_id = %d",
            $api_toplist_id
        ), ARRAY_A);
        
        if (!$row) {
            return null;
        }
        
        return self::make($row);
    }
    
    /**
     * Add where condition
     * 
     * @param string $column Column name
     * @param mixed $value Value
     * @param string $operator Operator (default: =)
     * @return Toplist
     */
    public static function where($column, $value, $operator = '=') {
        self::$query_where[] = array(
            'column' => $column,
            'value' => $value,
            'operator' => $operator
        );
        
        return new static();
    }
    
    /**
     * Add order by clause
     * 
     * @param string $column Column name
     * @param string $direction ASC or DESC
     * @return Toplist
     */
    public static function orderBy($column, $direction = 'ASC') {
        self::$query_order = array(
            'column' => $column,
            'direction' => strtoupper($direction)
        );
        
        return new static();
    }
    
    /**
     * Add limit clause
     * 
     * @param int $limit Limit number
     * @return Toplist
     */
    public static function limit($limit) {
        self::$query_limit = $limit;
        
        return new static();
    }
    
    /**
     * Execute query and get results (for fluent interface)
     * 
     * @return array Array of Toplist instances
     */
    public function get() {
        return self::getResults();
    }
    
    /**
     * Execute query and get results
     * 
     * @return array Array of Toplist instances
     */
    public static function getResults() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $sql = "SELECT * FROM $table_name";
        $where_clauses = array();
        $where_values = array();
        
        // Build WHERE clause
        if (!empty(self::$query_where)) {
            foreach (self::$query_where as $where) {
                $where_clauses[] = $where['column'] . ' ' . $where['operator'] . ' %s';
                $where_values[] = $where['value'];
            }
            
            if (!empty($where_clauses)) {
                $sql .= ' WHERE ' . implode(' AND ', $where_clauses);
            }
        }
        
        // Build ORDER BY clause
        if (self::$query_order) {
            $sql .= ' ORDER BY ' . self::$query_order['column'] . ' ' . self::$query_order['direction'];
        }
        
        // Build LIMIT clause
        if (self::$query_limit) {
            $sql .= ' LIMIT ' . intval(self::$query_limit);
        }
        
        // Reset query builder
        self::resetQuery();
        
        // Execute query
        if (!empty($where_values)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_values), ARRAY_A);
        } else {
            $results = $wpdb->get_results($sql, ARRAY_A);
        }
        
        $toplists = array();
        foreach ($results as $row) {
            $toplists[] = self::make($row);
        }
        
        return $toplists;
    }
    
    /**
     * Get all toplists
     * 
     * @return array Array of Toplist instances
     */
    public static function all() {
        self::resetQuery();
        return self::getResults();
    }
    
    /**
     * Get first result
     * 
     * @return Toplist|null
     */
    public static function first() {
        self::limit(1);
        $results = self::getResults();
        return !empty($results) ? $results[0] : null;
    }
    
    /**
     * Reset query builder
     */
    protected static function resetQuery() {
        self::$query_where = array();
        self::$query_order = null;
        self::$query_limit = null;
    }
    
    /**
     * Create Toplist instance from array
     * 
     * @param array $attributes
     * @return Toplist
     */
    protected static function make($attributes) {
        $instance = new static();
        $instance->attributes = $attributes;
        $instance->original = $attributes;
        return $instance;
    }
    
    /**
     * Get attribute value
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getAttribute($key, $default = null) {
        return isset($this->attributes[$key]) ? $this->attributes[$key] : $default;
    }
    
    /**
     * Get all attributes
     * 
     * @return array
     */
    public function toArray() {
        return $this->attributes;
    }
    
    /**
     * Get decoded data JSON
     * 
     * @return array|null
     */
    public function getData() {
        $data = $this->getAttribute('data');
        if (empty($data)) {
            return null;
        }
        
        $decoded = json_decode($data, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
    
    /**
     * Get toplist items (casinos)
     * 
     * @return array
     */
    public function getItems() {
        $data = $this->getData();
        if (!$data || !isset($data['data']['items'])) {
            return array();
        }
        
        return $data['data']['items'];
    }
    
    /**
     * Check if data is stale
     * 
     * @param int $days Number of days to consider stale (default: 3)
     * @return bool
     */
    public function isStale($days = 3) {
        $last_synced = strtotime($this->getAttribute('last_synced'));
        if (!$last_synced) {
            return true;
        }
        
        $threshold = $days * 24 * 60 * 60;
        return (time() - $last_synced) > $threshold;
    }
    
    /**
     * Magic method to get attributes
     * 
     * @param string $key
     * @return mixed
     */
    public function __get($key) {
        return $this->getAttribute($key);
    }
    
    /**
     * Magic method to check if attribute exists
     * 
     * @param string $key
     * @return bool
     */
    public function __isset($key) {
        return isset($this->attributes[$key]);
    }
    
    /**
     * Get local ID
     * 
     * @return int
     */
    public function getId() {
        return (int) $this->getAttribute('id');
    }
    
    /**
     * Get API toplist ID
     * 
     * @return int
     */
    public function getApiToplistId() {
        return (int) $this->getAttribute('api_toplist_id');
    }
    
    /**
     * Get name
     * 
     * @return string
     */
    public function getName() {
        return $this->getAttribute('name');
    }
    
    /**
     * Get version
     * 
     * @return string|null
     */
    public function getVersion() {
        return $this->getAttribute('version');
    }
}
