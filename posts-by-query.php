<?php
/*
Plugin Name: FCP Posts by Search Query
Description: Searches and prints the posts tiles by query
Version: 1.0.0
Requires at least: 5.8
Tested up to: 6.1
Requires PHP: 7.4
Author: Firmcatalyst, Vadim Volkov
Author URI: https://firmcatalyst.com
License: GPL v3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace FCP\PostsByQuery;

defined( 'ABSPATH' ) || exit;

define( 'FCPPBK_SLUG', 'fcppbk' );
define( 'FCPPBK_PREF', FCPPBK_SLUG.'-' );

define( 'FCPPBK_DEV', true );
define( 'FCPPBK_VER', get_file_data( __FILE__, [ 'ver' => 'Version' ] )[ 'ver' ] . ( FCPPBK_DEV ? time() : '' ) );

function layout_options($list_length = 0) {
    $list_length = is_numeric( $list_length ) ? $list_length : 10;
    return [
        '2-columns' => [
            't' => '2 columns',
            'l' => [
                'column' => 2,
            ],
        ],
        '3-columns' => [
            't' => '3 columns',
            'l' => [
                'column' => 3,
            ],
        ],
        '2-columns-1-list' => [
            't' => '2 columns + 1 list',
            'l' => [
                'column' => 2,
                'list' => $list_length,
            ],
        ],
        '1-list' => [
            't' => '1 list',
            'l' => [
                'list' => $list_length,
            ],
        ],
        '1-tile' => [
            't' => '1 tile',
            'l' => [
                'column' => 1,
            ],
        ],
    ];
}

function styling_options() {
    return [
        '' => 'None',
        'style-1' => 'Style 1',
        'style-2' => 'Style 2',
    ];
}

function default_values() {
    return [
        'main-color' => '#007cba',
        'secondary-color' => '#abb8c3',
        'layout' => '3-columns',
        'thumbnail-size' => 'medium',
        'excerpt-length' => '200',
        'select-from' => [ 'post' ],
        'apply-to' => [ 'page', 'post' ],
    ];
}

// fill in the initial settings
register_activation_hook( __FILE__, function() {
    add_option( FCPPBK_PREF.'settings', default_values() );
});

// admin interface
add_action( 'add_meta_boxes', function() {
    if ( !current_user_can( 'administrator' ) ) { return; }
    if ( empty( apply_to_post_types() ) ) { return; }
    add_meta_box(
        'fcp-posts-by-query',
        'Posts by Query',
        __NAMESPACE__.'\metabox_query',
        apply_to_post_types(),
        'normal',
        'low'
    );
});
// style meta boxes && settings
add_action( 'admin_enqueue_scripts', function() {

    if ( !current_user_can( 'administrator' ) ) { return; }
    $files = [ 'post' => [ 'metabox', 'advisor' ], 'settings_page_posts-by-query' => [ 'settings', 'codemirror', 'media' ] ];
    $screen = get_current_screen();
    if ( !isset( $screen ) || !is_object( $screen ) || !isset( $files[ $screen->base ] ) ) { return; }

    $assets_path = __DIR__.'/assets';
    foreach ( scandir( $assets_path ) as $v ) {
        if ( !is_file( $assets_path.'/'.$v ) ) { continue; }
        $onthelist = array_reduce( $files[ $screen->base ], function( $result, $item ) use ( $v ) {
            $result = $result ?: ( strpos( $v, $item.'.' ) === 0 );
            return $result;
        }, false );
        if ( !$onthelist ) { continue; }

        $ext = substr( $v, strrpos( $v, '.' )+1 );
        $handle = FCPPBK_PREF.preg_replace( ['/\.(?:js|css)$/', '/[^a-z0-9\-_]/'], '', $v );
        $url = plugin_dir_url(__FILE__).'assets/'.$v;

        if ( $ext === 'css' ) { wp_enqueue_style( $handle, $url, [], FCPPBK_VER, 'all' ); }
        if ( $ext === 'js' ) { wp_enqueue_script( $handle, $url, [], FCPPBK_VER, false ); }
    }

    // the css editor
    wp_localize_script( 'jquery', 'cm_settings', [ 'codeEditor' => wp_enqueue_code_editor( ['type' => 'text/css'] ) ] );
    wp_enqueue_script( 'wp-theme-plugin-editor' );
    wp_enqueue_style( 'wp-codemirror' );

    // the media popup main
    wp_enqueue_media();
});

// api to fetch the posts
// api to fetch the query
add_action( 'rest_api_init', function () {

    $route_args = function($results_format) {

        if ( empty( select_from_post_types() ) ) {
            return new \WP_Error( 'post_type_not_selected', 'Post type not selected', [ 'status' => 404 ] );
        }

        $wp_query_args = [
            'post_type' => select_from_post_types(),
            'post_status' => 'publish',
            //'sentence' => true,
            'posts_per_page' => 20,
        ];

        $format_output = function( $p ) {
            return [ 'id' => $p->ID, 'title' => $p->post_title ]; // get_the_title() forces different quotes in different languages or so
        };

        switch ( $results_format ) {
            case ( 'list' ):
                // $wp_query_args += [ 'orderby' => 'title', 'order' => 'ASC' ]; // autocomplete orders by title anyways
            break;
            case ( 'query' ):
                $wp_query_args += [ 'orderby' => 'date', 'order' => 'DESC' ];
                $format_output = function( $p ) {
                    $date = wp_date( get_option( 'date_format', 'j F Y' ), strtotime( $p->post_date ) );
                    return [ 'id' => $p->ID, 'title' => $p->post_title . ' ('.public_post_types()[$p->post_type].', '.$date.')' ];
                };
            break;
        }

        if ( FCPPBK_DEV ) { usleep( rand(0, 1000000) ); } // simulate server responce delay

        return [
            'methods'  => 'GET',
            'callback' => function( \WP_REST_Request $request ) use ( $wp_query_args, $format_output ) {

                $wp_query_args['s'] = $request['search'];

                $search = new \WP_Query( $wp_query_args );
    
                if ( !$search->have_posts() ) {
                    return new \WP_REST_Response( [], 200 ); // new \WP_Error( 'nothing_found', 'No results found', [ 'status' => 404 ] );
                }
    
                $result = [];
                while ( $search->have_posts() ) {
                    $p = $search->next_post();
                    $result[] = $format_output( $p ); // not using the id as the key to keep the order in json
                }
    
                $result = new \WP_REST_Response( $result, 200 );
    
                if ( FCPPBK_DEV ) { nocache_headers(); }
    
                return $result;
            },
            'permission_callback' => function() {
                if ( empty( $_SERVER['HTTP_REFERER'] ) ) { return false; }
                if ( strtolower( parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_HOST ) ) !== strtolower( $_SERVER['HTTP_HOST'] ) ) { return false; }
                if ( !current_user_can( 'administrator' ) ) { return false; } // works only with X-WP-Nonce header passed
                return true;
            },
            'args' => [
                'search' => [
                    'description' => 'The search query',
                    'type'        => 'string',
                    'validate_callback' => function($param) {
                        return trim( $param ) ? true : false;
                    },
                    'sanitize_settings' => function($param, $request, $key) {
                        return sanitize_text_field( urldecode( $param ) ); // return htmlspecialchars( wp_unslash( urldecode( $param ) ) );
                    },
                ],
            ],
        ];
    };

    register_rest_route( FCPPBK_SLUG.'/v1', '/list/(?P<search>.{1,90})', $route_args( 'list' ) );
    register_rest_route( FCPPBK_SLUG.'/v1', '/query/(?P<search>.{1,90})', $route_args( 'query' ) );
});


function metabox_query() {
    global $post;

    ?>
    <div class="<?php echo esc_attr( FCPPBK_PREF ) ?>tabs">
        <?php
        radio( (object) [
            'name' => FCPPBK_PREF.'variants',
            'value' => 'query',
            'checked' => get_post_meta( $post->ID, FCPPBK_PREF.'variants' )[0] ?? false,
            'default' => true,
        ]);
        ?>
        <div>
            <p><strong>Posts by Search Query &amp; Date</strong></p>
            <?php
            text( (object) [
                'name' => FCPPBK_PREF.'query',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'query' )[0] ?? '',
            ]);
            ?>
        </div>

        <?php
        radio( (object) [
            'name' => FCPPBK_PREF.'variants',
            'value' => 'list',
            'checked' => get_post_meta( $post->ID, FCPPBK_PREF.'variants' )[0] ?? false,
        ]);
        ?>
        <div>
            <p><strong>Particular Posts</strong></p>
            <?php
            text( (object) [
                'name' => FCPPBK_PREF.'list',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'list' )[0] ?? '',
            ]);
            ?>
        </div>
    </div>

    <div id="<?php echo esc_attr( FCPPBK_PREF ) ?>tiles">
        <?php
        $ids = get_post_meta( $post->ID, FCPPBK_PREF.'posts' )[0] ?? [];
        $result = [];
        if ( !empty( $ids ) ) {

            $search = new \WP_Query( [
                //'post_type' => select_from_post_types(), // commented to keep on accident save. filter still works in shortcode
                //'post_status' => 'publish', // same
                'post__in' => $ids,
                'orderby' => 'post__in',
            ] );
            if ( $search->have_posts() ) {
                while ( $search->have_posts() ) {
                    $p = $search->next_post();
                    $result[ $p->ID ] = $p->post_title;
                }
            }
        }

        checkboxes( (object) [
            'name' => FCPPBK_PREF.'posts',
            'options' => $result,
            'value' => $ids,
        ]);
        ?>
        <fieldset id="<?php echo esc_attr( FCPPBK_PREF ) ?>posts-preview"></fieldset>
    </div>
    
    <input type="hidden" name="<?php echo esc_attr( FCPPBK_PREF ) ?>nonce" value="<?= esc_attr( wp_create_nonce( FCPPBK_PREF.'nonce' ) ) ?>">
    <input type="hidden" id="<?php echo esc_attr( FCPPBK_PREF ) ?>rest-nonce" value="<?= esc_attr( wp_create_nonce( 'wp_rest' ) ) ?>">

    <?php
}


function public_post_types() {
    static $store = [];

    if ( !empty( $store ) ) { return $store; }

    $all = get_post_types( [], 'objects' );
    foreach ( $all as $type ) {
        if ( !$type->public ) { continue; }
        $slug = $type->rewrite->slug ?? $type->name;
        $store[ $slug ] = $type->label;
    }

    asort( $store, SORT_STRING );

    return $store;
}

function get_settings() {
    static $settings = [];
    if ( !empty( $settings ) ) { return $settings; }
    $settings = get_option( FCPPBK_PREF.'settings' );
    return $settings;
}

function apply_to_post_types() {
    return array_intersect( array_keys( public_post_types() ), get_settings()['apply-to'] ?? [] );
}
function select_from_post_types() {
    return array_intersect( array_keys( public_post_types() ), get_settings()['select-from'] ?? [] );
}

// save meta data
add_action( 'save_post', function( $postID ) {

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
    if ( !wp_verify_nonce( $_POST[ FCPPBK_PREF.'nonce' ], FCPPBK_PREF.'nonce' ) ) { return; }
    //if ( !current_user_can( 'edit_post', $postID ) ) { return; }
    if ( !current_user_can( 'administrator' ) ) { return; }

    $post = get_post( $postID );
    if ( $post->post_type === 'revision' ) { return; } // update_post_meta fixes the id to the parent, but id can be used before

    $fields = [ 'variants', 'query', 'posts' ];

    foreach ( $fields as $f ) {
        $f = FCPPBK_PREF . $f;
        if ( empty( $_POST[ $f ] ) || empty( $new_value = sanitize_meta( $_POST[ $f ], $f, $postID ) ) ) {
            delete_post_meta( $postID, $f );
            continue;
        }
        update_post_meta( $postID, $f, $new_value );
    }
});

function sanitize_meta( $value, $field, $postID ) {

    $field = ( strpos( $field, FCPPBK_PREF ) === 0 ) ? substr( $field, strlen( FCPPBK_PREF ) ) : $field;

    switch ( $field ) {
        case ( 'variants' ):
            return in_array( $value, ['query', 'list'] ) ? $value : 'query';
        break;
        case ( 'query' ):
            return sanitize_text_field( $value );
        break;
        case ( 'posts' ):
            return array_values( array_filter( $value, 'is_numeric' ) );
            // post-type & is-published filters are performed before printing on the front-end to not ruin on save if something changed in settings
        break;
    }

    return '';
}

add_shortcode( FCPPBK_SLUG, function() { // ++ check outside the loop && fix!!

    if ( empty( select_from_post_types() ) ) { return; }
    if ( !in_array( get_post_type(), apply_to_post_types() ) ) { return; }

    $settings = get_settings();

    // styles
    $handle = FCPPBK_PREF.'settings';
    wp_register_style( $handle, false );
    wp_enqueue_style( $handle );
    wp_add_inline_style( $handle, '.'.FCPPBK_SLUG.'{--main-color:'.$settings['main-color'].';--secondary-color:'.$settings['secondary-color'].';}' );

    $path = 'css-layout/'.$settings['layout'].'.css';
    $handle = FCPPBK_PREF.'layout';
    if ( is_file( __DIR__.'/' . $path ) ) {
        wp_enqueue_style( $handle, plugins_url( '/' ,__FILE__ ) . $path, [], FCPPBK_DEV ? FCPPBK_VER : FCPPBK_VER.'.'.filemtime( __DIR__.'/' . $path ) );
    }

    $path = 'css-styling/'.$settings['style'].'.css';
    $handle = FCPPBK_PREF.'style';
    if ( is_file( __DIR__.'/' . $path ) ) {
        wp_enqueue_style( $handle, plugins_url( '/' ,__FILE__ ) . $path, [], FCPPBK_DEV ? FCPPBK_VER : FCPPBK_VER.'.'.filemtime( __DIR__.'/' . $path ) );
    }

    $handle = FCPPBK_PREF.'additional';
    if ( $settings['additional-css'] ?? trim( $settings['additional-css'] ) ) {
        wp_register_style( $handle, false );
        wp_enqueue_style( $handle );
        wp_add_inline_style( $handle, $settings['additional-css'] );
    }


    $metas = array_map( function( $value ) {
        return $value[0];
    }, array_filter( get_post_custom(), function($key) {
        return strpos( $key, FCPPBK_PREF ) === 0;
    }, ARRAY_FILTER_USE_KEY ) );

    $layouts = layout_options( $settings['limit-the-list'] )[ $settings['layout'] ]['l'];

    $limit = array_reduce( $layouts, function( $result, $item ) {
        $result += $item;
        return $result;
    }, 0 );

    $wp_query_args = [
        'post_type' => select_from_post_types(),
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'post__not_in' => [ get_the_ID() ],
    ];

    $results_format = $metas[ FCPPBK_PREF.'variants' ];
    switch ( $results_format ) {
        case ( 'list' ):
            $ids = unserialize( $metas[ FCPPBK_PREF.'posts' ] );
            if ( empty( $ids ) ) { return; }
            $wp_query_args += [ 'post__in' => $ids, 'orderby' => 'post__in' ];
        break;
        case ( 'query' ):
            $query = $metas[ FCPPBK_PREF.'query' ];
            if ( trim( $query ) === '' ) { return; }
            $wp_query_args += [ 'orderby' => 'date', 'order' => 'DESC', 's' => $query ];
        break;
        default:
            return;
    }

    $search = new \WP_Query( $wp_query_args );

    if ( !$search->have_posts() ) { return; }

    $get_template = function($name, $is_part = true) {
        static $cached = [];
        if ( isset( $cached[ $name ] ) ) { return $cached[ $name ]; }
        if ( ( $template = file_get_contents( __DIR__.'/templates/'.($is_part ? 'parts/' : '').$name.'.html' ) ) === false ) { return ''; }
        return ( $cached[ $name ] = $template );
    };
    $fill_template = function($params, $template_name, $is_part = true) use ($get_template) {
        return strtr( $get_template( $template_name, $is_part ), array_reduce( array_keys( $params ), function( $result, $item ) use ( $params ) {
            $result[ '%'.$item ] = $params[ $item ];
            return $result;
        }, [] ) );
    };

    $params_initial = [];
    $params = [];
    $param_add = function( $key, $conditions = true ) use ( $fill_template, &$params_initial, &$params ) {
        $add = function($key) use ($fill_template, $params_initial, &$params, $conditions) {
            $params[ $key ] = $conditions ? $fill_template( $params_initial, $key ) : '';
        };
        if ( !is_array( $key ) ) {
            $add( $key );
            return;
        }
        foreach( $key as $v ) {
            $add( $v );
        }
    };

    $crop_excerpt = function($text, $length) {
        if ( !trim( $text ) || !is_numeric( $length ) ) { return ''; }
        $text = substr( $text, 0, $length );
        $text = rtrim( substr( $text, 0, strrpos( $text, ' ' ) ), ',.…!?&([{-_ "„“' ) . '…';
        return $text;
    };

    $posts = [];
    while ( $search->have_posts() ) {
        //$search->the_post(); // has the conflict with Glossary (Premium) plugin, which flushes the first post in a loop to the root one with the_excerpt()
        $p = $search->next_post();

        $categories = isset( $settings['hide-category'] ) ? [] : get_the_category( $p );

		$thumbnail = $settings['thumbnail-size'] ? (
			get_the_post_thumbnail( $p, $settings['thumbnail-size'] )
            ?: wp_get_attachment_image( $settings['default-thumbnail'], $settings['thumbnail-size'] )
            ?: '<img src="data:image/svg+xml,%3Csvg width=\'16\' height=\'16\' version=\'1.1\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3C/svg%3E" class="blank">'
		) : '';

        $params_initial = [
            'id' => get_the_ID( $p ),
            'permalink' => get_permalink( $p ),
            'title' => get_the_title( $p ),
            'date' => isset( $settings['hide-date'] ) ? '' : get_the_date( '', $p ),
            'datetime_attr' => isset( $settings['hide-date'] ) ? '' : get_the_date( 'Y-m-d', $p ),
            'excerpt' => isset( $settings['hide-excerpt'] ) ? '' : esc_html( $crop_excerpt( get_the_excerpt( $p ), $settings['excerpt-length'] ) ),
            'category' => empty( $categories ) ? '' : esc_html( $categories[0]->name ),
            'category_link' => empty( $categories ) ? '' : get_category_link( $categories[0]->term_id ),
            'thumbnail' => $thumbnail,
            'readmore' => esc_html( __( $settings['read-more-text'] ?: 'Read more' ) ),
        ];
        ksort( $params_initial, SORT_STRING ); // avoid smaller replacing bigger parts ++test if DESC

        $param_add( 'title_linked' );
        $param_add( 'title_linked_formatted' );
        $param_add( 'date_formatted', $params_initial['date'] );
        $param_add( 'excerpt_formatted', $params_initial['excerpt'] );
        $param_add( 'category_linked_formatted', $params_initial['category'] && $params_initial['category_link'] );
        $param_add( 'thumbnail_linked', $params_initial['thumbnail'] );
        $param_add( 'button', !isset( $settings['hide-read-more'] ) );
        //return '<pre>'.print_r( $params + $params_initial, true ).'</pre>';
        $posts[] = $params + $params_initial;
    }

    $result = [
        'headline' => $settings['headline'] ? $fill_template( [ 'headline' => $settings['headline'] ], 'headline' ) : '',
        'css_class' => FCPPBK_SLUG . ' ' . FCPPBK_PREF.$settings['layout'] . ' ' . $settings['css-class'],
        'column' => '',
        'list' => '',
    ];
    $ind = 0;

    foreach ( $layouts as $k => $v ) {
        for ( $i = 0; $i < $v; $i++ ) {
            if ( !isset( $posts[ $ind ] ) ) { break 2; }
            $result[ $k ] .= $fill_template( $posts[ $ind ], $k );
            $ind++;
        }
    }

    return $fill_template( $result, $settings['layout'], false );

});


// settings page
add_action( 'admin_menu', function() {
    // capabilities filter is inside
	add_options_page( 'Posts by Queryuery settings', 'Posts by Queryuery', 'switch_themes', 'posts-by-query', function() {
        ?>
        <div class="wrap">
            <h2><?php echo get_admin_page_title() ?></h2>
    
            <form action="options.php" method="POST">
                <?php
                    do_settings_sections( FCPPBK_PREF.'settings-page' ); // print fields of the page / tab
                    submit_button();
                    settings_fields( FCPPBK_PREF.'settings-group1' ); // nonce
                ?>
            </form>
        </div>
        <?php
    });
});

add_action( 'admin_init', function() {

    // ++filter for admin & screen

    $settings = (object) [
        'page' => FCPPBK_PREF.'settings-page',
        'varname' => FCPPBK_PREF.'settings',
    ];
    $settings->values = get_option( $settings->varname );
    // $settings->section goes later

    $add_settings_field = function( $title, $type = '', $atts = [] ) use ( $settings ) { // $atts: placeholder, options, option, step

        $types = [ 'text', 'color', 'number', 'textarea', 'radio', 'checkbox', 'checkboxes', 'select', 'comment', 'image' ];
        $type = ( empty( $type ) || !in_array( $type, $types ) ) ? $types[0] : $type;
        $function = __NAMESPACE__.'\\'.$type;
        if ( !function_exists( $function ) ) { return; }
        $slug = $atts['slug'] ?? sanitize_title( $title );

        $attributes = (object) [
            'name' => $settings->varname.'['.$slug.']',
            'id' => $settings->varname . '--' . $slug,
            'value' => $slug ? ( $settings->values[ $slug ] ?? '' ) : '',
            'placeholder' => $atts['placeholder'] ?? '',
            'className' => $atts['className'] ?? '',
            'options' => $atts['options'] ?? '',
            'option' => $atts['option'] ?? '',
            'label' => $atts['label'] ?? '',
            'comment' => $atts['comment'] ?? '',
            'rows' => $atts['rows'] ?? 10,
            'cols' => $atts['cols'] ?? 50,
        ];

        add_settings_field(
            $slug,
            $title,
            function() use ( $attributes, $function ) { call_user_func( $function, $attributes ); },
            $settings->page,
            $settings->section
        );
    };

    $layout_options = array_map( function( $a ) {
        return $a['t'];
    }, layout_options() );

    $styling_options = styling_options();

    $thumbnail_sizes = wp_get_registered_image_subsizes(); //++full, ++no image
    $thumbnail_sizes = [ '' => 'No image', 'full' => 'Full' ] + array_reduce( array_keys( $thumbnail_sizes ), function( $result, $item ) use ( $thumbnail_sizes ) {
        $result[ $item ] = ucfirst( $item ) . ' ('.$thumbnail_sizes[$item]['width'].'x'.$thumbnail_sizes[$item]['height'].')';
        return $result;
    }, [] );

    // structure of fields
    $settings->section = 'description';
	add_settings_section( $settings->section, 'Description', '', $settings->page );
        $add_settings_field( '', 'comment', [ 'comment' => '<p>Add the posts section with the following shortcode <code>['.FCPPBK_SLUG.']</code></p>' ] );

    $settings->section = 'styling-settings';
	add_settings_section( $settings->section, 'Styling settings', '', $settings->page );
        $add_settings_field( 'Main color', 'color' ); // ++use wp default picker
        $add_settings_field( 'Secondary color', 'color' );
        $add_settings_field( 'Layout', 'select', [ 'options' => $layout_options ] );
        $add_settings_field( 'Style', 'select', [ 'options' => $styling_options ] );
        $add_settings_field( 'Additional CSS', 'textarea' );
        $add_settings_field( 'Limit the list', 'number', [ 'placeholder' => '10', 'step' => 1, 'comment' => 'If the Layout contains the List, this number will limit the amount of posts in it' ] ); // ++ make the comment work
        $add_settings_field( 'Default thumbnail', 'image', [ 'comment' => 'This image is shown, if a post doesn\'t have the featured image', 'className' => 'image' ] );
        $add_settings_field( 'Thumbnail size', 'select', [ 'options' => $thumbnail_sizes ] );
        $add_settings_field( 'Excerpt length', 'number', [ 'step' => 1, 'comment' => 'Cut the excerpt to the number of symbols' ] );
        $add_settings_field( '"Read more" text', 'text', [ 'placeholder' => __( 'Read more' ) ] );

    $settings->section = 'hide-details';
    add_settings_section( $settings->section, 'Hide details', '', $settings->page );
        $add_settings_field( '', 'checkbox', [ 'option' => '1', 'label' => 'Hide the date', 'slug' => 'hide-date' ] );
        $add_settings_field( '', 'checkbox', [ 'option' => '1', 'label' => 'Hide the excerpt', 'slug' => 'hide-excerpt' ] );
        $add_settings_field( '', 'checkbox', [ 'option' => '1', 'label' => 'Hide the category', 'slug' => 'hide-category' ] );
        $add_settings_field( '', 'checkbox', [ 'option' => '1', 'label' => 'Hide the "'.__('Read more').'" button', 'slug' => 'hide-read-more' ] );

    $settings->section = 'other-settings';
    add_settings_section( $settings->section, 'Other settings', '', $settings->page );
        $add_settings_field( 'Headline', 'text' );
        $add_settings_field( 'CSS Class', 'text' );
        $add_settings_field( 'Select from', 'checkboxes', [ 'options' => public_post_types() ] );
        $add_settings_field( 'Apply to', 'checkboxes', [ 'options' => public_post_types(), 'comment' => 'This will add the option to query the posts to selected post types editor bottom' ] );
        //$add_settings_field( 'Defer style', 'checkbox', [ 'option' => '1', 'label' => 'defer the render blocking style.css', 'comment' => 'If you use a caching plugin, most probably it fulfulls the role of this checkbox' ] );

    register_setting( FCPPBK_PREF.'settings-group1', $settings->varname, __NAMESPACE__.'\sanitize_settings' ); // register, save, nonce
});

function text($a, $type = '') {
    ?>
    <input type="<?php echo in_array( $type, ['color', 'number'] ) ? $type : 'text' ?>"
        name="<?php echo esc_attr( $a->name ) ?>"
        id="<?php echo esc_attr( $a->id ?? $a->name ) ?>"
        placeholder="<?php echo esc_attr( $a->placeholder ?? '' ) ?>"
        value="<?php echo esc_attr( $a->value ?? '' ) ?>"
        class="<?php echo esc_attr( $a->className ?? '' ) ?>"
        <?php echo isset( $a->step ) ? 'step="'.esc_attr( $a->step ).'"' : '' ?>
    />
    <?php echo isset( $a->comment ) ? '<p><em>'.esc_html( $a->comment ).'</em></p>' : '' ?>
    <?php
}
function color($a) { text( $a, 'color' ); }
function number($a) { text( $a, 'number' ); }

function comment($a) {
    echo wp_filter_kses( $a->comment );
}

function textarea($a) {
    ?>
    <textarea
        name="<?php echo esc_attr( $a->name ) ?>"
        id="<?php echo esc_attr( $a->id ?? $a->name ) ?>"
        placeholder="<?php echo esc_attr( $a->placeholder ?? '' ) ?>"
        class="<?php echo esc_attr( $a->className ?? '' ) ?>"
        rows="<?php echo esc_attr( $a->rows ) ?>"
        cols="<?php echo esc_attr( $a->cols ) ?>"
    ><?php echo esc_textarea( $a->value ?? '' ) ?></textarea>
    <?php echo isset( $a->comment ) ? '<p><em>'.esc_html( $a->comment ).'</em></p>' : '' ?>
    <?php
}

function select($a) {
    ?>
    <select
        name="<?php echo esc_attr( $a->name ) ?>"
        id="<?php echo esc_attr( $a->id ?? $a->name ) ?>"
        class="<?php echo esc_attr( $a->className ?? '' ) ?>"
    >
    <?php foreach ( $a->options as $k => $v ) { ?>
        <option value="<?php echo esc_attr( $k ) ?>"
            <?php selected( !empty( $a->value ) && $k === $a->value, true ) ?>
        ><?php echo esc_html( $v ) ?></option>
    <?php } ?>
    </select>
    <?php echo isset( $a->comment ) ? '<p><em>'.esc_html( $a->comment ).'</em></p>' : '' ?>
    <?php
}
function checkboxes($a) {
    ?>
    <fieldset
        id="<?php echo esc_attr( $a->id ?? $a->name ) ?>"
        class="<?php echo esc_attr( $a->className ?? '' ) ?>"
    >
    <?php foreach ( $a->options as $k => $v ) { ?>
        <label>
            <input type="checkbox"
                name="<?php echo esc_attr( $a->name ) ?>[]"
                value="<?php echo esc_attr( $k ) ?>"
                <?php checked( is_array( $a->value ) && in_array( $k, $a->value ), true ) ?>
            >
            <span><?php echo esc_html( $v ) ?></span>
        </label>
    <?php } ?>
    </fieldset>
    <?php echo isset( $a->comment ) ? '<p><em>'.esc_html( $a->comment ).'</em></p>' : '' ?>
    <?php
}
function checkbox($a) {
    ?>
    <label>
        <input type="checkbox"
            name="<?php echo esc_attr( $a->name ) ?>"
            id="<?php echo esc_attr( $a->id ?? $a->name ) ?>"
            value="<?php echo esc_attr( $a->option ) ?>"
            class="<?php echo esc_attr( $a->className ?? '' ) ?>"
            <?php checked( $a->option, $a->value ) ?>
        >
        <span><?php echo esc_html( $a->label ) ?></span>
    </label>
    <?php echo isset( $a->comment ) ? '<p><em>'.esc_html( $a->comment ).'</em></p>' : '' ?>
    <?php
}
function radio($a) { // make like others or add the exception
    static $checked_once = false;
    $checked_once = $checked_once ?: $a->checked === $a->value;
    ?>
    <input type="radio"
        name="<?php echo esc_attr( $a->name ) ?>"
        value="<?php echo esc_attr( $a->value ) ?>"
        <?php checked( $a->checked === $a->value || ($a->default ?? false) && !$checked_once, true ) ?>
    >
    <?php echo isset( $a->comment ) ? '<p><em>'.esc_html( $a->comment ).'</em></p>' : '' ?>
    <?php
}

function image($a) {
    ?>
    <input type="hidden"
        name="<?php echo esc_attr( $a->name ) ?>"
        id="<?php echo esc_attr( $a->id ?? $a->name ) ?>"
        value="<?php echo esc_attr( $a->value ?? '' ) ?>"
    />
    <button type="button"
        id="<?php echo esc_attr( $a->id ?? $a->name ).'-pick' ?>"
        class="<?php echo esc_attr( $a->className ?? '' ) ?>"
    >
        <?php echo ( isset( $a->value ) && is_numeric( $a->value ) ) ? ( wp_get_attachment_image( $a->value, 'thumbnail' ) ?: __('No') ) : __('No') ?>
    </button>
    <?php echo isset( $a->comment ) ? '<p><em>'.esc_html( $a->comment ).'</em></p>' : '' ?>
    <?php
}

function sanitize_settings( $options ){

	foreach( $options as $name => & $val ){
		if( $name == 'input' )
			$val = strip_tags( $val );

		if( $name == 'checkbox' )
			$val = intval( $val );
	}

	return $options;
}

// ++sanitize admin values
    // default values to avoid double black or so
    // turn the fields to the arrays structure
    // data-default for (x)
// ++sanitize before printing
// ++polish for publishing
    // excape everything before printing
    // fix && prepare the texts
// add to both websites
// ++add a global function to print?
// ++option to print automatically?
    // ++is it allowed to make the gutenberg block??
// ++more styles
// ++maybe an option with schema?
// ++some hints how it will work
/* admin settings
    only admin checkbox (or anyone, who can edit the post)
    get the first image if no featured
    mode for preview only
*/
// ++ drag and drop to change the order of particular posts
// ++ preview using 1-tile layout && api
// override global with shortcode attributes and all with local on-page meta settings
    // ++make multiple in terms of css