<?php
/**
 * Plugin Name: Pictufy Integration
 * Description: Integrates Pictufy catalog (Collections, Artists, Artworks) into WooCommerce site
 * Version: 1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('PICTUFY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PICTUFY_PLUGIN_URL', plugin_dir_url(__FILE__));

// Pictufy API Configuration
class Pictufy_API {
    private $api_url = 'https://pictufy.com/api/';
    private $api_key = 'YOUR_API_KEY_HERE'; // Replace with actual API key
    
    public function __construct() {
        // Constructor
    }
    
    // Fetch Collections
    public function get_collections($category = '', $order = 'curated') {
        $endpoint = $this->api_url . 'collections';
        
        $args = array(
            'headers' => array(
                'X-AUTH-KEY' => $this->api_key
            ),
            'body' => array(
                'order' => $order
            )
        );
        
        if (!empty($category)) {
            $args['body']['collection_category'] = $category;
        }
        
        $response = wp_remote_post($endpoint, $args);
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    // Fetch Artists
    public function get_artists() {
        $endpoint = $this->api_url . 'artists';
        
        $args = array(
            'headers' => array(
                'X-AUTH-KEY' => $this->api_key
            )
        );
        
        $response = wp_remote_get($endpoint, $args);
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    // Fetch Artworks (Explore section)
    public function get_artworks($limit = 50, $offset = 0) {
        $endpoint = $this->api_url . 'artworks';
        
        $args = array(
            'headers' => array(
                'X-AUTH-KEY' => $this->api_key
            ),
            'body' => array(
                'limit' => $limit,
                'offset' => $offset
            )
        );
        
        $response = wp_remote_post($endpoint, $args);
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
}

// Initialize API
$pictufy_api = new Pictufy_API();

// Shortcode for Collections
function pictufy_collections_shortcode($atts) {
    global $pictufy_api;
    
    $atts = shortcode_atts(array(
        'category' => '',
        'order' => 'curated'
    ), $atts);
    
    $collections = $pictufy_api->get_collections($atts['category'], $atts['order']);
    
    if (isset($collections['error'])) {
        return '<p>Error loading collections: ' . esc_html($collections['error']) . '</p>';
    }
    
    ob_start();
    ?>
    <div class="pictufy-collections">
        <h2>Collections</h2>
        <div class="collections-grid">
            <?php if (isset($collections['items']) && is_array($collections['items'])): ?>
                <?php foreach ($collections['items'] as $collection): ?>
                    <?php if (isset($collection['collections']) && is_array($collection['collections'])): ?>
                        <?php foreach ($collection['collections'] as $col): ?>
                            <div class="collection-item">
                                <?php if (isset($col['cover'])): ?>
                                    <img src="<?php echo esc_url($col['cover']); ?>" alt="<?php echo esc_attr($col['name']); ?>">
                                <?php endif; ?>
                                <h3><?php echo esc_html($col['name']); ?></h3>
                                <p><?php echo esc_html($col['description']); ?></p>
                                <a href="<?php echo esc_url($col['url']); ?>" target="_blank">View Collection</a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No collections found.</p>
            <?php endif; ?>
        </div>
    </div>
    <style>
        .pictufy-collections {
            padding: 20px;
        }
        .collections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .collection-item {
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .collection-item img {
            max-width: 100%;
            height: auto;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .collection-item h3 {
            margin: 10px 0;
            font-size: 18px;
        }
        .collection-item a {
            display: inline-block;
            margin-top: 10px;
            padding: 8px 16px;
            background: #333;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('pictufy_collections', 'pictufy_collections_shortcode');

// Shortcode for Artists
function pictufy_artists_shortcode() {
    global $pictufy_api;
    
    $artists = $pictufy_api->get_artists();
    
    if (isset($artists['error'])) {
        return '<p>Error loading artists: ' . esc_html($artists['error']) . '</p>';
    }
    
    ob_start();
    ?>
    <div class="pictufy-artists">
        <h2>Artists</h2>
        <div class="artists-grid">
            <?php if (isset($artists['items']) && is_array($artists['items'])): ?>
                <?php foreach ($artists['items'] as $artist): ?>
                    <div class="artist-item">
                        <?php if (isset($artist['image'])): ?>
                            <img src="<?php echo esc_url($artist['image']); ?>" alt="<?php echo esc_attr($artist['name']); ?>">
                        <?php endif; ?>
                        <h3><?php echo esc_html($artist['name']); ?></h3>
                        <?php if (isset($artist['bio'])): ?>
                            <p><?php echo esc_html($artist['bio']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No artists found.</p>
            <?php endif; ?>
        </div>
    </div>
    <style>
        .pictufy-artists {
            padding: 20px;
        }
        .artists-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .artist-item {
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .artist-item img {
            max-width: 100%;
            height: auto;
            border-radius: 50%;
            margin-bottom: 10px;
        }
        .artist-item h3 {
            margin: 10px 0;
            font-size: 16px;
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('pictufy_artists', 'pictufy_artists_shortcode');

// Shortcode for Artworks (Explore)
function pictufy_artworks_shortcode($atts) {
    global $pictufy_api;
    
    $atts = shortcode_atts(array(
        'limit' => 20,
        'offset' => 0
    ), $atts);
    
    $artworks = $pictufy_api->get_artworks($atts['limit'], $atts['offset']);
    
    if (isset($artworks['error'])) {
        return '<p>Error loading artworks: ' . esc_html($artworks['error']) . '</p>';
    }
    
    ob_start();
    ?>
    <div class="pictufy-artworks">
        <h2>Explore Artworks</h2>
        <div class="artworks-grid">
            <?php if (isset($artworks['items']) && is_array($artworks['items'])): ?>
                <?php foreach ($artworks['items'] as $artwork): ?>
                    <div class="artwork-item">
                        <?php if (isset($artwork['image'])): ?>
                            <img src="<?php echo esc_url($artwork['image']); ?>" alt="<?php echo esc_attr($artwork['title']); ?>">
                        <?php endif; ?>
                        <h3><?php echo esc_html($artwork['title']); ?></h3>
                        <?php if (isset($artwork['artist'])): ?>
                            <p class="artist-name"><?php echo esc_html($artwork['artist']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No artworks found.</p>
            <?php endif; ?>
        </div>
    </div>
    <style>
        .pictufy-artworks {
            padding: 20px;
        }
        .artworks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .artwork-item {
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
        }
        .artwork-item img {
            max-width: 100%;
            height: auto;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .artwork-item h3 {
            margin: 10px 0;
            font-size: 14px;
        }
        .artist-name {
            font-size: 12px;
            color: #666;
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('pictufy_artworks', 'pictufy_artworks_shortcode');