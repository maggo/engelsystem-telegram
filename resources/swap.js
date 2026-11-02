// The swap page: a new day, half day or shift shows right away, and the button says what it does
(function () {
    var pick = document.getElementById('swap-pick');
    if (pick) {
        pick.addEventListener('change', function () {
            pick.submit();
        });
    }

    var form = document.getElementById('swap-form');
    if (!form) {
        return;
    }
    var button = document.getElementById('swap-button');
    var after = document.getElementById('swap-after');

    var ticked = function (side) {
        return form.querySelectorAll('input[name="from_' + side + '[]"]:checked').length;
    };

    var name = function (side) {
        return form.querySelector('[data-side="' + side + '"]').dataset.name;
    };

    var update = function () {
        var left = ticked('left');
        var right = ticked('right');
        button.disabled = left + right === 0;
        button.textContent = left && right ? 'Swap ' + left + ' ↔ ' + right
            : left ? 'Move ' + left + ' to ' + name('right')
            : right ? 'Move ' + right + ' to ' + name('left')
            : 'Tick people to move';

        var change = { left: right - left, right: left - right };
        after.textContent = left + right === 0 ? '' : 'After: ' + Array.prototype.map.call(
            form.querySelectorAll('[data-side]'),
            function (column) {
                var count = Number(column.dataset.filled) + change[column.dataset.side];
                return column.dataset.name + ' ' + count + (column.dataset.needed ? '/' + column.dataset.needed : '');
            }
        ).join(' · ');
    };

    // The free volunteers can be many: filter them by name, ticked ones stay ticked
    Array.prototype.forEach.call(form.querySelectorAll('[data-filter]'), function (search) {
        search.addEventListener('input', function () {
            var query = search.value.trim().toLowerCase();
            var list = form.querySelector('[data-list="' + search.dataset.filter + '"]');
            Array.prototype.forEach.call(list.children, function (item) {
                item.classList.toggle('d-none', query !== '' && item.textContent.toLowerCase().indexOf(query) === -1);
            });
        });
        // Enter in the search box would submit the move
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
    });

    form.addEventListener('change', update);
    form.addEventListener('submit', function () {
        // One click, one move
        button.disabled = true;
    });
    update();
})();
