$('.js-confirm').on('click', function (e) {
    if (!confirm('Are you sure?')) {
        e.preventDefault();
    }
});

$('#form_license_personal').on('change', function (e) {
    $('#form_license').attr('disabled', $('#form_license_personal').is(':checked') ? 'disabled' : null);
});
$('#form_license').on('keyup', function (e) {
    if ($(this).val() != '') {
        $('#form_license_personal').prop('checked', false);
    }
});
