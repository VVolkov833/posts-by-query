<?php

// form printing functions

namespace FCP\PostsByQuery;
defined( 'ABSPATH' ) || exit;

// fields
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
    echo wp_kses( $a->comment, [
        'p' => [
            'class' => true,
            'style' => true,
        ],
        'code' => [],
        'br' => [],
    ]);
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