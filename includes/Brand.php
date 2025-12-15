<?php
/**
 * Brand Model - Eloquent-like helper for brands
 * 
 * Usage:
 *   $brand = Brand::find(12);
 *   $brand = Brand::findByApiId(123);
 *   $brand = Brand::findBySlug('brand-slug');
 *   $brands = Brand::where('status', 'active')->get();
 *   $brands = Brand::all();
 */

namespace DataFlair\Toplists\Models;

if (!defined('ABSPATH')) {
    exit;
}

class Brand {
    
    protected static $table_name = DATAFLAIR_BRANDS_TABLE_NAME;
    
    protected $attributes = array();
    protected $original = array();
    
    protected static $query_where = array();
    protected static $query_order = null;
    protected static $query_limit = null;
    
    /**
     * Find brand by local ID
     * 
     * @param int $id Local database ID
     * @return Brand|null
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
     * Find brand by API brand ID
     * 
     * @param int $api_brand_id API brand ID
     * @return Brand|null
     */
    public static function findByApiId($api_brand_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE api_brand_id = %d",
            $api_brand_id
        ), ARRAY_A);
        
        if (!$row) {
            return null;
        }
        
        return self::make($row);
    }
    
    /**
     * Find brand by slug
     * 
     * @param string $slug Brand slug
     * @return Brand|null
     */
    public static function findBySlug($slug) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE slug = %s",
            $slug
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
     * @return Brand
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
     * @return Brand
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
     * @return Brand
     */
    public static function limit($limit) {
        self::$query_limit = $limit;
        
        return new static();
    }
    
    /**
     * Execute query and get results (for fluent interface)
     * 
     * @return array Array of Brand instances
     */
    public function get() {
        return self::getResults();
    }
    
    /**
     * Execute query and get results
     * 
     * @return array Array of Brand instances
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
        
        $brands = array();
        foreach ($results as $row) {
            $brands[] = self::make($row);
        }
        
        return $brands;
    }
    
    /**
     * Get all brands
     * 
     * @return array Array of Brand instances
     */
    public static function all() {
        self::resetQuery();
        return self::getResults();
    }
    
    /**
     * Get first result
     * 
     * @return Brand|null
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
     * Create Brand instance from array
     * 
     * @param array $attributes
     * @return Brand
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
     * Get product types as array
     * 
     * @return array
     */
    public function getProductTypes() {
        $product_types = $this->getAttribute('product_types');
        if (empty($product_types)) {
            return array();
        }
        
        return array_map('trim', explode(',', $product_types));
    }
    
    /**
     * Get licenses as array
     * 
     * @return array
     */
    public function getLicenses() {
        $licenses = $this->getAttribute('licenses');
        if (empty($licenses)) {
            return array();
        }
        
        return array_map('trim', explode(',', $licenses));
    }
    
    /**
     * Get top geos as array
     * 
     * @return array
     */
    public function getTopGeos() {
        $top_geos = $this->getAttribute('top_geos');
        if (empty($top_geos)) {
            return array();
        }
        
        return array_map('trim', explode(',', $top_geos));
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
     * Get API brand ID
     * 
     * @return int
     */
    public function getApiBrandId() {
        return (int) $this->getAttribute('api_brand_id');
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
     * Get slug
     * 
     * @return string
     */
    public function getSlug() {
        return $this->getAttribute('slug');
    }
    
    /**
     * Get status
     * 
     * @return string
     */
    public function getStatus() {
        return $this->getAttribute('status');
    }
}
