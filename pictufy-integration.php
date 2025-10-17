<?php
/**
 * Plugin Name: Pictufy Integration
 * Description: Integrates Pictufy catalog (Collections, Artists, Artworks) into WooCommerce site
 * Version: 1.9
 * Author: totmarc
 */

if (!defined('ABSPATH')) {
    exit;
}

function pictufy_schedule_expired_cleanup() {
    if (!wp_next_scheduled('pictufy_expired_artworks_cleanup')) {
        wp_schedule_event(time(), 'weekly', 'pictufy_expired_artworks_cleanup');
    }
}

function pictufy_unschedule_expired_cleanup() {
    $timestamp = wp_next_scheduled('pictufy_expired_artworks_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'pictufy_expired_artworks_cleanup');
    }
}

function pictufy_handle_expired_artworks_cleanup() {
    $api = pictufy_get_api();
    if (!$api) {
        return;
    }

    $page = 1;
    $per_page = 200;
    $processed_ids = array();
    $has_more = true;

    while ($has_more) {
        $response = $api->get_expired_artworks(array(
            'page' => $page,
            'per_page' => $per_page,
        ));

        if (!is_array($response) || isset($response['error'])) {
            if (defined('WP_DEBUG') && WP_DEBUG && isset($response['error'])) {
                error_log('Pictufy expired fetch error: ' . $response['error']);
            }
            break;
        }

        $items = isset($response['items']) && is_array($response['items']) ? $response['items'] : array();

        if (empty($items)) {
            break;
        }

        foreach ($items as $item) {
            if (empty($item['artwork_id'])) {
                continue;
            }

            $artwork_id = (string) $item['artwork_id'];

            if (isset($processed_ids[$artwork_id])) {
                continue;
            }

            $processed_ids[$artwork_id] = true;

            do_action('pictufy_artwork_expired', $item);
        }

        $returned = isset($response['status']['returned_items']) ? (int) $response['status']['returned_items'] : count($items);

        if ($returned < $per_page) {
            $has_more = false;
        } else {
            $page++;
        }
    }
}
add_action('pictufy_expired_artworks_cleanup', 'pictufy_handle_expired_artworks_cleanup');

function pictufy_remove_expired_artwork($item) {
    if (empty($item['artwork_id'])) {
        return;
    }

    $artwork_id = (string) $item['artwork_id'];

    $removed_post_id = 0;

    $post_ids = get_posts(array(
        'post_type' => 'product',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => '_pictufy_artwork_id',
                'value' => $artwork_id,
                'compare' => '=',
            ),
        ),
    ));

    if (!empty($post_ids)) {
        $removed_post_id = (int) $post_ids[0];

        wp_trash_post($removed_post_id);

        $gallery = get_post_meta($removed_post_id, '_product_image_gallery', true);
        if (!empty($gallery)) {
            $ids = array_map('intval', explode(',', $gallery));
            foreach ($ids as $image_id) {
                wp_delete_attachment($image_id, true);
            }
        }

        $thumbnail_id = get_post_thumbnail_id($removed_post_id);
        if ($thumbnail_id) {
            wp_delete_attachment($thumbnail_id, true);
        }
    }

    $transient_key = 'pictufy_artwork_' . md5($artwork_id);
    delete_transient($transient_key);

    do_action('pictufy_artwork_expired_removed', $item, $removed_post_id);
}
add_action('pictufy_artwork_expired', 'pictufy_remove_expired_artwork', 10, 1);

function pictufy_normalize_artwork_filters($filters) {
    $allowed = array(
        'artwork_type',
        'geometry',
        'color',
        'resolution',
        'order',
        'category',
        'search',
        'people',
        'animals',
        'buildings',
        'nudity',
        'custom_interiors',
        'grade',
        'collection_id',
        'artist_id',
    );

    $normalized = array();

    foreach ((array) $filters as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }

        if (!in_array($key, $allowed, true)) {
            continue;
        }

        switch ($key) {
            case 'resolution':
            case 'grade':
            case 'artist_id':
                $normalized[$key] = (int) $value;
                break;
            case 'custom_interiors':
                $normalized[$key] = (int) (bool) $value;
                break;
            default:
                $normalized[$key] = sanitize_text_field($value);
                break;
        }
    }

    return $normalized;
}

function pictufy_get_section_urls() {
    return array(
        'collections' => pictufy_get_page_url_by_shortcode('pictufy_collections'),
        'artists' => pictufy_get_page_url_by_shortcode('pictufy_artists'),
        'artworks' => pictufy_get_page_url_by_shortcode('pictufy_artworks'),
    );
}

function pictufy_get_page_url_by_shortcode($shortcode_tag) {
    if (empty($shortcode_tag)) {
        return '';
    }

    $pages = get_pages(array('number' => 0));

    foreach ((array) $pages as $page) {
        if (!isset($page->post_content) || stripos($page->post_content, '[' . $shortcode_tag) === false) {
            continue;
        }

        return get_permalink($page->ID);
    }

    return '';
}

function pictufy_render_artists_script() {
    static $printed = false;

    if ($printed) {
        return;
    }

    $printed = true;
    ?>
    <script>
        (function () {
            const initArtists = () => {
                const containers = document.querySelectorAll('.pictufy-artists[data-json-id]');

                if (!containers.length) {
                    return;
                }

                containers.forEach((container) => {
                    const dataScriptId = container.getAttribute('data-json-id');
                    const chunk = parseInt(container.getAttribute('data-chunk-size'), 10) || 24;
                    const initialCount = parseInt(container.getAttribute('data-initial-count'), 10) || 0;
                    const grid = container.querySelector('.artists-grid');
                    const loadMoreButton = container.querySelector('.pictufy-artists-load');

                    let allItems = [];
                    let renderedCount = initialCount;

                    if (dataScriptId) {
                        const script = document.getElementById(dataScriptId);
                        if (script) {
                            try {
                                allItems = JSON.parse(script.textContent || '[]');
                            } catch (error) {
                                console.error('Pictufy artists JSON parse error', error);
                            }
                        }
                    }

                    const createCard = (item) => {
                        const card = document.createElement('div');
                        card.className = 'artist-item';

                        if (item.image) {
                            const img = document.createElement('img');
                            img.src = item.image;
                            img.alt = item.name || '';
                            img.loading = 'lazy';
                            img.decoding = 'async';
                            img.fetchPriority = 'low';
                            card.appendChild(img);
                        }

                        if (item.name) {
                            const heading = document.createElement('h3');
                            heading.textContent = item.name;
                            card.appendChild(heading);
                        }

                        if (item.username) {
                            const username = document.createElement('p');
                            username.className = 'artist-username';
                            username.textContent = '@' + item.username;
                            card.appendChild(username);
                        }

                        if (item.type || (item.artworks && item.artworks > 0)) {
                            const meta = document.createElement('div');
                            meta.className = 'artist-meta';

                            if (item.type) {
                                const type = document.createElement('span');
                                type.className = 'artist-type';
                                type.textContent = item.type;
                                meta.appendChild(type);
                            }

                            if (item.artworks && item.artworks > 0) {
                                const count = document.createElement('span');
                                count.className = 'artist-count';
                                count.textContent = new Intl.NumberFormat().format(item.artworks) + ' artworks';
                                meta.appendChild(count);
                            }

                            card.appendChild(meta);
                        }

                        return card;
                    };

                    const renderMore = () => {
                        if (!grid || !allItems.length) {
                            return;
                        }

                        const nextItems = allItems.slice(renderedCount, renderedCount + chunk);

                        nextItems.forEach((item) => {
                            grid.appendChild(createCard(item));
                        });

                        renderedCount += nextItems.length;

                        if (renderedCount >= allItems.length && loadMoreButton) {
                            loadMoreButton.remove();
                        }
                    };

                    if (loadMoreButton) {
                        loadMoreButton.addEventListener('click', renderMore);
                    }
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initArtists);
            } else {
                initArtists();
            }
        })();
    </script>
    <?php
}

function pictufy_get_collection_detail_url($slug, $page = 1) {
    if (empty($slug)) {
        return '';
    }

    $base = trailingslashit(home_url('collection/' . $slug));

    if ($page > 1) {
        return trailingslashit($base . 'page/' . (int) $page);
    }

    return $base;
}

function pictufy_build_artist_slug_from_data($artist) {
    $slug = '';

    if (is_array($artist)) {
        if (!empty($artist['username'])) {
            $slug = sanitize_title($artist['username']);
        } elseif (!empty($artist['name'])) {
            $slug = sanitize_title($artist['name']);
        }

        if (!empty($artist['artist_id'])) {
            $suffix = (string) (int) $artist['artist_id'];
            if (!empty($slug)) {
                $slug .= '-' . $suffix;
            } else {
                $slug = $suffix;
            }
        }
    }

    return $slug;
}

function pictufy_get_artist_detail_url($artist, $page = 1) {
    if (is_array($artist)) {
        $slug = isset($artist['slug']) ? $artist['slug'] : pictufy_build_artist_slug_from_data($artist);
    } else {
        $slug = (string) $artist;
    }

    if (empty($slug)) {
        return '';
    }

    $base = trailingslashit(home_url('artist/' . $slug));

    if ($page > 1) {
        return trailingslashit($base . 'page/' . (int) $page);
    }

    return $base;
}

function pictufy_extract_artist_id_from_slug($slug) {
    if (empty($slug)) {
        return 0;
    }

    if (ctype_digit((string) $slug)) {
        return (int) $slug;
    }

    $parts = explode('-', $slug);
    $tail = array_pop($parts);

    if ($tail !== null && ctype_digit($tail)) {
        return (int) $tail;
    }

    return 0;
}

function pictufy_find_artist_by_slug($slug) {
    if (empty($slug)) {
        return array('error' => __('Artist not specified.', 'pictufy-integration'));
    }

    $api = pictufy_get_api();
    $artist = null;
    $last_error = '';

    $artist_id = pictufy_extract_artist_id_from_slug($slug);

    if ($artist_id) {
        $response = $api->get_artist($artist_id);

        if (isset($response['error'])) {
            $last_error = $response['error'];
        } elseif (!empty($response['items'][0]) && is_array($response['items'][0])) {
            $artist = $response['items'][0];
        }
    }

    if ($artist === null) {
        $orders = array('trending', 'artwork_count', 'alpha');

        foreach ($orders as $order) {
            $response = $api->get_artists(array('order' => $order));

            if (isset($response['error'])) {
                $last_error = $response['error'];
                continue;
            }

            if (empty($response['items']) || !is_array($response['items'])) {
                continue;
            }

            foreach ($response['items'] as $candidate) {
                $candidate_slug = pictufy_build_artist_slug_from_data($candidate);
                if ($candidate_slug === $slug) {
                    $artist = $candidate;
                    break 2;
                }
            }
        }
    }

    if ($artist === null) {
        if (!empty($last_error)) {
            return array('error' => $last_error);
        }

        return array('error' => __('Artist not found.', 'pictufy-integration'));
    }

    $artist['slug'] = pictufy_build_artist_slug_from_data($artist);

    return $artist;
}

function pictufy_register_collection_routes() {
    add_rewrite_rule('^collection/([^/]+)/page/([0-9]+)/?$', 'index.php?pictufy_collection=$matches[1]&pictufy_page=$matches[2]', 'top');
    add_rewrite_rule('^collection/([^/]+)/?$', 'index.php?pictufy_collection=$matches[1]', 'top');
    add_rewrite_rule('^artist/([^/]+)/page/([0-9]+)/?$', 'index.php?pictufy_artist=$matches[1]&pictufy_page=$matches[2]', 'top');
    add_rewrite_rule('^artist/([^/]+)/?$', 'index.php?pictufy_artist=$matches[1]', 'top');
}
add_action('init', 'pictufy_register_collection_routes', 5);

function pictufy_add_query_vars($vars) {
    $vars[] = 'pictufy_collection';
    $vars[] = 'pictufy_page';
    $vars[] = 'pictufy_artist';
    
    return $vars;
}
add_filter('query_vars', 'pictufy_add_query_vars');

function pictufy_collection_body_class($classes) {
    if (!empty(get_query_var('pictufy_collection'))) {
        $classes[] = 'pictufy-collection-page';
        $classes[] = 'no-sidebar';
    }

    return $classes;
}
add_filter('body_class', 'pictufy_collection_body_class');

function pictufy_artist_body_class($classes) {
    if (!empty(get_query_var('pictufy_artist'))) {
        $classes[] = 'pictufy-artist-page';
        $classes[] = 'no-sidebar';
    }

    return $classes;
}
add_filter('body_class', 'pictufy_artist_body_class');

function pictufy_render_collection_template() {
    $slug = get_query_var('pictufy_collection');

    if (empty($slug)) {
        return;
    }

    $page = max(1, (int) get_query_var('pictufy_page', 1));
    $per_page = 12;

    $collection = pictufy_find_collection_by_slug($slug);

    if (isset($collection['error'])) {
        $error_message = $collection['error'];
        $collection = null;
    }

    if ($collection === null || empty($collection) || !empty($error_message)) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        if (!empty($error_message)) {
            wp_die(esc_html($error_message));
        }
        include get_404_template();
        exit;
    }

    // If found collection lacks detail URL (cache miss), force refresh once.
    if (empty($collection['detail_url'])) {
        $refreshed = pictufy_get_collections_data(array(), true);
        if (empty($collection['slug']) && isset($refreshed['items'])) {
            foreach ($refreshed['items'] as $item) {
                if (!empty($item['slug']) && $item['slug'] === $slug) {
                    $collection = $item;
                    break;
                }
            }
        }
    }

    $collection_identifier = '';

    if (!empty($collection['slug'])) {
        $collection_identifier = $collection['slug'];
    } elseif (!empty($collection['id'])) {
        $collection_identifier = $collection['id'];
    } elseif (!empty($collection['external_url'])) {
        $collection_identifier = $collection['external_url'];
    }

    $api = pictufy_get_api();
    $artworks_response = $api->get_artworks(array(
        'collection_id' => $collection_identifier,
        'page' => $page,
        'per_page' => $per_page,
    ));

    $artwork_items = array();
    $artwork_error = '';

    if (isset($artworks_response['error'])) {
        $artwork_error = $artworks_response['error'];
    } elseif (!empty($artworks_response['items']) && is_array($artworks_response['items'])) {
        foreach ($artworks_response['items'] as $item) {
            $artwork_items[] = pictufy_prepare_artwork_item($item);
        }
    }

    $returned_items = isset($artworks_response['status']['returned_items']) ? (int) $artworks_response['status']['returned_items'] : count($artwork_items);
    $has_next = ($returned_items >= $per_page);
    $has_prev = ($page > 1);

    status_header(200);
    nocache_headers();

    $collection_title_filter = function ($title) use ($collection) {
        if (empty($collection['name'])) {
            return $title;
        }

        $site_name = get_bloginfo('name');

        if (!empty($site_name)) {
            return $collection['name'] . ' – ' . $site_name;
        }

        return $collection['name'];
    };

    $document_title_filter = function ($parts) use ($collection, $collection_title_filter) {
        if (!is_array($parts)) {
            return $parts;
        }

        $parts['title'] = $collection_title_filter(isset($parts['title']) ? $parts['title'] : '');

        return $parts;
    };

    add_filter('pre_get_document_title', $collection_title_filter, 10, 1);
    add_filter('post_type_archive_title', $collection_title_filter, 10, 1);
    add_filter('single_post_title', $collection_title_filter, 10, 1);
    add_filter('document_title_parts', $document_title_filter, 10, 1);

    $archive_title_filter = function ($title) use ($collection) {
        if (empty($collection['name'])) {
            return $title;
        }

        return $collection['name'];
    };

    add_filter('get_the_archive_title', $archive_title_filter, 10, 1);

    $page_title_filter = function ($title, $id = 0) use ($collection) {
        if (empty($collection['name'])) {
            return $title;
        }

        $posts_page_id = (int) get_option('page_for_posts');

        if ($posts_page_id && (int) $id === $posts_page_id) {
            return $collection['name'];
        }

        return $title;
    };

    add_filter('the_title', $page_title_filter, 10, 2);

    $custom_page_title_action = null;
    if (function_exists('woodmart_page_title')) {
        remove_action('woodmart_after_header', 'woodmart_page_title', 10);
        $collection_title = !empty($collection['name']) ? $collection['name'] : '';
        $collection_subtitle = !empty($collection['description']) ? $collection['description'] : '';

        $custom_page_title_action = function () use ($collection_title, $collection_subtitle) {
            if ($collection_title === '') {
                return;
            }

            pictufy_render_woodmart_page_title($collection_title, array(
                'subtitle' => $collection_subtitle,
                'classes' => array('pictufy-collection-title'),
            ));
        };

        add_action('woodmart_after_header', $custom_page_title_action, 10);
    }

    get_header();

    pictufy_render_styles('collections');

    $prepared_artworks = $artwork_items;
    $json_options = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $data_script_id = 'pictufy-collection-' . sanitize_key($collection_identifier) . '-data';
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('pictufy_artworks');
    $load_more_label = esc_html__('Load more artworks', 'pictufy-integration');
    $container_attrs = array(
        'class' => 'pictufy-collection-detail pictufy-artworks',
        'data-json-id' => $data_script_id,
        'data-modal' => '1',
        'data-chunk-size' => $per_page,
        'data-initial-count' => count($prepared_artworks),
        'data-empty-text' => esc_attr__('No artworks found in this collection yet.', 'pictufy-integration'),
        'data-ajax-url' => esc_url($ajax_url),
        'data-nonce' => esc_attr($nonce),
        'data-page' => esc_attr($page),
        'data-per-page' => esc_attr($per_page),
        'data-has-more' => $has_next ? '1' : '0',
        'data-filters' => esc_attr(wp_json_encode(array(
            'order' => 'recommended',
            'collection_id' => $collection_identifier,
        ), $json_options)),
    );

    $container_attr_html = '';
    foreach ($container_attrs as $attr => $value) {
        $container_attr_html .= sprintf(' %s="%s"', esc_attr($attr), esc_attr($value));
    }
    ?>
    <div<?php echo $container_attr_html; ?>>
        <div class="collection-hero">
            <?php if (!empty($collection['cover'])): ?>
                <img src="<?php echo esc_url($collection['cover']); ?>" alt="<?php echo esc_attr($collection['name']); ?>">
            <?php endif; ?>
            <?php if (!empty($collection['name'])): ?>
                <h1><?php echo esc_html($collection['name']); ?></h1>
            <?php endif; ?>
            <?php if (!empty($collection['description'])): ?>
                <p><?php echo esc_html($collection['description']); ?></p>
            <?php endif; ?>
        </div>
        <div class="collection-artworks">
            <h2><?php esc_html_e('Artworks', 'pictufy-integration'); ?></h2>
            <?php if (!empty($artwork_error)): ?>
                <p><?php echo esc_html($artwork_error); ?></p>
            <?php elseif (!empty($prepared_artworks)): ?>
                <div class="artworks-grid">
                    <?php foreach ($prepared_artworks as $item): ?>
                        <div class="artwork-item" data-artwork='<?php echo esc_attr(wp_json_encode($item, $json_options)); ?>'>
                            <?php if (!empty($item['card_image'])): ?>
                                <img src="<?php echo esc_url($item['card_image']); ?>" alt="<?php echo esc_attr($item['title']); ?>" loading="lazy" decoding="async">
                            <?php endif; ?>
                            <?php if (!empty($item['title'])): ?>
                                <h3><?php echo esc_html($item['title']); ?></h3>
                            <?php endif; ?>
                            <?php if (!empty($item['artist'])): ?>
                                <p class="artist-name"><?php echo esc_html($item['artist']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($item['category'])): ?>
                                <p class="artwork-meta"><?php echo esc_html($item['category']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p><?php esc_html_e('No artworks found in this collection yet.', 'pictufy-integration'); ?></p>
            <?php endif; ?>
            <div class="pictufy-artworks-sentinel" aria-hidden="true"></div>
            <button type="button" class="pictufy-artworks-load" <?php echo $has_next ? '' : 'style="display:none;"'; ?>><?php echo esc_html($load_more_label); ?></button>
        </div>

        <div class="pictufy-modal" hidden>
            <div class="pictufy-modal-backdrop"></div>
            <div class="pictufy-modal-window" role="dialog" aria-modal="true" aria-labelledby="pictufy-modal-title">
                <button type="button" class="pictufy-modal-close" aria-label="<?php esc_attr_e('Close', 'pictufy-integration'); ?>">×</button>
                <div class="pictufy-modal-body">
                    <div class="pictufy-modal-media">
                        <img src="" alt="" id="pictufy-modal-image">
                    </div>
                    <div class="pictufy-modal-info">
                        <h3 id="pictufy-modal-title"></h3>
                        <p class="pictufy-modal-artist"></p>
                        <ul class="pictufy-modal-details"></ul>
                        <div class="pictufy-modal-keywords"></div>
                        <div class="pictufy-modal-actions"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script type="application/json" id="<?php echo esc_attr($data_script_id); ?>"><?php echo wp_json_encode($prepared_artworks, $json_options); ?></script>
    <?php pictufy_render_styles('artworks'); ?>
    <?php pictufy_render_artworks_script(); ?>
    <?php

    get_footer();

    if ($custom_page_title_action) {
        remove_action('woodmart_after_header', $custom_page_title_action, 10);
    }

    if (function_exists('woodmart_page_title')) {
        add_action('woodmart_after_header', 'woodmart_page_title', 10);
    }

    remove_filter('pre_get_document_title', $collection_title_filter, 10);
    remove_filter('post_type_archive_title', $collection_title_filter, 10);
    remove_filter('single_post_title', $collection_title_filter, 10);
    remove_filter('the_title', $page_title_filter, 10);
    remove_filter('document_title_parts', $document_title_filter, 10);
    remove_filter('get_the_archive_title', $archive_title_filter, 10);
    exit;
}
add_action('template_redirect', 'pictufy_render_collection_template');

function pictufy_render_artist_template() {
    $slug = get_query_var('pictufy_artist');

    if (empty($slug)) {
        return;
    }

    $page = max(1, (int) get_query_var('pictufy_page', 1));
    $per_page = 24;

    $artist = pictufy_find_artist_by_slug($slug);

    if (isset($artist['error'])) {
        $error_message = $artist['error'];
        $artist = null;
    }

    if ($artist === null || empty($artist) || !empty($error_message)) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        if (!empty($error_message)) {
            wp_die(esc_html($error_message));
        }
        include get_404_template();
        exit;
    }

    $artist_id = isset($artist['artist_id']) ? (int) $artist['artist_id'] : 0;

    if (!$artist_id) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        include get_404_template();
        exit;
    }

    $api = pictufy_get_api();
    $artworks_response = $api->get_artist_artworks($artist_id, array(
        'page' => $page,
        'per_page' => $per_page,
    ));

    $artwork_items = array();
    $artwork_error = '';

    if (isset($artworks_response['error'])) {
        $artwork_error = $artworks_response['error'];
    } elseif (!empty($artworks_response['items']) && is_array($artworks_response['items'])) {
        foreach ($artworks_response['items'] as $item) {
            $artwork_items[] = pictufy_prepare_artwork_item($item);
        }
    }

    $returned_items = isset($artworks_response['status']['returned_items']) ? (int) $artworks_response['status']['returned_items'] : count($artwork_items);
    $has_next = ($returned_items >= $per_page);
    $has_prev = ($page > 1);

    status_header(200);
    nocache_headers();

    $artist_title_filter = function ($title) use ($artist) {
        if (empty($artist['name'])) {
            return $title;
        }

        $site_name = get_bloginfo('name');

        if (!empty($site_name)) {
            return $artist['name'] . ' – ' . $site_name;
        }

        return $artist['name'];
    };

    add_filter('pre_get_document_title', $artist_title_filter, 10, 1);
    add_filter('post_type_archive_title', $artist_title_filter, 10, 1);
    add_filter('single_post_title', $artist_title_filter, 10, 1);

    $document_title_filter = function ($parts) use ($artist, $artist_title_filter) {
        if (!is_array($parts)) {
            return $parts;
        }

        $parts['title'] = $artist_title_filter(isset($parts['title']) ? $parts['title'] : '');

        return $parts;
    };

    add_filter('document_title_parts', $document_title_filter, 10, 1);

    $archive_title_filter = function ($title) use ($artist) {
        if (empty($artist['name'])) {
            return $title;
        }

        return $artist['name'];
    };

    add_filter('get_the_archive_title', $archive_title_filter, 10, 1);

    $page_title_filter = function ($title, $id = 0) use ($artist) {
        if (empty($artist['name'])) {
            return $title;
        }

        $posts_page_id = (int) get_option('page_for_posts');

        if ($posts_page_id && (int) $id === $posts_page_id) {
            return $artist['name'];
        }

        return $title;
    };

    add_filter('the_title', $page_title_filter, 10, 2);

    $artist_page_title_action = null;
    if (function_exists('woodmart_page_title')) {
        remove_action('woodmart_after_header', 'woodmart_page_title', 10);

        $artist_title = !empty($artist['name']) ? $artist['name'] : '';
        $artist_subtitle = !empty($artist['artist_type']) ? $artist['artist_type'] : '';

        $artist_page_title_action = function () use ($artist_title, $artist_subtitle) {
            if ($artist_title === '') {
                return;
            }

            pictufy_render_woodmart_page_title($artist_title, array(
                'subtitle' => $artist_subtitle,
                'classes' => array('pictufy-artist-title'),
            ));
        };

        add_action('woodmart_after_header', $artist_page_title_action, 10);
    }

    get_header();

    pictufy_render_styles('artist-detail');

    $prepared_artworks = $artwork_items;
    $json_options = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $data_script_id = 'pictufy-artist-' . sanitize_key($artist_id) . '-data';
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('pictufy_artworks');
    $load_more_label = esc_html__('Load more artworks', 'pictufy-integration');
    $container_attrs = array(
        'class' => 'pictufy-artist-detail pictufy-artworks',
        'data-json-id' => $data_script_id,
        'data-modal' => '1',
        'data-chunk-size' => $per_page,
        'data-initial-count' => count($prepared_artworks),
        'data-empty-text' => esc_attr__('No artworks found for this artist yet.', 'pictufy-integration'),
        'data-ajax-url' => esc_url($ajax_url),
        'data-nonce' => esc_attr($nonce),
        'data-page' => esc_attr($page),
        'data-per-page' => esc_attr($per_page),
        'data-has-more' => $has_next ? '1' : '0',
        'data-filters' => esc_attr(wp_json_encode(array(
            'order' => 'recommended',
            'artist_id' => $artist_id,
        ), $json_options)),
    );

    $container_attr_html = '';
    foreach ($container_attrs as $attr => $value) {
        $container_attr_html .= sprintf(' %s="%s"', esc_attr($attr), esc_attr($value));
    }
    ?>
    <div<?php echo $container_attr_html; ?>>
        <div class="artist-hero">
            <div class="artist-hero-image">
                <?php if (!empty($artist['profile_picture'])): ?>
                    <img src="<?php echo esc_url($artist['profile_picture']); ?>" alt="<?php echo esc_attr($artist['name']); ?>" loading="lazy" decoding="async">
                <?php else: ?>
                    <div class="artist-avatar-placeholder" aria-hidden="true">
                        <span><?php echo esc_html(mb_strtoupper(mb_substr($artist['name'], 0, 1))); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="artist-hero-content">
                <?php if (!empty($artist['name'])): ?>
                    <h1><?php echo esc_html($artist['name']); ?></h1>
                <?php endif; ?>
                <div class="artist-hero-meta">
                    <?php if (!empty($artist['artist_type'])): ?>
                        <span class="artist-type"><?php echo esc_html($artist['artist_type']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($artist['country'])): ?>
                        <span class="artist-country"><?php echo esc_html($artist['country']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($artist['artworks'])): ?>
                        <span class="artist-count"><?php echo esc_html(sprintf(_n('%d artwork', '%d artworks', (int) $artist['artworks'], 'pictufy-integration'), (int) $artist['artworks'])); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($artist['biography_text'])): ?>
                    <div class="artist-bio"><?php echo wp_kses_post(wpautop($artist['biography_text'])); ?></div>
                <?php elseif (!empty($artist['biography_html'])): ?>
                    <div class="artist-bio"><?php echo wp_kses_post($artist['biography_html']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="artist-artworks">
            <h2><?php esc_html_e('Artworks', 'pictufy-integration'); ?></h2>
            <?php if (!empty($artwork_error)): ?>
                <p><?php echo esc_html($artwork_error); ?></p>
            <?php elseif (!empty($prepared_artworks)): ?>
                <div class="artworks-grid">
                    <?php foreach ($prepared_artworks as $item): ?>
                        <div class="artwork-item" data-artwork='<?php echo esc_attr(wp_json_encode($item, $json_options)); ?>'>
                            <?php if (!empty($item['card_image'])): ?>
                                <img src="<?php echo esc_url($item['card_image']); ?>" alt="<?php echo esc_attr($item['title']); ?>" loading="lazy" decoding="async">
                            <?php endif; ?>
                            <?php if (!empty($item['title'])): ?>
                                <h3><?php echo esc_html($item['title']); ?></h3>
                            <?php endif; ?>
                            <?php if (!empty($item['artist'])): ?>
                                <p class="artist-name"><?php echo esc_html($item['artist']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($item['category'])): ?>
                                <p class="artwork-meta"><?php echo esc_html($item['category']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p><?php esc_html_e('No artworks found for this artist yet.', 'pictufy-integration'); ?></p>
            <?php endif; ?>
            <div class="pictufy-artworks-sentinel" aria-hidden="true"></div>
            <button type="button" class="pictufy-artworks-load" <?php echo $has_next ? '' : 'style="display:none;"'; ?>><?php echo esc_html($load_more_label); ?></button>
        </div>

        <div class="pictufy-modal" hidden>
            <div class="pictufy-modal-backdrop"></div>
            <div class="pictufy-modal-window" role="dialog" aria-modal="true" aria-labelledby="pictufy-modal-title">
                <button type="button" class="pictufy-modal-close" aria-label="<?php esc_attr_e('Close', 'pictufy-integration'); ?>">×</button>
                <div class="pictufy-modal-body">
                    <div class="pictufy-modal-media">
                        <img src="" alt="" id="pictufy-modal-image">
                    </div>
                    <div class="pictufy-modal-info">
                        <h3 id="pictufy-modal-title"></h3>
                        <p class="pictufy-modal-artist"></p>
                        <ul class="pictufy-modal-details"></ul>
                        <div class="pictufy-modal-keywords"></div>
                        <div class="pictufy-modal-actions"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script type="application/json" id="<?php echo esc_attr($data_script_id); ?>"><?php echo wp_json_encode($prepared_artworks, $json_options); ?></script>
    <?php pictufy_render_styles('artworks'); ?>
    <?php pictufy_render_artworks_script(); ?>
    <?php

    get_footer();

    if ($artist_page_title_action) {
        remove_action('woodmart_after_header', $artist_page_title_action, 10);
    }

    if (function_exists('woodmart_page_title')) {
        add_action('woodmart_after_header', 'woodmart_page_title', 10);
    }

    remove_filter('pre_get_document_title', $artist_title_filter, 10);
    remove_filter('post_type_archive_title', $artist_title_filter, 10);
    remove_filter('single_post_title', $artist_title_filter, 10);
    remove_filter('the_title', $page_title_filter, 10);
    remove_filter('document_title_parts', $document_title_filter, 10);
    remove_filter('get_the_archive_title', $archive_title_filter, 10);
    exit;
}
add_action('template_redirect', 'pictufy_render_artist_template');

class Pictufy_API {
    private $api_url = 'https://pictufy.com/api/';
    private $api_key = 'use_your_own_api_key';

    public function request($endpoint, $method = 'POST', $body = array()) {
        $args = array(
            'headers' => array('X-AUTH-KEY' => $this->api_key),
            'timeout' => 15,
        );

        if ($method === 'GET') {
            $url = $this->api_url . $endpoint;
            if (!empty($body)) {
                $url = add_query_arg($body, $url);
            }
            $response = wp_remote_get($url, $args);
        } else {
            $args['body'] = $body;
            $response = wp_remote_post($this->api_url . $endpoint, $args);
        }

        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('error' => 'Invalid JSON response from API');
        }

        if (!is_array($data)) {
            return array('error' => 'Unexpected API response format');
        }

        return $data;
    }

    public function get_collections($category = '', $order = 'curated') {
        $body = array('order' => $order);
        if (!empty($category)) {
            $body['collection_category'] = $category;
        }
        return $this->request('collections/', 'POST', $body);
    }

    public function get_artists($args = array()) {
        $defaults = array(
            'order' => 'trending',
        );
        $params = wp_parse_args($args, $defaults);
        return $this->request('artists/', 'POST', $params);
    }

    public function get_all_artists($args = array()) {
        $defaults = array(
            'order' => 'trending',
            'page' => 1,
            'per_page' => 60,
        );

        $params = wp_parse_args($args, $defaults);
        $page = max(1, (int) $params['page']);
        $per_page = max(1, (int) $params['per_page']);

        $all_items = array();
        $has_more = true;

        while ($has_more) {
            $params['page'] = $page;
            $params['per_page'] = $per_page;

            $response = $this->get_artists($params);

            if (isset($response['error'])) {
                return $response;
            }

            if (!empty($response['items']) && is_array($response['items'])) {
                $all_items = array_merge($all_items, $response['items']);
            }

            $returned = isset($response['status']['returned_items']) ? (int) $response['status']['returned_items'] : count($response['items']);

            if ($returned < $per_page || empty($response['items'])) {
                $has_more = false;
            } else {
                $page++;
            }
        }

        return array(
            'items' => $all_items,
            'status' => array(
                'returned_items' => count($all_items),
                'code' => 200,
            ),
        );
    }

    public function get_artist($artist_id) {
        if (empty($artist_id)) {
            return array('error' => 'Missing artist ID');
        }

        return $this->request('artist/', 'POST', array('artist_id' => $artist_id));
    }

    public function get_artist_artworks($artist_id, $args = array()) {
        if (empty($artist_id)) {
            return array('error' => 'Missing artist ID');
        }

        $defaults = array(
            'page' => 1,
            'per_page' => 24,
        );

        $params = wp_parse_args($args, $defaults);
        $params['artist_id'] = $artist_id;

        return $this->request('artworks/', 'POST', $params);
    }

    public function get_categories() {
        return $this->request('categories/', 'POST');
    }

    public function get_artworks($args = array()) {
        $defaults = array(
            'page' => 1,
            'per_page' => 80,
            'order' => 'recommended',
        );
        $params = wp_parse_args($args, $defaults);
        return $this->request('artworks/', 'POST', $params);
    }

    public function get_expired_artworks($args = array()) {
        $defaults = array(
            'page' => 1,
            'per_page' => 200,
        );

        $params = wp_parse_args($args, $defaults);
        return $this->request('expired/', 'POST', $params);
    }
}

function pictufy_get_api() {
    static $api = null;
    if ($api === null) {
        $api = new Pictufy_API();
    }
    return $api;
}

function pictufy_prepare_collection_item($collection) {
    $slug = '';

    if (!empty($collection['slug'])) {
        $slug = sanitize_title($collection['slug']);
    }

    if (empty($slug) && !empty($collection['url'])) {
        $path = parse_url($collection['url'], PHP_URL_PATH);
        if ($path !== null) {
            $slug = sanitize_title(basename(untrailingslashit($path)));
        }
    }

    if (empty($slug) && !empty($collection['id'])) {
        $slug = sanitize_title($collection['id']);
    }

    $description = '';
    if (!empty($collection['description'])) {
        $description = wp_strip_all_tags($collection['description']);
    }

    $excerpt = $description ? wp_trim_words($description, 20, '...') : '';
    $cover = '';
    $card_image = '';

    if (!empty($collection['thumb'])) {
        $card_image = $collection['thumb'];
    } elseif (!empty($collection['cover_small'])) {
        $card_image = $collection['cover_small'];
    }

    if (!empty($collection['cover'])) {
        $cover = $collection['cover'];
    } elseif (!empty($collection['cover_large'])) {
        $cover = $collection['cover_large'];
    } elseif (!empty($collection['thumb'])) {
        $cover = $collection['thumb'];
    }

    if (empty($card_image) && !empty($cover)) {
        $card_image = $cover;
    }

    $artworks_count = isset($collection['artworks']) ? (int) $collection['artworks'] : null;
    $artworks_label = '';

    if ($artworks_count !== null && $artworks_count > 0) {
        $artworks_label = sprintf(_n('%s artwork', '%s artworks', $artworks_count, 'pictufy-integration'), number_format_i18n($artworks_count));
    }

    $detail_url = $slug ? trailingslashit(home_url('collection/' . $slug)) : '';

    return array(
        'id' => isset($collection['id']) ? $collection['id'] : '',
        'slug' => $slug,
        'name' => isset($collection['name']) ? $collection['name'] : '',
        'description' => $description,
        'excerpt' => $excerpt,
        'cover' => $cover,
        'card_image' => $card_image,
        'external_url' => !empty($collection['url']) ? $collection['url'] : '',
        'detail_url' => $detail_url,
        'artworks_count' => $artworks_count,
        'artworks_count_label' => $artworks_label,
    );
}

function pictufy_collections_cache_key($args) {
    $defaults = array(
        'category' => '',
        'order' => 'curated',
    );
    $args = wp_parse_args($args, $defaults);
    ksort($args);
    return 'pictufy_collections_' . md5(wp_json_encode($args));
}

function pictufy_get_collections_data($args = array(), $force = false) {
    $cache_key = pictufy_collections_cache_key($args);

    if (!$force) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
    }

    $defaults = array(
        'category' => '',
        'order' => 'curated',
    );
    $params = wp_parse_args($args, $defaults);

    $api = pictufy_get_api();
    $response = $api->get_collections($params['category'], $params['order']);

    if (isset($response['error'])) {
        return array('error' => $response['error']);
    }

    if (empty($response['items']) || !is_array($response['items'])) {
        $result = array('items' => array(), 'raw' => $response);
        set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);
        return $result;
    }

    $flattened = array();

    foreach ($response['items'] as $collection_group) {
        if (empty($collection_group['collections']) || !is_array($collection_group['collections'])) {
            continue;
        }

        foreach ($collection_group['collections'] as $collection) {
            $flattened[] = pictufy_prepare_collection_item($collection);
        }
    }

    $result = array(
        'items' => $flattened,
        'raw' => $response,
    );

    set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);

    return $result;
}

function pictufy_find_collection_by_slug($slug, $args = array()) {
    $data = pictufy_get_collections_data($args);

    if (isset($data['error'])) {
        return $data;
    }

    if (empty($data['items'])) {
        return null;
    }

    foreach ($data['items'] as $item) {
        if (!empty($item['slug']) && $item['slug'] === $slug) {
            return $item;
        }
    }

    return null;
}

function pictufy_collections_shortcode($atts) {
    $atts = shortcode_atts(array(
        'category' => '',
        'order' => 'curated',
    ), $atts);

    $collections_data = pictufy_get_collections_data($atts);

    if (isset($collections_data['error'])) {
        return '<p>Error loading collections: ' . esc_html($collections_data['error']) . '</p>';
    }

    $flattened_collections = isset($collections_data['items']) ? $collections_data['items'] : array();

    ob_start();

    pictufy_render_styles('collections');

    if (empty($flattened_collections)) {
        ?>
        <div class="pictufy-collections">
            <p><?php esc_html_e('No collections found.', 'pictufy-integration'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    $per_page = 9;
    $total_items = count($flattened_collections);
    $initial_display = min($total_items, max($per_page * 2, 18));
    $initial_items = array_slice($flattened_collections, 0, $initial_display);
    $initial_count = count($initial_items);
    $container_id = 'pictufy-collections-' . wp_generate_password(8, false, false);
    $grid_id = $container_id . '-grid';
    $data_script_id = $container_id . '-data';
    $view_label = esc_html__('View details', 'pictufy-integration');
    $load_more_label = esc_html__('Load more collections', 'pictufy-integration');
    $json_options = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    ?>
    <div class="pictufy-collections" data-json-id="<?php echo esc_attr($data_script_id); ?>" data-per-page="<?php echo esc_attr($per_page); ?>" data-initial-count="<?php echo esc_attr($initial_count); ?>" data-view-label="<?php echo esc_attr($view_label); ?>">
        <h2><?php esc_html_e('Collections', 'pictufy-integration'); ?></h2>
        <div id="<?php echo esc_attr($grid_id); ?>" class="collections-grid">
            <?php foreach ($initial_items as $item): ?>
                <div class="collection-item">
                    <?php if (!empty($item['card_image'])): ?>
                        <img src="<?php echo esc_url($item['card_image']); ?>" alt="<?php echo esc_attr($item['name']); ?>" loading="lazy" decoding="async" fetchpriority="low">
                    <?php endif; ?>
                    <?php if (!empty($item['name'])): ?>
                        <h3><?php echo esc_html($item['name']); ?></h3>
                    <?php endif; ?>
                    <?php if (!empty($item['excerpt'])): ?>
                        <p><?php echo esc_html($item['excerpt']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($item['artworks_count_label'])): ?>
                        <p class="collection-meta"><?php echo esc_html($item['artworks_count_label']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($item['detail_url'])): ?>
                        <a class="pictufy-collection-link" href="<?php echo esc_url($item['detail_url']); ?>"><?php echo esc_html($view_label); ?></a>
                    <?php elseif (!empty($item['external_url'])): ?>
                        <a class="pictufy-collection-link" href="<?php echo esc_url($item['external_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($view_label); ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="pictufy-collections-sentinel" aria-hidden="true"></div>
        <?php if ($total_items > $initial_count): ?>
            <button type="button" class="pictufy-collections-load"><?php echo esc_html($load_more_label); ?></button>
        <?php endif; ?>
    </div>
    <script type="application/json" id="<?php echo esc_attr($data_script_id); ?>"><?php echo wp_json_encode($flattened_collections, $json_options); ?></script>
    <?php pictufy_render_collections_script(); ?>
    <?php
    return ob_get_clean();
}
add_shortcode('pictufy_collections', 'pictufy_collections_shortcode');

function pictufy_render_collections_script() {
    static $printed = false;

    if ($printed) {
        return;
    }

    $printed = true;
    ?>
    <script>
        (function () {
            const initCollections = () => {
                const containers = document.querySelectorAll('.pictufy-collections[data-json-id]');

                if (!containers.length) {
                    return;
                }

                containers.forEach((container) => {
                    const dataScriptId = container.getAttribute('data-json-id');
                    const perPage = parseInt(container.getAttribute('data-per-page'), 10) || 9;
                    const jsonScript = dataScriptId ? document.getElementById(dataScriptId) : null;

                    if (!jsonScript) {
                        return;
                    }

                    let collections = [];

                    try {
                        collections = JSON.parse(jsonScript.textContent || '[]');
                    } catch (err) {
                        console.error('Pictufy collections JSON error', err);
                        return;
                    }

                    let renderedCount = parseInt(container.getAttribute('data-initial-count'), 10) || 0;
                    const loadButton = container.querySelector('.pictufy-collections-load');
                    const grid = container.querySelector('.collections-grid');
                    const sentinel = container.querySelector('.pictufy-collections-sentinel');
                    let observer = null;

                    const renderCard = (item) => {
                        const card = document.createElement('div');
                        card.className = 'collection-item';

                        if (item.card_image || item.cover) {
                            const img = document.createElement('img');
                            img.src = item.card_image || item.cover;
                            img.alt = item.name || '';
                            img.loading = 'lazy';
                            img.decoding = 'async';
                            img.fetchPriority = 'low';
                            card.appendChild(img);
                        }

                        if (item.name) {
                            const title = document.createElement('h3');
                            title.textContent = item.name;
                            card.appendChild(title);
                        }

                        if (item.excerpt) {
                            const excerpt = document.createElement('p');
                            excerpt.textContent = item.excerpt;
                            card.appendChild(excerpt);
                        }

                        if (item.artworks_count_label) {
                            const meta = document.createElement('p');
                            meta.className = 'collection-meta';
                            meta.textContent = item.artworks_count_label;
                            card.appendChild(meta);
                        }

                        let linkHref = item.detail_url || item.external_url || '';

                        if (linkHref) {
                            const link = document.createElement('a');
                            link.className = 'pictufy-collection-link';
                            link.textContent = container.getAttribute('data-view-label') || 'View details';
                            link.href = linkHref;

                            if (!item.detail_url && item.external_url) {
                                link.target = '_blank';
                                link.rel = 'noopener noreferrer';
                            }

                            card.appendChild(link);
                        }

                        return card;
                    };

                    const renderMore = () => {
                        const nextItems = collections.slice(renderedCount, renderedCount + perPage);

                        nextItems.forEach((item) => {
                            grid.appendChild(renderCard(item));
                        });

                        renderedCount += nextItems.length;

                        if (renderedCount >= collections.length && loadButton) {
                            loadButton.remove();
                        }
                    };

                    const handleLoadMoreClick = () => {
                        renderMore();

                        if (renderedCount >= collections.length && loadButton) {
                            loadButton.style.display = 'none';
                        }
                    };

                    if (loadButton) {
                        loadButton.addEventListener('click', handleLoadMoreClick);
                    }

                    const ensureObserver = () => {
                        if (!sentinel || observer || !('IntersectionObserver' in window)) {
                            return;
                        }

                        observer = new IntersectionObserver((entries) => {
                            entries.forEach((entry) => {
                                if (!entry.isIntersecting) {
                                    return;
                                }

                                handleLoadMoreClick();
                            });
                        }, {
                            rootMargin: '200px 0px 200px 0px',
                            threshold: 0.01,
                        });

                        observer.observe(sentinel);
                    };

                    ensureObserver();

                    if (renderedCount >= collections.length && loadButton) {
                        loadButton.style.display = 'none';
                    }
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initCollections);
            } else {
                initCollections();
            }
        })();
    </script>
    <?php
}

function pictufy_artists_shortcode($atts = array()) {
    $atts = shortcode_atts(array('order' => 'trending'), $atts);

    $api = pictufy_get_api();

    try {
        $artists = $api->get_artists($atts);

        if (isset($artists['error'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Pictufy artists API error: ' . $artists['error']);
            }
            return '<p>Error loading artists: ' . esc_html($artists['error']) . '</p>';
        }

        if (!isset($artists['items']) || !is_array($artists['items'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Pictufy artists unexpected payload: ' . wp_json_encode($artists));
            }
            return '<p>No artists found.</p>';
        }

        $artist_items = array();

        foreach ($artists['items'] as $artist) {
            $artist_items[] = pictufy_prepare_artist_item($artist);
        }

        $chunk_size = 24;
        $initial_items = array_slice($artist_items, 0, $chunk_size);
        $initial_count = count($initial_items);
        $total_items = count($artist_items);
        $container_id = 'pictufy-artists-' . wp_generate_password(8, false, false);
        $grid_id = $container_id . '-grid';
        $data_script_id = $container_id . '-data';
        $load_more_label = esc_html__('Show more artists', 'pictufy-integration');
        $json_options = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        ob_start();
        ?>
        <div class="pictufy-artists" data-json-id="<?php echo esc_attr($data_script_id); ?>" data-initial-count="<?php echo esc_attr($initial_count); ?>" data-chunk-size="<?php echo esc_attr($chunk_size); ?>">
            <h2>Artists</h2>
            <div id="<?php echo esc_attr($grid_id); ?>" class="artists-grid">
                <?php foreach ($initial_items as $artist): ?>
                    <a class="artist-item" href="<?php echo esc_url($artist['detail_url']); ?>" data-artist='<?php echo esc_attr(wp_json_encode($artist, $json_options)); ?>'>
                        <?php if (!empty($artist['image'])): ?>
                            <img src="<?php echo esc_url($artist['image']); ?>" alt="<?php echo esc_attr($artist['name']); ?>" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <?php if (!empty($artist['name'])): ?>
                            <h3><?php echo esc_html($artist['name']); ?></h3>
                        <?php endif; ?>
                        <?php if (!empty($artist['username'])): ?>
                            <p class="artist-username">@<?php echo esc_html($artist['username']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($artist['type']) || $artist['artworks'] > 0): ?>
                            <div class="artist-meta">
                                <?php if (!empty($artist['type'])): ?>
                                    <span class="artist-type"><?php echo esc_html($artist['type']); ?></span>
                                <?php endif; ?>
                                <?php if ($artist['artworks'] > 0): ?>
                                    <span class="artist-count"><?php echo esc_html(number_format_i18n($artist['artworks'])); ?> <?php esc_html_e('artworks', 'pictufy-integration'); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if ($total_items > $initial_count): ?>
                <button type="button" class="pictufy-artists-load"><?php echo esc_html($load_more_label); ?></button>
            <?php endif; ?>
        </div>
        <script type="application/json" id="<?php echo esc_attr($data_script_id); ?>"><?php echo wp_json_encode($artist_items, $json_options); ?></script>
        <?php pictufy_render_styles('artists'); ?>
        <?php pictufy_render_artists_script(); ?>
        <?php
        return ob_get_clean();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Pictufy artists shortcode error: ' . $e->getMessage());
        }
        return '<p>Error loading artists section. Please try again later.</p>';
    }
}
add_shortcode('pictufy_artists', 'pictufy_artists_shortcode');

function pictufy_prepare_artist_item($artist) {
    $image = '';

    if (!empty($artist['profile_picture'])) {
        $image = $artist['profile_picture'];
    } elseif (!empty($artist['image'])) {
        $image = $artist['image'];
    }

    $slug = pictufy_build_artist_slug_from_data($artist);

    return array(
        'id' => isset($artist['artist_id']) ? $artist['artist_id'] : '',
        'name' => isset($artist['name']) ? $artist['name'] : '',
        'username' => isset($artist['username']) ? $artist['username'] : '',
        'type' => isset($artist['artist_type']) ? $artist['artist_type'] : '',
        'image' => $image,
        'artworks' => isset($artist['artworks']) ? (int) $artist['artworks'] : 0,
        'slug' => $slug,
        'detail_url' => pictufy_get_artist_detail_url(array_merge($artist, array('slug' => $slug))),
    );
}

function pictufy_prepare_artwork_item($artwork) {
    $image = '';
    $image_high = '';

    if (!empty($artwork['urls']) && is_array($artwork['urls'])) {
        $urls = $artwork['urls'];
        if (!empty($urls['img_thumb_square'])) {
            $image = $urls['img_thumb_square'];
        } elseif (!empty($urls['img_thumb'])) {
            $image = $urls['img_thumb'];
        } elseif (!empty($urls['img_medium'])) {
            $image = $urls['img_medium'];
        }

        if (!empty($urls['img_high'])) {
            $image_high = $urls['img_high'];
        }
    }

    if (empty($image) && !empty($artwork['image'])) {
        $image = $artwork['image'];
    }

    if (empty($image_high) && !empty($artwork['image'])) {
        $image_high = $artwork['image'];
    }

    $title = '';
    if (!empty($artwork['title'])) {
        $title = is_array($artwork['title']) ? reset($artwork['title']) : $artwork['title'];
    }

    $width = isset($artwork['width']) ? (int) $artwork['width'] : 0;
    $height = isset($artwork['height']) ? (int) $artwork['height'] : 0;
    $resolution = ($width && $height) ? ($width . ' × ' . $height) : '';

    $keywords = array();
    if (!empty($artwork['keywords'])) {
        if (is_array($artwork['keywords'])) {
            $first = reset($artwork['keywords']);
            if (is_string($first)) {
                $keywords = explode(',', $first);
            }
        } elseif (is_string($artwork['keywords'])) {
            $keywords = explode(',', $artwork['keywords']);
        }
    }

    $keywords = array_slice(array_filter(array_map('trim', $keywords)), 0, 20);

    $color = '';
    if (!empty($artwork['color']) && is_array($artwork['color'])) {
        foreach ($artwork['color'] as $key => $enabled) {
            if ($enabled) {
                $color = $key;
                break;
            }
        }
    }

    $category = !empty($artwork['category']) ? $artwork['category'] : '';
    $category_id = isset($artwork['category_id']) ? $artwork['category_id'] : '';

    return array(
        'id' => isset($artwork['id']) ? $artwork['id'] : '',
        'title' => $title,
        'artist' => isset($artwork['artist']) ? $artwork['artist'] : '',
        'artist_id' => isset($artwork['artist_id']) ? (int) $artwork['artist_id'] : 0,
        'category' => $category,
        'category_id' => $category_id,
        'keywords' => $keywords,
        'published' => isset($artwork['artwork_published']) ? $artwork['artwork_published'] : '',
        'resolution' => $resolution,
        'artwork_type' => isset($artwork['artwork_type']) ? $artwork['artwork_type'] : '',
        'geometry' => isset($artwork['geometry']) ? $artwork['geometry'] : '',
        'color' => $color,
        'urls' => isset($artwork['urls']) && is_array($artwork['urls']) ? $artwork['urls'] : array(),
        'image' => $image,
        'image_high' => $image_high,
        'card_image' => $image,
        'width' => $width,
        'height' => $height,
    );
}

if (!function_exists('pictufy_render_woodmart_page_title')) {
    function pictufy_render_woodmart_page_title($title, $args = array()) {
        $title = (string) $title;

        if ($title === '') {
            return;
        }

        $defaults = array(
            'subtitle' => '',
            'classes' => array(),
            'breadcrumbs' => null,
        );

        $args = wp_parse_args($args, $defaults);

        $classes = array('page-title-default');
        $title_size = 'default';
        $title_design = 'default';
        $title_color = 'light';
        $breadcrumbs_enabled = true;

        if (function_exists('woodmart_get_opt')) {
            $title_size = woodmart_get_opt('page-title-size');
            if (empty($title_size)) {
                $title_size = 'default';
            }

            $title_design = woodmart_get_opt('page-title-design');
            if (empty($title_design) || $title_design === 'disable') {
                $title_design = 'default';
            }

            $title_color = woodmart_get_opt('page-title-color');
            if (empty($title_color)) {
                $title_color = 'light';
            }

            $breadcrumbs_setting = woodmart_get_opt('breadcrumbs');
            if ($args['breadcrumbs'] === null) {
                $breadcrumbs_enabled = (bool) $breadcrumbs_setting;
            }
        }

        if ($args['breadcrumbs'] !== null) {
            $breadcrumbs_enabled = (bool) $args['breadcrumbs'];
        }

        $classes[] = 'title-size-' . $title_size;
        $classes[] = 'title-design-' . $title_design;
        $classes[] = 'color-scheme-' . $title_color;
        $classes[] = 'title-blog';
        $classes[] = 'pictufy-custom-title';

        if (!empty($args['classes'])) {
            $extra_classes = is_array($args['classes']) ? $args['classes'] : preg_split('/\s+/u', (string) $args['classes'], -1, PREG_SPLIT_NO_EMPTY);
            foreach ($extra_classes as $class) {
                $classes[] = $class;
            }
        }

        $classes = array_unique(array_filter(array_map('sanitize_html_class', $classes)));
        $class_attr = implode(' ', $classes);

        if (function_exists('woodmart_enqueue_inline_style')) {
            woodmart_enqueue_inline_style('page-title');
        }

        ?>
        <div class="page-title <?php echo esc_attr($class_attr); ?>">
            <div class="container">
                <h1 class="entry-title title"><?php echo esc_html($title); ?></h1>
                <?php if ($args['subtitle'] !== ''): ?>
                    <p class="page-title-subtitle"><?php echo esc_html($args['subtitle']); ?></p>
                <?php endif; ?>
                <?php do_action('woodmart_page_title_after_title'); ?>
                <?php if ($breadcrumbs_enabled && function_exists('woodmart_current_breadcrumbs')): ?>
                    <?php woodmart_current_breadcrumbs('pages'); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

function pictufy_artworks_shortcode($atts) {
    $atts = shortcode_atts(array(
        'page' => 1,
        'per_page' => 60,
        'order' => 'recommended',
        'chunk' => 60,
        'search' => '',
        'artwork_type' => '',
        'geometry' => '',
        'color' => '',
        'resolution' => '',
        'category' => '',
        'people' => '',
        'animals' => '',
        'buildings' => '',
        'nudity' => '',
        'custom_interiors' => '',
        'grade' => '',
    ), $atts);

    $page = max(1, (int) $atts['page']);
    $per_page = max(1, (int) $atts['per_page']);
    $chunk_size = max(1, min($per_page, (int) $atts['chunk']));

    $filters = pictufy_normalize_artwork_filters($atts);
    $request_args = array_merge($filters, array(
        'page' => $page,
        'per_page' => $per_page,
        'order' => sanitize_text_field($atts['order']),
    ));

    $api = pictufy_get_api();
    $artworks = $api->get_artworks($request_args);

    if (isset($artworks['error'])) {
        return '<p>Error loading artworks: ' . esc_html($artworks['error']) . '</p>';
    }

    $prepared_items = array();

    if (!empty($artworks['items']) && is_array($artworks['items'])) {
        foreach ($artworks['items'] as $artwork) {
            $prepared_items[] = pictufy_prepare_artwork_item($artwork);
        }
    }

    $returned_items = isset($artworks['status']['returned_items']) ? (int) $artworks['status']['returned_items'] : count($prepared_items);
    $has_more = ($returned_items >= $per_page);

    $initial_items = array_slice($prepared_items, 0, $chunk_size);
    $initial_count = count($initial_items);
    $container_id = 'pictufy-artworks-' . wp_generate_password(8, false, false);
    $grid_id = $container_id . '-grid';
    $data_script_id = $container_id . '-data';
    $more_label = esc_html__('Load more artworks', 'pictufy-integration');
    $json_options = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('pictufy_artworks');

    $color_options = array(
        '' => __('Any color', 'pictufy-integration'),
        'red' => __('Red', 'pictufy-integration'),
        'orange' => __('Orange', 'pictufy-integration'),
        'yellow' => __('Yellow', 'pictufy-integration'),
        'green' => __('Green', 'pictufy-integration'),
        'turquoise' => __('Turquoise', 'pictufy-integration'),
        'blue' => __('Blue', 'pictufy-integration'),
        'lilac' => __('Lilac', 'pictufy-integration'),
        'pink' => __('Pink', 'pictufy-integration'),
        'highkey' => __('High key', 'pictufy-integration'),
        'lowkey' => __('Low key', 'pictufy-integration'),
    );

    $type_options = array(
        '' => __('Any type', 'pictufy-integration'),
        'photography' => __('Photography', 'pictufy-integration'),
        'illustration' => __('Illustration', 'pictufy-integration'),
        'wall_mural' => __('Wall mural', 'pictufy-integration'),
    );

    $geometry_options = array(
        '' => __('Any geometry', 'pictufy-integration'),
        'horizontal' => __('Horizontal', 'pictufy-integration'),
        'vertical' => __('Vertical', 'pictufy-integration'),
        'square' => __('Square', 'pictufy-integration'),
        'panorama' => __('Panorama', 'pictufy-integration'),
    );

    $order_options = array(
        'recommended' => __('Recommended', 'pictufy-integration'),
        'recently_added' => __('Recently added', 'pictufy-integration'),
        'trending' => __('Trending', 'pictufy-integration'),
        'best_selling' => __('Best selling', 'pictufy-integration'),
        'oldest_first' => __('Oldest first', 'pictufy-integration'),
    );

    $resolution_options = array(
        '' => __('Any resolution', 'pictufy-integration'),
        '20' => __('≥ 20 MP', 'pictufy-integration'),
        '30' => __('≥ 30 MP', 'pictufy-integration'),
        '40' => __('≥ 40 MP', 'pictufy-integration'),
        '50' => __('≥ 50 MP', 'pictufy-integration'),
        '100' => __('≥ 100 MP', 'pictufy-integration'),
        '150' => __('≥ 150 MP', 'pictufy-integration'),
    );

    $category_options = array('' => __('Any category', 'pictufy-integration'));
    $categories_response = $api->get_categories();
    if (!isset($categories_response['error']) && !empty($categories_response['items']) && is_array($categories_response['items'])) {
        foreach ($categories_response['items'] as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $category) {
                if (empty($category['category_id']) || empty($category['category_name'])) {
                    continue;
                }
                $category_options[$category['category_id']] = $category['category_name'];
            }
        }
    }

    $initial_filters = array(
        'order' => sanitize_text_field($atts['order']),
        'search' => sanitize_text_field($atts['search']),
        'artwork_type' => sanitize_text_field($atts['artwork_type']),
        'geometry' => sanitize_text_field($atts['geometry']),
        'color' => sanitize_text_field($atts['color']),
        'resolution' => sanitize_text_field($atts['resolution']),
        'category' => sanitize_text_field($atts['category']),
    );

    ob_start();
    ?>
    <div class="pictufy-artworks" data-modal="1" data-json-id="<?php echo esc_attr($data_script_id); ?>" data-chunk-size="<?php echo esc_attr($chunk_size); ?>" data-initial-count="<?php echo esc_attr($initial_count); ?>" data-ajax-url="<?php echo esc_url($ajax_url); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-page="<?php echo esc_attr($page); ?>" data-per-page="<?php echo esc_attr($per_page); ?>" data-has-more="<?php echo $has_more ? '1' : '0'; ?>" data-filters='<?php echo esc_attr(wp_json_encode($initial_filters, $json_options)); ?>'>
        <h2><?php esc_html_e('Explore Artworks', 'pictufy-integration'); ?></h2>
        <form class="pictufy-artworks-filters" method="post" action="#" novalidate>
            <div class="filter-grid">
                <div class="filter-field filter-field--search">
                    <label for="<?php echo esc_attr($container_id); ?>-search"><?php esc_html_e('Search', 'pictufy-integration'); ?></label>
                    <input id="<?php echo esc_attr($container_id); ?>-search" type="search" name="search" value="<?php echo esc_attr($atts['search']); ?>" placeholder="<?php esc_attr_e('Search artworks…', 'pictufy-integration'); ?>">
                </div>
                <div class="filter-field">
                    <label for="<?php echo esc_attr($container_id); ?>-order"><?php esc_html_e('Order', 'pictufy-integration'); ?></label>
                    <select id="<?php echo esc_attr($container_id); ?>-order" name="order">
                        <?php foreach ($order_options as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($atts['order'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="<?php echo esc_attr($container_id); ?>-type"><?php esc_html_e('Artwork type', 'pictufy-integration'); ?></label>
                    <select id="<?php echo esc_attr($container_id); ?>-type" name="artwork_type">
                        <?php foreach ($type_options as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($atts['artwork_type'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="<?php echo esc_attr($container_id); ?>-geometry"><?php esc_html_e('Geometry', 'pictufy-integration'); ?></label>
                    <select id="<?php echo esc_attr($container_id); ?>-geometry" name="geometry">
                        <?php foreach ($geometry_options as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($atts['geometry'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="<?php echo esc_attr($container_id); ?>-color"><?php esc_html_e('Color', 'pictufy-integration'); ?></label>
                    <select id="<?php echo esc_attr($container_id); ?>-color" name="color">
                        <?php foreach ($color_options as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($atts['color'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="<?php echo esc_attr($container_id); ?>-resolution"><?php esc_html_e('Resolution', 'pictufy-integration'); ?></label>
                    <select id="<?php echo esc_attr($container_id); ?>-resolution" name="resolution">
                        <?php foreach ($resolution_options as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($atts['resolution'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="<?php echo esc_attr($container_id); ?>-category"><?php esc_html_e('Category', 'pictufy-integration'); ?></label>
                    <select id="<?php echo esc_attr($container_id); ?>-category" name="category">
                        <?php foreach ($category_options as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected((string) $atts['category'], (string) $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button type="reset" class="pictufy-artworks-reset"><?php esc_html_e('Clear filters', 'pictufy-integration'); ?></button>
            </div>
        </form>

        <div id="<?php echo esc_attr($grid_id); ?>" class="artworks-grid">
            <?php if ($initial_count > 0): ?>
                <?php foreach ($initial_items as $item): ?>
                    <div class="artwork-item" data-artwork='<?php echo esc_attr(wp_json_encode($item, $json_options)); ?>'>
                        <?php if (!empty($item['card_image'])): ?>
                            <img src="<?php echo esc_url($item['card_image']); ?>" alt="<?php echo esc_attr($item['title']); ?>" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <?php if (!empty($item['title'])): ?>
                            <h3><?php echo esc_html($item['title']); ?></h3>
                        <?php endif; ?>
                        <?php if (!empty($item['artist'])): ?>
                            <p class="artist-name"><?php echo esc_html($item['artist']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($item['category'])): ?>
                            <p class="artwork-meta"><?php echo esc_html($item['category']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?php esc_html_e('No artworks found.', 'pictufy-integration'); ?></p>
            <?php endif; ?>
        </div>
        <div class="pictufy-artworks-sentinel" aria-hidden="true"></div>
        <button type="button" class="pictufy-artworks-load" <?php echo $has_more ? '' : 'style="display:none;"'; ?>><?php echo esc_html($more_label); ?></button>
        <div class="pictufy-modal" hidden>
            <div class="pictufy-modal-backdrop"></div>
            <div class="pictufy-modal-window" role="dialog" aria-modal="true" aria-labelledby="pictufy-modal-title">
                <button type="button" class="pictufy-modal-close" aria-label="<?php esc_attr_e('Close', 'pictufy-integration'); ?>">×</button>
                <div class="pictufy-modal-body">
                    <div class="pictufy-modal-media">
                        <img src="" alt="" id="pictufy-modal-image">
                    </div>
                    <div class="pictufy-modal-info">
                        <h3 id="pictufy-modal-title"></h3>
                        <p class="pictufy-modal-artist"></p>
                        <ul class="pictufy-modal-details"></ul>
                        <div class="pictufy-modal-keywords"></div>
                        <div class="pictufy-modal-actions"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script type="application/json" id="<?php echo esc_attr($data_script_id); ?>"><?php echo wp_json_encode($prepared_items, $json_options); ?></script>
    <?php pictufy_render_styles('artworks'); ?>
    <?php pictufy_render_artworks_script(); ?>
    <?php
    return ob_get_clean();
}
add_shortcode('pictufy_artworks', 'pictufy_artworks_shortcode');

function pictufy_handle_artworks_ajax() {
    check_ajax_referer('pictufy_artworks', 'nonce');

    $page = isset($_POST['page']) ? (int) $_POST['page'] : 1;
    $per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : 60;
    $filters = array();

    if (!empty($_POST['filters']) && is_array($_POST['filters'])) {
        $filters = pictufy_normalize_artwork_filters($_POST['filters']);
    }

    $page = max(1, $page);
    $per_page = max(1, min(120, $per_page));

    $args = array_merge($filters, array(
        'page' => $page,
        'per_page' => $per_page,
    ));

    if (!empty($_POST['order'])) {
        $args['order'] = sanitize_text_field($_POST['order']);
    }

    $api = pictufy_get_api();
    $response = $api->get_artworks($args);

    if (isset($response['error'])) {
        wp_send_json_error(array('message' => $response['error']));
    }

    $items = array();

    if (!empty($response['items']) && is_array($response['items'])) {
        foreach ($response['items'] as $item) {
            $items[] = pictufy_prepare_artwork_item($item);
        }
    }

    $returned = isset($response['status']['returned_items']) ? (int) $response['status']['returned_items'] : count($items);

    wp_send_json_success(array(
        'items' => $items,
        'has_more' => ($returned >= $per_page),
    ));
}
add_action('wp_ajax_pictufy_artworks', 'pictufy_handle_artworks_ajax');
add_action('wp_ajax_nopriv_pictufy_artworks', 'pictufy_handle_artworks_ajax');

function pictufy_render_artworks_script() {
    static $printed = false;

    if ($printed) {
        return;
    }

    $printed = true;
    ?>
    <script>
        (function () {
            const initArtworks = () => {
                const containers = document.querySelectorAll('.pictufy-artworks[data-modal="1"]');

                if (!containers.length) {
                    return;
                }

                const enableModal = (container) => {
                    const grid = container.querySelector('.artworks-grid');
                    const modal = container.querySelector('.pictufy-modal');

                    if (!grid || !modal) {
                        return {
                            bindCard: () => {},
                            bindAll: () => {},
                        };
                    }

                    const backdrop = modal.querySelector('.pictufy-modal-backdrop');
                    const closeBtn = modal.querySelector('.pictufy-modal-close');
                    const imageEl = modal.querySelector('#pictufy-modal-image');
                    const titleEl = modal.querySelector('#pictufy-modal-title');
                    const artistEl = modal.querySelector('.pictufy-modal-artist');
                    const detailsList = modal.querySelector('.pictufy-modal-details');
                    const keywordsWrap = modal.querySelector('.pictufy-modal-keywords');
                    const actionsWrap = modal.querySelector('.pictufy-modal-actions');

                    const openModal = (payload) => {
                        if (!payload) {
                            return;
                        }

                        if (imageEl) {
                            imageEl.src = payload.image_high || payload.image || '';
                            imageEl.alt = payload.title || '';
                        }

                        if (titleEl) {
                            titleEl.textContent = payload.title || '';
                        }

                        if (artistEl) {
                            artistEl.textContent = payload.artist ? ('By ' + payload.artist) : '';
                        }

                        if (detailsList) {
                            detailsList.innerHTML = '';

                            const entries = [];

                            if (payload.id) {
                                entries.push('ID: ' + payload.id);
                            }

                            if (payload.resolution) {
                                entries.push('Image resolution: ' + payload.resolution);
                            }

                            if (payload.published) {
                                entries.push('Uploaded: ' + payload.published);
                            }

                            if (payload.category) {
                                entries.push('Category: ' + payload.category);
                            }

                            entries.forEach((text) => {
                                const li = document.createElement('li');
                                li.textContent = text;
                                detailsList.appendChild(li);
                            });
                        }

                        if (keywordsWrap) {
                            keywordsWrap.innerHTML = '';
                            (payload.keywords || []).forEach((keyword) => {
                                if (!keyword) {
                                    return;
                                }
                                const tag = document.createElement('span');
                                tag.textContent = keyword;
                                keywordsWrap.appendChild(tag);
                            });
                        }

                        if (actionsWrap) {
                            actionsWrap.innerHTML = '';

                            const urls = payload.urls || {};
                            const defaultImage = payload.image_high || payload.image || '';

                            const setModalImage = (src) => {
                                if (imageEl) {
                                    imageEl.src = src || defaultImage;
                                }
                            };

                            const addLink = (label, url, options = {}) => {
                                if (!url) {
                                    return;
                                }
                                const link = document.createElement('a');
                                link.href = url;
                                link.textContent = label;

                                if (options.newTab) {
                                    link.target = '_blank';
                                    link.rel = 'noopener noreferrer';
                                }

                                actionsWrap.appendChild(link);
                            };

                            const addPreviewButton = (label, url) => {
                                if (!url) {
                                    return;
                                }
                                const button = document.createElement('button');
                                button.type = 'button';
                                button.textContent = label;
                                button.addEventListener('click', () => {
                                    setModalImage(url);
                                });
                                actionsWrap.appendChild(button);
                            };

                            addLink('View high-res', urls.img_high || defaultImage, { newTab: true });
                            addPreviewButton('Artwork', payload.image || defaultImage);
                            addPreviewButton('Wall preview', urls.wall_preview);
                            addPreviewButton('Preview (no border)', urls.wall_preview_without_border);
                        }

                        modal.classList.add('is-open');
                        document.body.classList.add('pictufy-modal-open');
                    };

                    const closeModal = () => {
                        modal.classList.remove('is-open');
                        document.body.classList.remove('pictufy-modal-open');
                    };

                    const bindClose = (element) => {
                        if (!element) {
                            return;
                        }
                        element.addEventListener('click', (event) => {
                            event.preventDefault();
                            closeModal();
                        });
                    };

                    bindClose(backdrop);
                    bindClose(closeBtn);

                    document.addEventListener('keydown', (event) => {
                        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                            closeModal();
                        }
                    });

                    const bindCard = (card) => {
                        if (!card || card.getAttribute('data-modal-bound') === '1') {
                            return;
                        }

                        card.setAttribute('data-modal-bound', '1');
                        card.addEventListener('click', () => {
                            const dataset = card.getAttribute('data-artwork');
                            if (!dataset) {
                                return;
                            }

                            try {
                                const payload = JSON.parse(dataset);
                                openModal(payload);
                            } catch (error) {
                                console.error('Pictufy artwork JSON parse error', error);
                            }
                        });
                    };

                    const bindAll = () => {
                        grid.querySelectorAll('.artwork-item[data-artwork]').forEach((card) => {
                            bindCard(card);
                        });
                    };

                    return {
                        bindCard,
                        bindAll,
                    };
                };

                containers.forEach((container) => {
                    const dataScriptId = container.getAttribute('data-json-id');
                    const chunk = parseInt(container.getAttribute('data-chunk-size'), 10) || 20;
                    const initialCount = parseInt(container.getAttribute('data-initial-count'), 10) || 0;
                    const grid = container.querySelector('.artworks-grid');
                    const loadMoreButton = container.querySelector('.pictufy-artworks-load');
                    const ajaxUrl = container.getAttribute('data-ajax-url');
                    const nonce = container.getAttribute('data-nonce');
                    const emptyText = container.getAttribute('data-empty-text') || 'No artworks found.';
                    const filtersAttr = container.getAttribute('data-filters') || '{}';
                    const filters = (() => {
                        try {
                            return JSON.parse(filtersAttr);
                        } catch (error) {
                            return {};
                        }
                    })();
                    const form = container.querySelector('.pictufy-artworks-filters');

                    const modalApi = enableModal(container);
                    modalApi.bindAll();

                    let currentPage = parseInt(container.getAttribute('data-page'), 10) || 1;
                    const perPage = parseInt(container.getAttribute('data-per-page'), 10) || 60;
                    let hasMore = container.getAttribute('data-has-more') === '1';
                    let isLoading = false;

                    const createCard = (item) => {
                        const card = document.createElement('div');
                        card.className = 'artwork-item';
                        card.setAttribute('data-artwork', JSON.stringify(item));

                        if (item.card_image) {
                            const img = document.createElement('img');
                            img.src = item.card_image;
                            img.alt = item.title || '';
                            img.loading = 'lazy';
                            img.decoding = 'async';
                            img.fetchPriority = 'low';
                            card.appendChild(img);
                        }

                        if (item.title) {
                            const heading = document.createElement('h3');
                            heading.textContent = item.title;
                            card.appendChild(heading);
                        }

                        if (item.artist) {
                            const artist = document.createElement('p');
                            artist.className = 'artist-name';
                            artist.textContent = item.artist;
                            card.appendChild(artist);
                        }

                        if (item.category) {
                            const meta = document.createElement('p');
                            meta.className = 'artwork-meta';
                            meta.textContent = item.category;
                            card.appendChild(meta);
                        }

                        modalApi.bindCard(card);

                        return card;
                    };

                    const requestArtworks = async (options = {}) => {
                        if (isLoading || !ajaxUrl) {
                            return;
                        }

                        isLoading = true;
                        if (loadMoreButton) {
                            loadMoreButton.disabled = true;
                            loadMoreButton.textContent = loadMoreButton.getAttribute('data-loading-text') || 'Loading…';
                        }

                        const payload = new FormData();
                        payload.append('action', 'pictufy_artworks');
                        payload.append('nonce', nonce || '');
                        payload.append('page', options.page || currentPage);
                        payload.append('per_page', perPage);
                        payload.append('order', options.order || filters.order || 'recommended');

                        const filtersToSend = Object.assign({}, filters, options.filters || {});
                        Object.keys(filtersToSend).forEach((key) => {
                            if (filtersToSend[key] !== undefined && filtersToSend[key] !== null) {
                                payload.append(`filters[${key}]`, filtersToSend[key]);
                            }
                        });

                        const reset = !options.append;

                        try {
                            const response = await fetch(ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: payload,
                            });

                            const result = await response.json();

                            if (!result || !result.success) {
                                throw new Error(result && result.data && result.data.message ? result.data.message : 'Unknown error');
                            }

                            if (reset && grid) {
                                grid.innerHTML = '';
                            }

                            const items = Array.isArray(result.data.items) ? result.data.items : [];

                            if (!items.length && grid && reset) {
                                grid.innerHTML = '<p class="pictufy-artworks-empty">' + emptyText + '</p>';
                            }

                            items.forEach((item) => {
                                const card = createCard(item);
                                if (grid) {
                                    grid.appendChild(card);
                                }
                            });

                            if (grid && !reset) {
                                modalApi.bindAll();
                            }

                            if (!options.append) {
                                currentPage = options.page || 1;
                            }

                            hasMore = !!result.data.has_more;
                            if (loadMoreButton) {
                                loadMoreButton.style.display = hasMore ? '' : 'none';
                                loadMoreButton.disabled = false;
                                loadMoreButton.textContent = loadMoreButton.getAttribute('data-default-text') || loadMoreButton.textContent;
                            }

                            container.setAttribute('data-has-more', hasMore ? '1' : '0');
                        } catch (error) {
                            console.error('Pictufy artworks request failed', error);
                            if (grid && !options.append) {
                                grid.innerHTML = '<p class="pictufy-artworks-empty">' + emptyText + '</p>';
                            }
                        } finally {
                            isLoading = false;
                            if (loadMoreButton) {
                                loadMoreButton.disabled = false;
                                loadMoreButton.textContent = loadMoreButton.getAttribute('data-default-text') || 'See more artworks';
                            }
                        }
                    };

                    if (loadMoreButton) {
                        loadMoreButton.setAttribute('data-default-text', loadMoreButton.textContent);
                        loadMoreButton.setAttribute('data-loading-text', loadMoreButton.getAttribute('data-loading-text') || 'Loading…');
                        loadMoreButton.addEventListener('click', () => {
                            if (!hasMore) {
                                loadMoreButton.style.display = 'none';
                                return;
                            }

                            currentPage += 1;
                            requestArtworks({ page: currentPage, append: true });
                        });
                    }

                    const sentinel = container.querySelector('.pictufy-artworks-sentinel');
                    let observer = null;

                    const ensureObserver = () => {
                        if (!sentinel || observer || !('IntersectionObserver' in window)) {
                            return;
                        }

                        observer = new IntersectionObserver((entries) => {
                            entries.forEach((entry) => {
                                if (!entry.isIntersecting || isLoading || !hasMore) {
                                    return;
                                }

                                currentPage += 1;
                                requestArtworks({ page: currentPage, append: true });
                            });
                        });

                        observer.observe(sentinel);
                    };

                    ensureObserver();

                    if (form) {
                        const resetButton = form.querySelector('.pictufy-artworks-reset');

                        form.addEventListener('submit', (event) => {
                            event.preventDefault();
                        });

                        const applyFilters = () => {
                            const formData = new FormData(form);
                            const newFilters = {};
                            formData.forEach((value, key) => {
                                newFilters[key] = value;
                            });

                            Object.assign(filters, newFilters);
                            currentPage = 1;
                            container.setAttribute('data-page', currentPage);
                            requestArtworks({ page: currentPage, filters: filters, order: filters.order });
                        };

                        let debounceTimer = null;
                        form.addEventListener('input', () => {
                            clearTimeout(debounceTimer);
                            debounceTimer = setTimeout(applyFilters, 400);
                        });

                        if (resetButton) {
                            resetButton.addEventListener('click', () => {
                                form.reset();
                                Object.keys(filters).forEach((key) => {
                                    filters[key] = '';
                                });
                                filters.order = 'recommended';
                                currentPage = 1;
                                requestArtworks({ page: currentPage, filters: filters, order: filters.order });
                            });
                        }
                    }

                    if (dataScriptId) {
                        const script = document.getElementById(dataScriptId);
                        if (script) {
                            try {
                                const initialItems = JSON.parse(script.textContent || '[]');
                                if (Array.isArray(initialItems) && initialItems.length) {
                                    initialItems.forEach((item, index) => {
                                        if (!grid || index < initialCount) {
                                            return;
                                        }

                                        const card = createCard(item);
                                        grid.appendChild(card);
                                    });
                                    modalApi.bindAll();
                                }
                            } catch (error) {
                                console.error('Pictufy artworks JSON parse error', error);
                            }
                        }
                    }

                    if (loadMoreButton) {
                        loadMoreButton.setAttribute('data-default-text', loadMoreButton.textContent);
                        loadMoreButton.setAttribute('data-loading-text', loadMoreButton.getAttribute('data-loading-text') || 'Loading…');
                        loadMoreButton.style.display = hasMore ? '' : 'none';
                        loadMoreButton.addEventListener('click', () => {
                            if (!hasMore) {
                                loadMoreButton.style.display = 'none';
                                return;
                            }

                            currentPage += 1;
                            requestArtworks({ page: currentPage, append: true });
                        });
                    }

                    ensureObserver();
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initArtworks);
            } else {
                initArtworks();
            }
        })();
    </script>
    <?php
}

function pictufy_render_styles($section) {
    static $styles_printed = array();

    if (isset($styles_printed[$section])) {
        return;
    }

    $styles_printed[$section] = true;
    ?>
    <style>
        <?php if ($section === 'collections'): ?>
        .pictufy-collections { padding: 20px; }
        .pictufy-collections .collections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 32px 28px;
        }
        @media (min-width: 1280px) {
            .pictufy-collections .collections-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
        .pictufy-collections .collection-item {
            border: 1px solid #e8e8e8;
            padding: 18px;
            border-radius: 16px;
            text-align: center;
            background: #fff;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .pictufy-collections .collection-item img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 16px;
        }
        .pictufy-collections .collection-item h3 {
            font-size: 17px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .pictufy-collections .collection-item p {
            font-size: 13px;
            color: #556;
        }
        .pictufy-collections .collection-item .collection-meta {
            font-size: 13px;
            color: #778;
            margin-top: 10px;
        }
        .pictufy-collections .collection-item .pictufy-collection-link {
            display: inline-block;
            margin-top: 18px;
            padding: 10px 20px;
            background: #111;
            color: #fff;
            border-radius: 999px;
            text-decoration: none;
            transition: background 0.3s;
            font-size: 14px;
            align-self: center;
        }
        .pictufy-collections .collection-item .pictufy-collection-link:hover {
            background: #444;
        }
        .pictufy-collections .pictufy-collections-load {
            display: block;
            margin: 24px auto 0;
            padding: 10px 28px;
            background: #111;
            color: #fff;
            border: none;
            border-radius: 999px;
            cursor: pointer;
            transition: background 0.3s;
            font-size: 15px;
        }
        .pictufy-collections .pictufy-collections-load:hover {
            background: #444;
        }
        .pictufy-collection-detail {
            padding: 60px 20px 80px;
        }
        .pictufy-collection-page .site-main,
        .pictufy-collection-page .entry-content,
        .pictufy-collection-page .content-area {
            max-width: 100%;
        }
        .pictufy-collection-detail .collection-hero {
            max-width: 1440px;
            margin: 0 auto 48px;
            text-align: center;
        }
        .pictufy-collection-detail .collection-hero img {
            width: 100%;
            height: 420px;
            object-fit: cover;
            border-radius: 18px;
            margin-bottom: 26px;
        }
        .pictufy-collection-detail .collection-hero h1 {
            font-size: 40px;
            margin-bottom: 10px;
            text-transform: none;
        }
        .pictufy-collection-detail .collection-hero p {
            font-size: 17px;
            color: #444;
        }
        .pictufy-collection-detail .collection-artworks {
            max-width: 1440px;
            margin: 0 auto;
            text-align: left;
        }
        .pictufy-collection-detail .collection-artworks h2 {
            font-size: 26px;
            margin-bottom: 28px;
            text-transform: none;
        }
        .pictufy-collection-detail .artworks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 24px;
        }
        @media (min-width: 1440px) {
            .pictufy-collection-detail .artworks-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }
        .pictufy-collection-detail .artwork-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .pictufy-collection-detail .artwork-card img {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        .pictufy-collection-detail .artwork-card .artwork-body {
            padding: 16px;
        }
        .pictufy-collection-detail .artwork-card h3 {
            font-size: 16px;
            margin-bottom: 8px;
        }
        .pictufy-collection-detail .artwork-card .artwork-meta {
            font-size: 13px;
            color: #777;
        }
        .pictufy-collection-detail .pagination {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 36px;
        }
        .pictufy-collection-detail .pagination a,
        .pictufy-collection-detail .pagination span {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 999px;
            background: #f1f1f1;
            text-decoration: none;
            color: #333;
            transition: background 0.3s;
        }
        .pictufy-collection-detail .pagination a:hover {
            background: #ddd;
        }
        .pictufy-collection-detail .pagination .current {
            background: #111;
            color: #fff;
        }
        <?php elseif ($section === 'artists'): ?>
        .pictufy-artists { padding: 40px 20px 60px; max-width: 1200px; margin: 0 auto; }
        .pictufy-artists h2 { font-size: 32px; margin-bottom: 32px; text-align: center; }
        .pictufy-artists .artists-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 28px 24px;
        }
        @media (min-width: 1200px) {
            .pictufy-artists .artists-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        .pictufy-artists .artist-item {
            background: #fff;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .pictufy-artists .artist-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 32px rgba(0,0,0,0.08);
        }
        .pictufy-artists .artist-item img {
            width: 130px;
            height: 130px;
            object-fit: cover;
            border-radius: 50%;
            margin-bottom: 12px;
        }
        .pictufy-artists .artist-item h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .pictufy-artists .artist-item p {
            font-size: 14px;
            color: #666;
        }
        .pictufy-artists .artist-meta {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
            font-size: 13px;
            color: #555;
        }
        .pictufy-artists .artist-meta .artist-type {
            background: #f5f5f7;
            padding: 4px 10px;
            border-radius: 999px;
        }
        .pictufy-artists .artist-meta .artist-count {
            background: #f5f5f7;
            padding: 4px 10px;
            border-radius: 999px;
        }
        .pictufy-artists .pictufy-artists-load {
            margin: 32px auto 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 26px;
            border-radius: 999px;
            border: none;
            background: #111;
            color: #fff;
            font-size: 15px;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .pictufy-artists .pictufy-artists-load:hover {
            background: #333;
        }
        <?php elseif ($section === 'artworks'): ?>
        .pictufy-artworks {
            padding: 40px 20px 80px;
            max-width: 1440px;
            margin: 0 auto;
        }
        .pictufy-artworks h2 {
            font-size: 32px;
            margin-bottom: 36px;
            text-align: center;
        }
        .pictufy-artworks form.pictufy-artworks-filters {
            margin-bottom: 32px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
        }
        .pictufy-artworks .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px 22px;
        }
        .pictufy-artworks .filter-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .pictufy-artworks .filter-field label {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        .pictufy-artworks .filter-field select,
        .pictufy-artworks .filter-field input[type="search"] {
            border: 1px solid #dcdcdc;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            background-color: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .pictufy-artworks .filter-field select:focus,
        .pictufy-artworks .filter-field input[type="search"]:focus {
            outline: none;
            border-color: #111;
            box-shadow: 0 0 0 2px rgba(17, 17, 17, 0.1);
        }
        .pictufy-artworks .filter-field--search {
            grid-column: 1 / -1;
        }
        .pictufy-artworks .filter-actions {
            margin-top: 20px;
            display: flex;
            justify-content: flex-end;
        }
        .pictufy-artworks .pictufy-artworks-reset {
            border: none;
            background: #111;
            color: #fff;
            border-radius: 999px;
            padding: 10px 22px;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .pictufy-artworks .pictufy-artworks-reset:hover {
            background: #333;
        }
        .pictufy-artworks .artworks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 28px;
        }
        .pictufy-artworks .artwork-item {
            background: #fff;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
            text-align: left;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .pictufy-artworks .artwork-item:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 42px rgba(0, 0, 0, 0.12);
        }
        .pictufy-artworks .artwork-item img {
            width: 100%;
            height: 240px;
            object-fit: cover;
            border-radius: 14px;
            margin-bottom: 14px;
        }
        .pictufy-artworks .artwork-item h3 {
            font-size: 18px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .pictufy-artworks .artwork-item .artist-name {
            font-size: 14px;
            color: #666;
            margin-bottom: 4px;
        }
        .pictufy-artworks .artwork-item .artwork-meta {
            font-size: 13px;
            color: #999;
        }
        @media (max-width: 768px) {
            .pictufy-artworks form.pictufy-artworks-filters {
                padding: 20px;
            }
            .pictufy-artworks .filter-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            .pictufy-artworks .filter-field--search {
                grid-column: auto;
            }
            .pictufy-artworks .filter-actions {
                justify-content: stretch;
            }
            .pictufy-artworks .pictufy-artworks-reset {
                width: 100%;
            }
        }
        .pictufy-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .pictufy-modal.is-open {
            display: flex;
        }
        .pictufy-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
        }
        .pictufy-modal-window {
            position: relative;
            max-width: 1020px;
            width: calc(100% - 40px);
            background: #fff;
            border-radius: 24px;
            padding: 32px;
            display: flex;
            gap: 32px;
            box-shadow: 0 30px 65px rgba(0, 0, 0, 0.25);
            z-index: 1;
        }
        .pictufy-modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: #111;
            color: #fff;
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
        }
        .pictufy-modal-body {
            display: flex;
            flex: 1;
            gap: 28px;
            flex-wrap: wrap;
        }
        .pictufy-modal-media {
            flex: 1 1 360px;
        }
        .pictufy-modal-media img {
            width: 100%;
            border-radius: 18px;
            object-fit: cover;
            max-height: 520px;
        }
        .pictufy-modal-info {
            flex: 1 1 320px;
        }
        .pictufy-modal-info h3 {
            font-size: 28px;
            margin-bottom: 12px;
        }
        .pictufy-modal-artist {
            font-size: 16px;
            color: #666;
            margin-bottom: 18px;
        }
        .pictufy-modal-details {
            list-style: none;
            margin: 0 0 20px;
            padding: 0;
        }
        .pictufy-modal-details li {
            font-size: 14px;
            color: #444;
            margin-bottom: 8px;
        }
        .pictufy-modal-keywords {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }
        .pictufy-modal-keywords span {
            background: #f1f1f5;
            color: #333;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 13px;
        }
        .pictufy-modal-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .pictufy-modal-actions a {
            display: inline-block;
            padding: 10px 18px;
            border-radius: 999px;
            background: #111;
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
        }
        .pictufy-modal-actions a:hover {
            background: #444;
        }
        body.pictufy-modal-open {
            overflow: hidden;
        }
        @media (max-width: 900px) {
            .pictufy-modal-window {
                flex-direction: column;
                align-items: stretch;
                padding: 24px;
            }
            .pictufy-modal-body {
                flex-direction: column;
            }
            .pictufy-modal-media img {
                max-height: none;
            }
        }
        <?php endif; ?>
    </style>
    <?php
}

function pictufy_ensure_pages() {
    $current_version = '1.3';
    $stored_version = get_option('pictufy_plugin_version');

    $pages = array(
        array(
            'title' => __('Colecciones', 'pictufy-integration'),
            'slug' => 'colecciones',
            'shortcode' => '[pictufy_collections]',
        ),
        array(
            'title' => __('Artistas', 'pictufy-integration'),
            'slug' => 'artistas',
            'shortcode' => '[pictufy_artists]',
        ),
        array(
            'title' => __('Explorar', 'pictufy-integration'),
            'slug' => 'explorar',
            'shortcode' => '[pictufy_artworks]',
        ),
    );

    foreach ($pages as $page) {
        $existing = get_page_by_path($page['slug']);

        $should_update = ($stored_version !== $current_version);

        if (!$existing) {
            $new_id = wp_insert_post(array(
                'post_title' => $page['title'],
                'post_name' => $page['slug'],
                'post_content' => $page['shortcode'],
                'post_status' => 'publish',
                'post_type' => 'page',
            ));
            if (!is_wp_error($new_id)) {
                update_post_meta($new_id, '_pictufy_page_version', $current_version);
            }
            continue;
        }

        $page_version = get_post_meta($existing->ID, '_pictufy_page_version', true);

        if ($should_update || $page_version !== $current_version || strpos($existing->post_content, $page['shortcode']) === false) {
            $update_result = wp_update_post(array(
                'ID' => $existing->ID,
                'post_content' => $page['shortcode'],
            ));

            if (!is_wp_error($update_result)) {
                update_post_meta($existing->ID, '_pictufy_page_version', $current_version);
            }
        }
    }

    if ($stored_version !== $current_version) {
        update_option('pictufy_plugin_version', $current_version);
    }
}

function pictufy_activate_plugin() {
    pictufy_ensure_pages();
    pictufy_schedule_expired_cleanup();
}

function pictufy_deactivate_plugin() {
    pictufy_unschedule_expired_cleanup();
}

register_activation_hook(__FILE__, 'pictufy_activate_plugin');
register_deactivation_hook(__FILE__, 'pictufy_deactivate_plugin');
add_action('init', 'pictufy_ensure_pages');