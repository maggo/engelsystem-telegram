// The standby page: a new day or half day shows right away, a Move button needs a shift picked,
// each move needs a second click to confirm (the bot tells people right away), and the search
// hides who doesn't match
(function () {
    var pick = document.getElementById('standby-pick');
    if (pick) {
        pick.addEventListener('change', function (event) {
            if (event.target.id !== 'standby-search') {
                pick.submit();
            }
        });
        // Enter in the search box would reload the page
        pick.addEventListener('submit', function (event) {
            if (document.activeElement && document.activeElement.id === 'standby-search') {
                event.preventDefault();
            }
        });
    }

    var form = document.getElementById('standby-form');
    if (!form) {
        return;
    }

    // A move asks on the page, not with a browser dialog (some browsers block those and answer
    // "cancel" right away): the first click turns the button into "Confirm?", a second one moves
    var armed = null;
    var disarm = function () {
        if (armed) {
            armed.textContent = armed.dataset.label;
            armed.className = armed.dataset.classes;
            armed.removeAttribute('title');
            clearTimeout(armed.timer);
            armed = null;
        }
    };
    var arm = function (button) {
        disarm();
        var select = form.querySelector('select[name="to[' + button.value + ']"]');
        var to = button.dataset.to || (select && select.options[select.selectedIndex].text);
        button.dataset.label = button.textContent;
        button.dataset.classes = button.className;
        button.textContent = 'Confirm?';
        button.className = button.className.replace(/\bbtn-(outline-)?primary\b/, 'btn-danger');
        button.title = 'Move ' + button.dataset.name + ' to ' + to + '. The bot tells them, the leads and coordination.';
        button.timer = setTimeout(disarm, 5000);
        armed = button;
    };

    // Engelsystem turns every select into a Choices.js box, which wraps it
    var buttonOf = function (select) {
        return select.closest('[data-move]').querySelector('button[name="entry"]');
    };
    Array.prototype.forEach.call(form.querySelectorAll('select[name^="to["]'), function (select) {
        buttonOf(select).disabled = select.value === '';
        select.addEventListener('change', function () {
            if (armed === buttonOf(select)) {
                disarm();
            }
            buttonOf(select).disabled = select.value === '';
        });
    });

    // Arming happens on the click, before any submit: Engelsystem greys out every submit button on
    // a form's submit event, even one that is cancelled, which would block the second click
    form.addEventListener('click', function (event) {
        var button = event.target.closest('button[name="entry"]');
        if (button && armed !== button) {
            event.preventDefault();
            arm(button);
        }
    });

    form.addEventListener('submit', function (event) {
        var button = event.submitter;
        if (!button || button.name !== 'entry' || armed !== button) {
            event.preventDefault();
            return;
        }
        clearTimeout(button.timer);
        button.textContent = 'Moving…';
        // One click, one move
        setTimeout(function () {
            Array.prototype.forEach.call(form.querySelectorAll('button'), function (other) {
                other.disabled = true;
            });
        });
    });

    var search = document.getElementById('standby-search');
    if (search) {
        search.addEventListener('input', function () {
            var query = search.value.trim().toLowerCase().replace(/^@/, '');
            Array.prototype.forEach.call(form.querySelectorAll('[data-person]'), function (item) {
                item.classList.toggle('d-none', query !== '' && item.textContent.toLowerCase().indexOf(query) === -1);
            });
        });
    }
})();
