<?php
/*
Plugin Name: FCP Posts by Search Query
Description: Searches and prints the posts tiles by query
Version: 0.0.5
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

define( 'FCPPBK_DEV', false );
define( 'FCPPBK_VER', get_file_data( __FILE__, [ 'ver' => 'Version' ] )[ 'ver' ] . ( FCPPBK_DEV ? time() : '' ) );

function get_search_post_types() { // ++replace with the option value
    return ['post', 'page', 'brustchirurgie', 'gesichtschirurgie', 'koerperchirurgie', 'haut' ];
}

// admin interface
add_action( 'add_meta_boxes', function() {
    if ( !current_user_can( 'administrator' ) ) { return; }
    list( 'public' => $public_post_types ) = get_all_post_types();
    add_meta_box(
        'fcp-posts-by-query',
        'Posts by Query',
        __NAMESPACE__.'\metabox_query',
        array_keys( $public_post_types ),
        'normal',
        'low'
    );
});
// style meta boxes && settings
add_action( 'admin_enqueue_scripts', function() {

    if ( !current_user_can( 'administrator' ) ) { return; }
    $files = [ 'post' => [ 'metabox', 'advisor' ], 'settings_page_posts-by-query' => [ 'settings' ] ];
    $screen = get_current_screen();
    if ( !isset( $screen ) || !is_object( $screen ) || !isset( $files[ $screen->base ] ) ) { return; }

    $assets_path = __DIR__.'/assets';
    foreach ( scandir( $assets_path ) as $v ) {
        if ( !is_file( $assets_path.'/'.$v ) ) { continue; }
        $onthelist = array_reduce( $files[ $screen->base ], function( $result, $item ) use ( $v ) {
            $result = $result ? $result : strpos( $v, $item.'.' ) === 0;
            return $result;
        });
        if ( !$onthelist ) { continue; }

        $ext = substr( $v, strrpos( $v, '.' )+1 );
        $handle = FCPPBK_PREF.preg_replace( ['/\.(?:js|css)$/', '/[^a-z0-9\-_]/'], '', $v );
        $url = plugin_dir_url(__FILE__).'assets/'.$v;

        if ( $ext === 'css' ) { wp_enqueue_style( $handle, $url, [], FCPPBK_VER, 'all' ); }
        if ( $ext === 'js' ) { wp_enqueue_script( $handle, $url, [], FCPPBK_VER, false ); }
    }

});

// api to fetch the posts
// api to fetch the query
add_action( 'rest_api_init', function () {

    $route_args = function($results_format) {

        $wp_query_args = [
            'post_type' => get_search_post_types(),
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
                $wp_query_args['post_type'] = ['post'];
                $wp_query_args += [ 'orderby' => 'date', 'order' => 'DESC' ];
                $format_output = function( $p ) {
                    $date = wp_date( get_option( 'date_format', 'j F Y' ), strtotime( $p->post_date ) );
                    return [ 'id' => $p->ID, 'title' => '('.$date.') ' . $p->post_title ];
                };
            break;
        }

        return [
            'methods'  => 'GET',
            'callback' => function( \WP_REST_Request $request ) use ( $wp_query_args, $format_output ) {

                $wp_query_args['s'] = $request['search'];

                $search = new \WP_Query( $wp_query_args );
    
                if ( !$search->have_posts() ) {
                    return new \WP_Error( 'nothing_found', 'No results found', [ 'status' => 404 ] );
                }
    
                $result = [];
                while ( $search->have_posts() ) {
                    $p = $search->next_post();
                    $result[] = $format_output( $p ); // not using the id as the key to keep the order in json
                }
    
                $result = new \WP_REST_Response( (object) $result, 200 );
    
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
            'checked' => get_post_meta( $post->ID, FCPPBK_PREF.'variants' )[0],
            'default' => true,
        ]);
        ?>
        <div>
            <p><strong>Posts by Search Query &amp; Date</strong></p>
            <?php
            text( (object) [
                'name' => FCPPBK_PREF.'query',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'query' )[0],
            ]);
            ?>
        </div>

        <?php
        radio( (object) [
            'name' => FCPPBK_PREF.'variants',
            'value' => 'list',
            'checked' => get_post_meta( $post->ID, FCPPBK_PREF.'variants' )[0],
        ]);
        ?>
        <div>
            <p><strong>Particular Posts</strong></p>
            <?php
            text( (object) [
                'name' => FCPPBK_PREF.'list',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'list' )[0],
            ]);
            ?>
        </div>
    </div>

    <div id="<?php echo esc_attr( FCPPBK_PREF ) ?>tiles">
        <?php
        $ids = get_post_meta( $post->ID, FCPPBK_PREF.'posts' )[0];
        if ( !empty( $ids ) ) {

            $search = new \WP_Query( [
                'post_type' => get_search_post_types(),
                'post_status' => 'publish',
                'post__in' => $ids,
                'orderby' => 'post__in',
            ] );
            if ( $search->have_posts() ) {
                $result = [];
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


function get_all_post_types() {
    static $all = [], $public = [], $archive = [];

    if ( !empty( $public ) ) { return [ 'all' => $all, 'public' => $public, 'archive' => $archive ]; }

    $all = get_post_types( [], 'objects' );
    $public = [];
    $archive = [];
    $archive[ 'blog' ] = 'Blog';
    usort( $all, function($a,$b) { return strcasecmp( $a->label, $b->label ); });
    foreach ( $all as $type ) {
        $type->name = isset( $type->rewrite->slug ) ? $type->rewrite->slug : $type->name;
        if ( $type->has_archive ) {
            $archive[ $type->name ] = $type->label;
        }
        if ( $type->public ) {
            $public[ $type->name ] = $type->label;
        }
    }

    return [ 'all' => $all, 'public' => $public, 'archive' => $archive ];

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

    return $value;

    $field = ( strpos( $field, FCPPBK_PREF ) === 0 ) ? substr( $field, strlen( FCPPBK_PREF ) ) : $field;

    switch ( $field ) {
        case ( 'variants' ):
            return in_array( $value, ['query', 'list'] ) ? $value : 'query';
        break;
        case ( 'query' ):
            return sanitize_text_field( $value );
        break;
        case ( 'posts' ):
            return array_values( array_filter( $value, 'is_numeric' ) ); // post-type & is-published filters are performed before printing on the front-end
        break;
    }

    return '';
}

add_shortcode( FCPPBK_SLUG, function($atts = []) {
    $allowed = [
        'layout' => 'default',
        'styles' => 'style-1',
        'headline' => 'Das könnte Sie auch interessieren', //++replace with a wrapper
    ];
    $atts = shortcode_atts( $allowed, $atts );
    
    $metas = array_map( function( $value ) {
        return $value[0];
    }, array_filter( get_post_custom(), function($key) {
        return strpos( $key, FCPPBK_PREF ) === 0;
    }, ARRAY_FILTER_USE_KEY ) );


    $wp_query_args = [
        'post_type' => get_search_post_types(),
        'post_status' => 'publish',
        'posts_per_page' => 3,
    ];

    $results_format = $metas[ FCPPBK_PREF.'variants' ];
    switch ( $results_format ) {
        case ( 'list' ):
            $ids = unserialize( $metas[ FCPPBK_PREF.'posts' ] ); // ++ filter by post-type & is-published
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

    $template = function($name) {
        static $cached = [];
        if ( isset( $cached[ $name ] ) ) { return $cached[ $name ]; }
        $template = file_get_contents( __DIR__.'/templates/'.$name.'.html' );
        if ( $template === false ) { return; }
        $cached[ $name ] = $template;
        return $cached[ $name ];
    };
    $format = function($params, $template_name) use ($template) { // ++refactor
        return strtr( $template( $template_name ), array_reduce( array_keys( $params ), function( $result, $item ) use ( $params ) {
            $result[ '%'.$item ] = $params[ $item ];
            return $result;
        }, [] ) );
    };
    $params = [];
    $param_add = function( $key, $fill = true ) use ( $format, &$params ) { //++$key, $fill (condition, which gotta be true and fill empty if is false)
        $add = function($key) use ($format, &$params, $fill) { $params[ $key ] = $fill ? $format( $params, $key ) : ''; };
        if ( is_array( $key ) ) {
            foreach( $key as $v ) { $add( $v ); }
            return;
        }
        $add( $key );
    };
    $crop_excerpt = function($text, $length) {
        if ( !$text || !is_numeric( $settings['excerpt-length'] ) ) { return $text; }
        $text = substr( get_the_excerpt( $p ), 0, $length );
        $text = rtrim( substr( $text, 0, strrpos( $text, ' ' ) ), ',.…!?&([{-_ "„“' ) . '…';
        return $text;
    };

    $settings = get_option( FCPPBK_PREF.'settings' );

    $result = [];
    while ( $search->have_posts() ) {
        //$search->the_post(); // has the conflict with Glossary (Premium) plugin, which flushes the first post in a loop to the root one with the_excerpt()
        $p = $search->next_post();

        $categories = $settings['hide-category'] ? '' : get_the_category( $p );
//++ add filters like esc_url and esc_html
        $params = [
            'id' => get_the_ID( $p ),
            'permalink' => get_permalink( $p ),
            'title' => get_the_title( $p ),
            'date' => $settings['hide-date'] ? '' : get_the_date( '', $p ),
            'excerpt' => $settings['hide-excerpt'] ? '' : get_the_excerpt( $p ),
            'category' => empty( $categories ) ? '' : $categories[0]->name,
            'category_link' => empty( $categories ) ? '' : get_category_link( $categories[0]->term_id ),
            'thumbnail' => $settings['thumbnail-size'] ? get_the_post_thumbnail( $p, $settings['thumbnail-size'] ) : '',
            'readmore' => __( $settings['read-more-text'] ? $settings['read-more-text'] : 'Read more' ),
        ];
        $param_add( 'title_linked' );
        $param_add( 'date', $params['date'] );
        $params['excerpt'] = $crop_excerpt( $params['excerpt'], $settings['excerpt-length'] );
        $param_add( 'excerpt', $params['excerpt'] );
        $param_add( 'category_linked', $params['category'] && $params['category_link'] );
        $param_add( 'thumbnail_linked', $params['thumbnail'] );
        $param_add( 'button', !$settings['hide-read-more'] );

        ksort( $params, SORT_STRING ); // ++ end with % instead of probability??
        $result[] = $format( $params, 'column' );
        //echo '<pre>';
        //print_r( [ $settings, $params ] ); exit;

    }

    //wp_reset_postdata();

//print_r( $result ); exit;

    wp_enqueue_style(
        'fcp-posts-by-query',
        plugins_url( '/' ,__FILE__ ) . 'styles/style-1.css',
        [],
        FCPPBK_DEV ? FCPPBK_VER : FCPPBK_VER.'.'.filemtime( __DIR__.'/styles/style-1.css' ),
    );

    return '<section class="'.FCPPBK_SLUG.' container"><h2>'.$atts['headline'].'</h2><div>' . implode( '', $result) . '</div></section>';
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

        $types = [ 'text', 'radio', 'checkbox', 'checkboxes', 'select', 'color', 'number', 'comment' ];
        $type = ( empty( $type ) || !in_array( $type, $types ) ) ? $types[0] : $type;
        $function = __NAMESPACE__.'\\'.$type;
        if ( !function_exists( $function ) ) { return; }
        $slug = empty( $atts['slug'] ) ? sanitize_title( $title ) : $atts['slug'];

        $attributes = (object) [
            'name' => $settings->varname.'['.$slug.']',
            'id' => $settings->varname . '--' . $slug,
            'value' => $settings->values[ $slug ],
            'placeholder' => empty( $atts['placeholder'] ) ? '' : $atts['placeholder'], // ++unify with the rest
            'options' => empty( $atts['options'] ) ? '' : $atts['options'],
            'option' => empty( $atts['option'] ) ? '' : $atts['option'],
            'label' => empty( $atts['label'] ) ? '' : $atts['label'],
            'comment' => empty( $atts['comment'] ) ? '' : $atts['comment'],
        ];

        add_settings_field(
            $slug,
            $title,
            function() use ( $attributes, $function ) {
                call_user_func( $function, $attributes );
            },
            $settings->page,
            $settings->section
        );
    };

    $layout_options = [ '2 columns', '3 columns', 'List', '2 columns + 1 list' ];
    $layout_options = array_reduce( $layout_options, function( $result, $item ) {
        $result[ sanitize_title( $item ) ] = $item;
        return $result;
    }, [] );

    $thumbnail_sizes = wp_get_registered_image_subsizes(); //++full, ++no image
    $thumbnail_sizes = [ '' => 'No image', 'full' => 'Full' ] + array_reduce( array_keys( $thumbnail_sizes ), function( $result, $item ) use ( $thumbnail_sizes ) {
        $result[ $item ] = ucfirst( $item ) . ' ('.$thumbnail_sizes[$item]['width'].'x'.$thumbnail_sizes[$item]['height'].')';
        return $result;
    }, [] );

    list( 'public' => $public_post_types ) = get_all_post_types();

    // structure of fields
    $settings->section = 'description';
	add_settings_section( $settings->section, 'Description', '', $settings->page );
        $add_settings_field( '', 'comment', [ 'comment' => '<p>Add the posts section with the following shortcode <code>['.FCPPBK_SLUG.']</code></p>' ] );

    $settings->section = 'styling-settings';
	add_settings_section( $settings->section, 'Styling settings', '', $settings->page );
        $add_settings_field( 'Main color', 'color' ); // ++use wp default picker
        $add_settings_field( 'Secondary color', 'color' );
        $add_settings_field( 'Layout', 'select', [ 'options' => $layout_options ] );
        $add_settings_field( 'Limit the list', 'number', [ 'placeholder' => '10', 'step' => 1, 'comment' => 'If the Layout contains the List, this number will limit the amount of posts in it' ] ); // ++ make the comment work
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
        $add_settings_field( 'Select from', 'checkboxes', [ 'options' => $public_post_types ] );
        $add_settings_field( 'Apply to', 'checkboxes', [ 'options' => $public_post_types, 'comment' => 'This will add the option to query the posts to selected post types editor bottom' ] );
        $add_settings_field( 'Defer style', 'checkbox', [ 'option' => '1', 'label' => 'defer the render blocking style.css', 'comment' => 'If you use a caching plugin, most probably it fulfulls the role of this checkbox' ] );

    register_setting( FCPPBK_PREF.'settings-group1', $settings->varname, __NAMESPACE__.'\sanitize_settings' ); // register, save, nonce
});

function text($a, $type = '') {
    ?>
    <input type="<?php echo in_array( $type, ['color', 'number'] ) ? $type : 'text' ?>"
        name="<?php echo esc_attr( $a->name ) ?>"
        id="<?php echo esc_attr( isset( $a->id ) ? $a->id : $a->name ) ?>"
        placeholder="<?php echo isset( $a->placeholder ) ? esc_attr( $a->placeholder )  : '' ?>"
        value="<?php echo isset( $a->value ) ? esc_attr( $a->value ) : '' ?>"
        class="<?php echo isset( $a->className ) ? esc_attr( $a->className ) : '' ?>"
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

function select($a) {
    ?>
    <select
        name="<?php echo esc_attr( $a->name ) ?>"
        id="<?php echo esc_attr( isset( $a->id ) ? $a->id : $a->name ) ?>"
        class="<?php echo isset( $a->className ) ? esc_attr( $a->className ) : '' ?>"
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
        id="<?php echo esc_attr( isset( $a->id ) ? $a->id : $a->name ) ?>"
        class="<?php echo isset( $a->className ) ? esc_attr( $a->className ) : '' ?>"
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
            id="<?php echo esc_attr( isset( $a->id ) ? $a->id : $a->name ) ?>"
            value="<?php echo esc_attr( $a->option ) ?>"
            class="<?php echo isset( $a->className ) ? esc_attr( $a->className ) : '' ?>"
            <?php checked( $a->option, $a->value ) ?>
        >
        <span><?php echo esc_html( $a->label ) ?></span>
    </label>
    <?php echo isset( $a->comment ) ? '<p><em>'.esc_html( $a->comment ).'</em></p>' : '' ?>
    <?php
}
function radio($a) { // make like others or add the exception
    static $checked = false;
    $checked = $checked ? true : $a->checked === $a->value;
    ?>
    <input type="radio"
        name="<?php echo esc_attr( $a->name ) ?>"
        value="<?php echo esc_attr( $a->value ) ?>"
        <?php echo esc_attr( ( $a->checked === $a->value || $a->default && !$checked ) ? 'checked' : '' ) ?>
    >
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


// ++!!! the post must not be itself !!!
// make the layouts for both websites && apply to lanuwa?
// ++default values on install?
// ++sanitize admin values
// ++sanitize before printing
// ++polish for publishing
    // excape everything before printing
// ++add a global function to print?
// ++option to print automatically?
    // ++is it allowed to make the gutenberg block??
// ++check the plugins on reis - what minifies the jss?
// ++array_unique before saving.. just so is
// ++2 more styles? check the todo
// ++maybe an option with schema?
// ++abort previous fetch if new one is here
// ++some hints how it will work
/* admin settings
    only admin checkbox (or anyone, who can edit the post)
    get the first image if no featured
    tiles per row?
*/
// ++ drag and drop to change the order of particular posts
// ++ use wp checked()