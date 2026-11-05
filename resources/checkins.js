// Picking another day on the check-in and attendance pages shows that day
(function () {
    var select = document.getElementById('day-picker');
    if (!select) {
        return;
    }
    select.addEventListener('change', function () {
        select.form.submit();
    });
    var button = document.getElementById('day-picker-button');
    if (button) {
        button.classList.add('d-none');
    }
})();
