/* Shows that a run is under way and stops a second one being started on top of it. Checks are
   executed in the request the form submits — there is no background job to report on — so
   without this the page simply sits there and a reader cannot tell whether anything happened. */
(function() {
    'use strict';

    document.addEventListener('submit', function(event) {
        var form = event.target;

        if (!form || typeof form.hasAttribute !== 'function' || !form.hasAttribute('data-wd-run-form')) {
            return;
        }

        if (form.hasAttribute('data-wd-running')) {
            event.preventDefault();
            return;
        }

        form.setAttribute('data-wd-running', '');

        // Everything below happens after the browser has serialised the form, so the button that
        // was pressed is still part of what is submitted. Disabling it any earlier would drop it.
        window.setTimeout(function() {
            form.classList.add('wd-running');
            form.setAttribute('aria-busy', 'true');

            var controls = form.querySelectorAll('button, input[type="submit"], input[type="checkbox"], select');

            for (var i = 0; i < controls.length; i++) {
                controls[i].disabled = true;
            }

            // Revealing a live region is what announces it; the text is already in the DOM.
            var status = form.querySelector('[data-wd-run-status]');

            if (status) {
                status.hidden = false;
            }
        }, 0);
    });
})();
