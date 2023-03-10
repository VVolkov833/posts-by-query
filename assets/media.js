jQuery( document ).ready( $ => {

    const $button = $( '#fcppbk-settings--default-thumbnail-pick' );
    const $storage = $( '#fcppbk-settings--default-thumbnail' );

    $button.on('click', () => {

        const uploader = wp.media( {

            title: 'Select the default thumbnail',
            button: {
                text: 'Use this image',
                type: 'image',
            },
            multiple: false

        }).on( 'select', () => {

            attachment = uploader.state().get( 'selection' ).first().toJSON();
            $button.html( '' ).append( `<img src="${ attachment.sizes?.thumbnail?.url || attachment.url }">` );
            $storage.val( attachment.id );

        }).open();
    });
});