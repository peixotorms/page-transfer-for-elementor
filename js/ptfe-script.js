jQuery(document).ready(function($) {
    // Open modal on import link click
    $('body').on('click', '.ptfe-import-link', function(e) {
        e.preventDefault();
        var postId = $(this).data('post-id');
        $('#ptfe-import-modal').data('post-id', postId).show();
    });

    // Close modal
    $('#ptfe-import-modal .close').on('click', function() {
        resetModal();
        $('#ptfe-import-modal').hide();
    });

    // Handle form submission
    $('#ptfe-import-form').on('submit', function(e) {
        e.preventDefault();

        var form = $(this);
        var fileInput = form.find('input[name="ptfe_import_file"]');
        var file = fileInput[0].files[0];

        // Check if the selected file is a .json file
        if (file && file.type !== 'application/json') {
            form.find('.ptfe-file-feedback').text('Please select a .json file.').css('color', 'red').css('display', 'block');
            return;
        }

        var formData = new FormData(this);
        formData.append('action', 'ptfe_import_page');
        formData.append('security', ptfe_ajax.nonce);
        formData.append('post_id', $('#ptfe-import-modal').data('post-id'));

        $.ajax({
            url: ptfe_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    form.find('.ptfe-file-feedback').text(response.data).css('color', 'green').css('display', 'block');
                    setTimeout(function() {
                        resetModal();
                        $('#ptfe-import-modal').hide();
                    }, 2000);
                } else {
                    form.find('.ptfe-file-feedback').text(response.data).css('color', 'red').css('display', 'block');
                }
            },
            error: function(xhr, status, error) {
                form.find('.ptfe-file-feedback').text('An error occurred: ' + error).css('color', 'red').css('display', 'block');
            }
        });
    });

    // Reset modal form and feedback
    function resetModal() {
        var form = $('#ptfe-import-form');
        form[0].reset();
        form.find('.ptfe-file-feedback').text('').css('display', 'none');
    }
});
