// Picking another day shows its shifts
(function () {
    var select = document.getElementById('checkins-day');
    if (!select) {
        return;
    }
    select.addEventListener('change', function () {
        select.form.submit();
    });
    var button = document.getElementById('checkins-day-button');
    if (button) {
        button.classList.add('d-none');
    }
})();
