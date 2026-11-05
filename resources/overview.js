// Keeps the shift overview current on a screen: fetches the page every 30 seconds and puts it in
// place, keeping opened shifts open; waits while someone uses the page
(function () {
    var POLL = 30000;
    var IDLE = 60000;

    var root = document.getElementById('overview');
    if (!root || !window.fetch || !window.DOMParser) {
        return;
    }
    var lastActivity = 0;
    var failed = false;

    ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'focusin'].forEach(function (name) {
        window.addEventListener(name, function () {
            lastActivity = Date.now();
        }, {passive: true});
    });

    function status() {
        var element = document.getElementById('overview-status');
        if (element) {
            element.textContent = failed ? 'Can\'t reach the server, retrying' : 'Updated ' + element.dataset.time;
        }
    }

    function swap(next) {
        var open = {};
        root.querySelectorAll('details[data-key]').forEach(function (details) {
            open[details.dataset.key] = details.open;
        });
        next.querySelectorAll('details[data-key]').forEach(function (details) {
            if (details.dataset.key in open) {
                details.open = open[details.dataset.key];
            }
        });
        root.replaceWith(next);
        root = next;
    }

    function poll() {
        if (Date.now() - lastActivity < IDLE) {
            return;
        }
        fetch(window.location.href, {credentials: 'same-origin', headers: {'Accept': 'text/html'}})
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(response.status);
                }
                return response.text();
            })
            .then(function (html) {
                var next = new DOMParser().parseFromString(html, 'text/html').getElementById('overview');
                // Signed out or another page: keep what's shown
                if (!next) {
                    throw new Error('no overview');
                }
                failed = false;
                swap(next);
                status();
            })
            .catch(function () {
                failed = true;
                status();
            });
    }

    // Folded half days stay folded on this screen, also after a reload; only a convenience
    var FOLDED = 'telegram-overview-folded';
    function folded() {
        try {
            return JSON.parse(window.localStorage.getItem(FOLDED)) || {};
        } catch (e) {
            return {};
        }
    }
    function applyFolded(element) {
        var state = folded();
        element.querySelectorAll('details.slot[data-key]').forEach(function (details) {
            if (details.dataset.key in state) {
                details.open = !state[details.dataset.key];
            }
        });
    }
    document.addEventListener('toggle', function (event) {
        var details = event.target;
        if (!details.matches || !details.matches('details.slot[data-key]')) {
            return;
        }
        var state = folded();
        state[details.dataset.key] = !details.open;
        try {
            window.localStorage.setItem(FOLDED, JSON.stringify(state));
        } catch (e) {
            // Storage blocked: folding still works until the page reloads
        }
    }, true);
    applyFolded(root);

    setInterval(poll, POLL);
})();
