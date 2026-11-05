// Keeps the live dashboard current on a screen at the desk: fetches the page every few seconds, pops up
// new check-ins and throws confetti when a shift is complete (needs covered, everyone checked in).
// The page itself only changes after the mouse and keyboard were left alone for a while, so it
// doesn't move under someone reading or clicking it.
(function () {
    var POLL = 15000;
    var IDLE = 120000;
    var MAX_TOASTS = 4;

    var root = document.getElementById('live');
    if (!root || !window.fetch || !window.DOMParser) {
        return;
    }

    var live = read(root);
    var seen = live.latest;
    var complete = keys(live.complete);
    var lastActivity = 0;
    var pending = null;
    var offline = false;

    var toasts = document.createElement('div');
    toasts.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    toasts.setAttribute('aria-live', 'polite');
    document.body.appendChild(toasts);

    ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'scroll', 'focusin'].forEach(function (name) {
        window.addEventListener(name, function () {
            lastActivity = Date.now();
            status();
        }, {passive: true});
    });

    function read(element) {
        try {
            return JSON.parse(element.dataset.live);
        } catch (e) {
            return {latest: 0, checkins: [], complete: []};
        }
    }

    function keys(list) {
        var set = {};
        list.forEach(function (shift) {
            set[shift.key] = shift.name;
        });
        return set;
    }

    function idle() {
        return Date.now() - lastActivity >= IDLE;
    }

    function status() {
        var element = document.getElementById('live-status');
        if (!element) {
            return;
        }
        element.textContent = offline
            ? 'Can\'t reach the server, retrying'
            : 'Updated ' + element.dataset.time + (!idle() && pending ? ' · paused while you use the page' : '');
    }

    function toast(title, detail, kind) {
        var element = document.createElement('div');
        element.className = 'toast show bg-body' + (kind ? ' border-' + kind : '');
        element.setAttribute('role', 'status');
        var body = document.createElement('div');
        body.className = 'toast-body';
        var strong = document.createElement('strong');
        strong.textContent = title;
        body.appendChild(strong);
        if (detail) {
            var small = document.createElement('div');
            small.className = 'small text-body-secondary';
            small.textContent = detail;
            body.appendChild(small);
        }
        element.appendChild(body);
        toasts.appendChild(element);
        while (toasts.children.length > 6) {
            toasts.removeChild(toasts.firstChild);
        }
        setTimeout(function () {
            element.remove();
        }, kind === 'success' ? 9000 : 6000);
    }

    function confetti() {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        var canvas = document.createElement('canvas');
        canvas.style.cssText = 'position: fixed; inset: 0; z-index: 1070; pointer-events: none;';
        document.body.appendChild(canvas);
        var context = canvas.getContext('2d');
        var ratio = window.devicePixelRatio || 1;
        var width = canvas.width = window.innerWidth * ratio;
        var height = canvas.height = window.innerHeight * ratio;
        var colors = ['#ffc107', '#198754', '#0dcaf0', '#dc3545', '#0d6efd', '#6f42c1'];
        var pieces = [];
        for (var i = 0; i < 180; i++) {
            var fromLeft = i % 2 === 0;
            pieces.push({
                x: fromLeft ? 0 : width,
                y: height * (0.55 + Math.random() * 0.3),
                vx: (fromLeft ? 1 : -1) * (6 + Math.random() * 12) * ratio,
                vy: -(14 + Math.random() * 14) * ratio,
                size: (5 + Math.random() * 6) * ratio,
                spin: Math.random() * Math.PI,
                turn: (Math.random() - 0.5) * 0.4,
                color: colors[i % colors.length]
            });
        }
        var started = null;
        function frame(time) {
            started = started || time;
            var age = time - started;
            context.clearRect(0, 0, width, height);
            pieces.forEach(function (piece) {
                piece.vy += 0.45 * ratio;
                piece.vx *= 0.985;
                piece.x += piece.vx;
                piece.y += piece.vy;
                piece.spin += piece.turn;
                context.save();
                context.globalAlpha = Math.max(0, 1 - age / 4200);
                context.translate(piece.x, piece.y);
                context.rotate(piece.spin);
                context.fillStyle = piece.color;
                context.fillRect(-piece.size / 2, -piece.size / 4, piece.size, piece.size / 2);
                context.restore();
            });
            if (age < 4200) {
                requestAnimationFrame(frame);
            } else {
                canvas.remove();
            }
        }
        requestAnimationFrame(frame);
    }

    // Pops up what's new since the last look; the first look on load is the starting point
    function announce(next) {
        var fresh = next.checkins.filter(function (checkin) {
            return checkin.id > seen;
        });
        var shown = fresh.slice(-MAX_TOASTS);
        if (fresh.length > shown.length) {
            toast((fresh.length - shown.length) + ' more checked in', '', '');
        }
        shown.forEach(function (checkin) {
            toast(checkin.name + ' checked in', checkin.shift + (checkin.by_hand ? ' · by hand' : ''), '');
        });
        seen = Math.max(seen, next.latest);

        var nextComplete = keys(next.complete);
        var celebrate = Object.keys(nextComplete).filter(function (key) {
            return !(key in complete);
        });
        celebrate.forEach(function (key) {
            toast(nextComplete[key] + ' is complete', 'Needs covered and everyone checked in', 'success');
        });
        if (celebrate.length) {
            confetti();
        }
        complete = nextComplete;
    }

    // Puts the fetched page in place, keeping open lists open
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
        pending = null;
        status();
    }

    function poll() {
        fetch(window.location.href, {credentials: 'same-origin', headers: {'Accept': 'text/html'}})
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(response.status);
                }
                return response.text();
            })
            .then(function (html) {
                var next = new DOMParser().parseFromString(html, 'text/html').getElementById('live');
                // Signed out or another page: keep what's shown
                if (!next) {
                    throw new Error('no live dashboard');
                }
                offline = false;
                announce(read(next));
                pending = next;
                if (idle()) {
                    swap(next);
                } else {
                    status();
                }
            })
            .catch(function () {
                offline = true;
                status();
            });
    }

    // Swaps in what's waiting as soon as the page is left alone, without waiting for the next poll
    setInterval(function () {
        if (pending && idle()) {
            swap(pending);
        }
    }, 5000);
    setInterval(poll, POLL);
})();
