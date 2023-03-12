jQuery( document ).ready( $ => {
    const $textarea = $( '#fcppbk-settings--additional-css' );
    $textarea.attr( 'placeholder', `/* enter your custom CSS here */
.fcppbk {
    border: 1px dotted red;
}`
    );      

    wp.codeEditor.initialize( $textarea, cm_settings );
});
