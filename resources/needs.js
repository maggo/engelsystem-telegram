// The needs table: changed numbers stand out, the totals follow, and the button says how many changed
(function () {
    var form = document.getElementById('needs-form');
    if (!form) {
        return;
    }
    var button = document.getElementById('needs-button');
    var inputs = form.querySelectorAll('input[type="number"][data-was]');
    var saving = false;

    var number = function (value) {
        var parsed = parseInt(value, 10);
        return isNaN(parsed) ? 0 : parsed;
    };

    var update = function () {
        var changed = 0;
        inputs.forEach(function (input) {
            var isChanged = input.value.trim() !== input.dataset.was;
            input.classList.toggle('border-warning', isChanged);
            input.classList.toggle('bg-warning-subtle', isChanged);
            changed += isChanged ? 1 : 0;
        });

        form.querySelectorAll('[data-total]').forEach(function (cell) {
            var day = cell.dataset.total;
            var sum = 0;
            form.querySelectorAll('td[data-day="' + day + '"]').forEach(function (td) {
                td.querySelectorAll('input[type="number"]').forEach(function (input) {
                    sum += number(input.value);
                });
                td.querySelectorAll('[data-value]').forEach(function (span) {
                    sum += number(span.dataset.value);
                });
            });
            cell.textContent = sum;
        });

        button.disabled = changed === 0;
        button.textContent = changed === 0 ? 'Nothing changed' : 'Save ' + changed + (changed === 1 ? ' change' : ' changes');
        return changed;
    };

    form.addEventListener('input', update);
    form.addEventListener('submit', function () {
        saving = true;
    });
    window.addEventListener('beforeunload', function (event) {
        if (!saving && update() > 0) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    update();
})();
