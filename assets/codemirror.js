jQuery( document ).ready( $ => {
    const $ed = $( '#fcppbk-settings--additional-css' );
    $ed.attr( 'placeholder', `/* enter your custom CSS here */
.fcppbk {
    border: 1px dotted red;
}`
    );
    wp.codeEditor.initialize( $ed, cm_settings );
});
