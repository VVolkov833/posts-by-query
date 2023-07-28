<?php

// user end printing

namespace FCP\PostsByQuery;
defined( 'ABSPATH' ) || exit;

add_shortcode( FCPPBK_SLUG, function() { // ++!! what if it is outside the loop!!

    if ( is_admin() ) { return; }
    if ( empty( get_types_to_search_among() ) ) { return; }

    $queried_id = get_queried_object_id();
    $post_type = get_post_type( $queried_id );

    if ( !in_array( $post_type, get_types_to_apply_to() ) ) { return; }

    if( !is_singular($post_type) ) { return; }

    $settings = get_settings();

    // styles
    // inline the global settings
    $handle = FCPPBK_PREF.'settings';
    wp_register_style( $handle, false );
    wp_enqueue_style( $handle );
    wp_add_inline_style( $handle, '.'.FCPPBK_SLUG.'{--main-color:'.$settings['main-color'].';--secondary-color:'.$settings['secondary-color'].'}' );

    // layout
    $path = 'css-layout/'.$settings['layout'].'.css';
    $handle = FCPPBK_PREF.'layout';

    if ( is_file( FCPPBK_DIR.$path ) ) {
        wp_enqueue_style( $handle, FCPPBK_URL.$path, [], ( FCPPBK_DEV ? FCPPBK_VER : FCPPBK_VER.'.'.filemtime( FCPPBK_DIR.$path ) ) );
    }

    // style
    $path = 'css-styling/'.$settings['style'].'.css';
    $handle = FCPPBK_PREF.'style';
    if ( is_file( FCPPBK_DIR.$path ) ) {
        wp_enqueue_style( $handle, FCPPBK_URL.$path, [], ( FCPPBK_DEV ? FCPPBK_VER : FCPPBK_VER.'.'.filemtime( FCPPBK_DIR.$path ) ) );
    }

    // inline the additional CSS
    $handle = FCPPBK_PREF.'additional';
    if ( $settings['additional-css'] ?? trim( $settings['additional-css'] ) ) {
        wp_register_style( $handle, false );
        wp_enqueue_style( $handle );
        wp_add_inline_style( $handle, css_minify( $settings['additional-css'] ) );
    }

    $metas = array_map( function( $value ) { // get the meta values
        return $value[0];
    }, array_filter( get_post_meta( $queried_id ), function($key) { // get only meta for the plugin
        return strpos( $key, FCPPBK_PREF ) === 0;
    }, ARRAY_FILTER_USE_KEY ) );

    $layouts = layout_variants( $settings['limit-the-list'] )[ $settings['layout'] ]['l']; // [columns, list with limit]
    $limit = array_reduce( $layouts, function( $result, $item ) { // count the limit to select by the query
        $result += $item;
        return $result;
    }, 0 );

    $wp_query_args = [
        'post_type' => get_types_to_search_among(),
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'post__not_in' => [ $queried_id ], // exclude self
        'lang' => 'all',
    ];

    $search_by = $metas[ FCPPBK_PREF.'variants' ] ?? 'list';
	$unfilled = false;

    switch ( $search_by ) {
        case ( 'list' ):
            $ids = unserialize( $metas[ FCPPBK_PREF.'posts' ] );
            if ( empty( $ids ) ) { $unfilled = true; break; }
            $wp_query_args += [ 'post__in' => $ids, 'orderby' => 'post__in' ];
        break;
        case ( 'query' ):
            $query = trim( $metas[ FCPPBK_PREF.'query' ] );
			if ( $query === '' ) { $unfilled = true; break; }
            $wp_query_args += [ 'orderby' => 'date', 'order' => 'DESC', 's' => $query ];
        break;
        default:
            $unfilled = true;
    }
	
    if ( $unfilled && $settings['unfilled-behavior'] !== 'search-by-title' ) { return; } // ++ improve the logic

	if ( $unfilled && $settings['unfilled-behavior'] === 'search-by-title' ) { // ++-- && is_single( $queried_id ) doesn't work somehow
		$query = trim( get_the_title( $queried_id ) );
		$wp_query_args += [ 'orderby' => 'date', 'order' => 'DESC', 's' => $query ];
	}

    $search = new \WP_Query( $wp_query_args );

    if ( !$search->have_posts() || !( $search->found_posts >= ( $settings['minimum-posts'] ?? 0 ?: 0 ) ) ) { return; }

    $get_template = function($name, $is_part = true) {
        static $cached = [];
        if ( isset( $cached[ $name ] ) ) { return $cached[ $name ]; }
        if ( ( $template = file_get_contents( FCPPBK_DIR.'templates/'.($is_part ? 'parts/' : '').$name.'.html' ) ) === false ) { return ''; }
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
        //$search->the_post(); // has the conflict with Glossary (Premium) plugin, which flushes the first post in a loop to the root one by the_excerpt()
        $p = $search->next_post();

        // prepare the values to fill in the template
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
        'css_class' =>
            FCPPBK_SLUG . ' '
            .FCPPBK_PREF.$settings['layout'] . ' '
            .FCPPBK_PREF.$settings['style'] . ' '
            .$settings['css-class'],
        'column' => '',
        'list' => '',
    ];
    $ind = 0;

    // fill in the template
    foreach ( $layouts as $k => $v ) {
        for ( $i = 0; $i < $v; $i++ ) {
            if ( !isset( $posts[ $ind ] ) ) { break 2; }
            $result[ $k ] .= $fill_template( $posts[ $ind ], $k );
            $ind++;
        }
    }

    return $fill_template( $result, $settings['layout'], false );

});