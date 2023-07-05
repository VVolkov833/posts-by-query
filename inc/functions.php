<?php

// list of used functinos

namespace FCP\PostsByQuery;
defined( 'ABSPATH' ) || exit;


function get_public_post_types() {
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
function filter_by_public_types($types = []) { // list of types is among the list of public ones
    return array_intersect( array_keys( get_public_post_types() ), $types ?: [] );
}

function get_settings() {
    static $settings = [];
    if ( !empty( $settings ) ) { return $settings; }
    $settings = get_option( FCPPBK_SETT );
    return $settings;
}

function get_types_to_apply_to() {
    return filter_by_public_types( get_settings()['apply-to'] ?? [] );
}
function get_types_to_search_among() {
    return filter_by_public_types( get_settings()['select-from'] ?? [] );
}

function css_minify($css) {
    $preg_replace = function($regexp, $replace, $string) { // avoid null result so that css still works even though not fully minified
        return preg_replace( $regexp, $replace, $string ) ?: $string . '/* --- failed '.$regexp.', '.$replace.' */';
    };
    $css = $preg_replace( '/\s+/', ' ', $css ); // one-line & only single speces
    $css = $preg_replace( '/ ?\/\*(?:.*?)\*\/ ?/', '', $css ); // remove comments
    $css = $preg_replace( '/ ?([\{\};:\>\~\+]) ?/', '$1', $css ); // remove spaces
    $css = $preg_replace( '/\+(\d)/', ' + $1', $css ); // restore spaces in functions
    $css = $preg_replace( '/(?:[^\}]*)\{\}/', '', $css ); // remove empty properties
    $css = str_replace( [';}', '( ', ' )'], ['}', '(', ')'], $css ); // remove last ; and spaces
    // ++ should also remove 0 from 0.5, but not from svg-s?
    // ++ try replacing ', ' with ','
    // ++ remove space between %3E %3C and before %3E and /%3E
    return trim( $css );
};