<?php

// initial settings

namespace FCP\PostsByQuery;
defined( 'ABSPATH' ) || exit;


define( 'FCPPBK_SLUG', 'fcppbk' );
define( 'FCPPBK_PREF', FCPPBK_SLUG.'-' );

define( 'FCPPBK_SETT', FCPPBK_PREF.'settings' );


function layout_variants($list_length = 0) { // ++ maybe switch to const
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

function get_styling_variants() { // ++add by scanning the folder
    return [
        '' => 'None',
        'style-1' => 'Style 1',
        'style-2' => 'Style 2',
    ];
}

function get_default_values() { // apply on install && only planned to be usef in fields, which must not be empty // ++ turn to the constant ++!! set up them all by the structure
    return [
        'main-color' => '#007cba',
        'secondary-color' => '#abb8c3',
        'layout' => '3-columns',
        'style' => 'style-2',
        'thumbnail-size' => 'medium',
        'excerpt-length' => '200',
        'select-from' => [ 'post' ],
        'apply-to' => [ 'page', 'post' ],
    ];
}

function settings_get() {
    return (object) [
        'varname' => FCPPBK_SETT,
        'group' => FCPPBK_SETT.'-group',
        'page' => FCPPBK_SETT.'-page',
        'section' => '',
        'values' => get_option( FCPPBK_SETT ),
    ];
}