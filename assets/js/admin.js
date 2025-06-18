jQuery(document).ready(function ($) {
    $('#wps_fetch_specs').on('click', function () {
        const phoneName = $('#wps_phone_name').val();
        const resultDiv = $('#wps_specs_result');
        const button = $(this);

        if (!phoneName) {
            alert('Please enter a phone name.');
            return;
        }

        resultDiv.html('<span class="spinner is-active"></span> Fetching...');
        button.prop('disabled', true);

        $.ajax({
            url: wps_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wps_get_phone_specs',
                nonce: wps_ajax.nonce,
                phone_name: phoneName,
            },
            success: function (response) {
                if (response.success) {
                    const specs = response.data;
                    $('#wps_specs_json').val(JSON.stringify(specs));
                    
                    let html = '<h4>' + specs.phone_name + '</h4>';
                    html += '<p><strong>Important:</strong> Review the specs below. When you click "Update" or "Publish", these will be added as product attributes.</p>';
                    html += '<table class="widefat striped">';
                    
                    specs.specifications.forEach(group => {
                        html += '<thead><tr><th colspan="2">' + group.title + '</th></tr></thead>';
                        html += '<tbody>';
                        group.specs.forEach(spec => {
                            html += '<tr>';
                            html += '<td style="width: 30%;"><strong>' + spec.key + '</strong></td>';
                            html += '<td>' + spec.val.join(', ') + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody>';
                    });

                    html += '</table>';
                    resultDiv.html(html);
                } else {
                    resultDiv.html('<p style="color:red;">Error: ' + response.data.message + '</p>');
                }
                button.prop('disabled', false);
            },
            error: function () {
                resultDiv.html('<p style="color:red;">An unexpected error occurred. Check your browser\'s console for details.</p>');
                button.prop('disabled', false);
            }
        });
    });
});