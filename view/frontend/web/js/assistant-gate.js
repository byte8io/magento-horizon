/**
 * Byte8 Horizon — gated loader for the AI Shopping Assistant widget.
 *
 * Rendered instead of the plain widget script tag when "Load After Cookie"
 * is set in Stores → Configuration → Byte8 → Horizon → AI Shopping Assistant.
 * Waits until the named cookie exists (optionally with an exact value) and
 * only then injects the widget script, copying every data-* attribute from
 * this tag onto it. Re-checks on the configured window event and every
 * 1.5 seconds, so age-verification and consent gates need no theme changes.
 *
 * Attributes: data-src (widget script URL), data-gate-cookie,
 * data-gate-value (optional), data-gate-event (optional); all other data-*
 * attributes are passed through to the widget.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) {
        return;
    }

    var src = script.getAttribute('data-src') || '';
    var cookieName = script.getAttribute('data-gate-cookie') || '';
    var expected = script.getAttribute('data-gate-value') || '';
    var eventName = script.getAttribute('data-gate-event') || '';
    var loaded = false;

    if (!src) {
        return;
    }

    function cookieValue(name) {
        var parts = document.cookie ? document.cookie.split(';') : [];
        var i, part;

        for (i = 0; i < parts.length; i++) {
            part = parts[i].trim();
            if (part.indexOf(name + '=') === 0) {
                try {
                    return decodeURIComponent(part.slice(name.length + 1));
                } catch (e) {
                    return part.slice(name.length + 1);
                }
            }
        }

        return null;
    }

    function gateOpen() {
        var value;

        if (!cookieName) {
            return true;
        }
        value = cookieValue(cookieName);
        if (value === null) {
            return false;
        }

        return expected === '' ? value !== '' : value === expected;
    }

    function load() {
        var tag, i, attr;

        if (loaded || !gateOpen()) {
            return false;
        }
        loaded = true;

        tag = document.createElement('script');
        tag.src = src;
        tag.async = true;
        for (i = 0; i < script.attributes.length; i++) {
            attr = script.attributes[i];
            if (attr.name.indexOf('data-') === 0
                && attr.name !== 'data-src'
                && attr.name.indexOf('data-gate-') !== 0
            ) {
                tag.setAttribute(attr.name, attr.value);
            }
        }
        document.body.appendChild(tag);

        return true;
    }

    if (load()) {
        return;
    }
    if (eventName) {
        window.addEventListener(eventName, load);
    }
    var timer = window.setInterval(function () {
        if (load()) {
            window.clearInterval(timer);
        }
    }, 1500);
})();
