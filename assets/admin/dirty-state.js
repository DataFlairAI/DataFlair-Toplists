/* DataFlair Admin — Dirty-state tracker for settings forms. Phase 9.6.
 *
 * Usage: call window.DFAdmin.dirtyState.init('#my-form') on a form element.
 * Shows an "Unsaved changes" amber pill next to the save button, disables
 * the save button when clean, and fires a beforeunload warning on navigation.
 */
(function () {
    'use strict';

    window.DFAdmin = window.DFAdmin || {};

    window.DFAdmin.dirtyState = {
        _dirty: false,
        _form:  null,

        init: function (formSelector) {
            var self   = this;
            self._form = document.querySelector(formSelector);
            if (!self._form) { return; }

            // Snapshot initial values
            var snapshot = self._snapshot();

            self._form.addEventListener('input',  function () { self._check(snapshot); });
            self._form.addEventListener('change', function () { self._check(snapshot); });

            // beforeunload guard
            window.addEventListener('beforeunload', function (e) {
                if (self._dirty) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            // Hide pill initially
            self._setPill(false);
        },

        markClean: function () {
            this._dirty = false;
            this._setPill(false);
        },

        _check: function (snapshot) {
            var current  = this._snapshot();
            var is_dirty = current !== snapshot;
            if (is_dirty !== this._dirty) {
                this._dirty = is_dirty;
                this._setPill(is_dirty);
            }
        },

        _snapshot: function () {
            if (!this._form) { return ''; }
            var parts = [];
            var els   = this._form.querySelectorAll('input, select, textarea');
            els.forEach(function (el) {
                if (el.type === 'checkbox' || el.type === 'radio') {
                    parts.push(el.name + '=' + (el.checked ? '1' : '0'));
                } else {
                    parts.push(el.name + '=' + el.value);
                }
            });
            return parts.join('&');
        },

        _setPill: function (dirty) {
            var pill = document.getElementById('df-dirty-pill');
            if (!pill && dirty) {
                pill = document.createElement('span');
                pill.id        = 'df-dirty-pill';
                pill.className = 'df-pill df-pill--warning';
                pill.style.marginLeft = '10px';
                pill.textContent = '● Unsaved changes';
                var form = this._form;
                if (form) {
                    var btn = form.querySelector('[type="submit"]');
                    if (btn && btn.parentNode) {
                        btn.parentNode.insertBefore(pill, btn.nextSibling);
                    }
                }
            }
            if (pill) {
                pill.style.display = dirty ? '' : 'none';
            }
        }
    };
}());
