/* ==========================================================================
   Interactive e-Learning System - application scripts
   --------------------------------------------------------------------------
   Vanilla ES2018. No jQuery, no build step, one request for the whole app.

   Loaded with `defer`, so the DOM is already parsed when this runs.

   Feature areas
     1. Theme (Bootstrap native data-bs-theme, persisted)
     2. Mobile sidebar offcanvas
     3. Alerts (dismiss + auto-hide success/info only)
     4. Confirmations for destructive forms
     5. Table filters / search
     6. Auto-submitting filter forms (opt-in via data-auto-submit)
     7. Bootstrap form select-all checkboxes
     8. Drag-and-drop ordering (used by the rubric builder)
     9. File input feedback and client-side size guard
    10. Assignment description textarea counter
    11. Scoring calculators
    12. Character counter for textarea
   ========================================================================== */

(function () {
    'use strict';

    /* ----------------------------------------------------------------------
       1. Theme
       ---------------------------------------------------------------------- */

    const THEME_KEY = 'ils-theme';

    function getStoredTheme() {
        try {
            return localStorage.getItem(THEME_KEY);
        } catch (e) {
            return null;
        }
    }

    function setStoredTheme(value) {
        try {
            if (value) {
                localStorage.setItem(THEME_KEY, value);
            } else {
                localStorage.removeItem(THEME_KEY);
            }
        } catch (e) {
            /* Private mode: the in-memory switch still works. */
        }
    }

    function prefersDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        syncThemeButtons(theme);

        // Keep <meta name="theme-color"> in step with the surface colour so
        // mobile browser chrome matches the page.
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', theme === 'dark' ? '#0b1120' : '#ffffff');
        }
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    }

    function syncThemeButtons(theme) {
        const icon = theme === 'dark' ? 'fa-sun' : 'fa-moon';
        const label = theme === 'dark' ? 'Light mode' : 'Dark mode';

        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            const i = btn.querySelector('i');
            if (i) {
                i.classList.remove('fa-sun', 'fa-moon');
                i.classList.add(icon);
            }
            btn.setAttribute('aria-label', 'Switch to ' + label);
            btn.setAttribute('title', 'Switch to ' + label);
            btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        });
    }

    function initTheme() {
        applyTheme(getStoredTheme() || (prefersDark() ? 'dark' : 'light'));

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-theme-toggle]');
            if (!btn) return;
            const next = currentTheme() === 'dark' ? 'light' : 'dark';
            setStoredTheme(next);
            applyTheme(next);
        });

        // Follow the OS only while the visitor has not made an explicit choice.
        if (window.matchMedia) {
            const mq = window.matchMedia('(prefers-color-scheme: dark)');
            const onChange = function () {
                if (!getStoredTheme()) {
                    applyTheme(mq.matches ? 'dark' : 'light');
                }
            };
            if (mq.addEventListener) {
                mq.addEventListener('change', onChange);
            } else if (mq.addListener) {
                mq.addListener(onChange);
            }
        }
    }

    /* ----------------------------------------------------------------------
       2. Mobile sidebar
       ---------------------------------------------------------------------- */

    function initSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (!sidebar) return;

        let instance = null;
        if (window.bootstrap && bootstrap.Offcanvas) {
            instance = bootstrap.Offcanvas.getOrCreateInstance(sidebar);
        }

        function close() {
            if (instance) {
                instance.hide();
            }
        }

        document.querySelectorAll('[data-sidebar-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (window.innerWidth >= 992) {
                    return;
                }
                if (instance) {
                    instance.show();
                }
            });
        });

        document.querySelectorAll('[data-sidebar-close]').forEach(function (btn) {
            btn.addEventListener('click', close);
        });

        if (backdrop) {
            backdrop.addEventListener('click', close);
        }

        // Navigating on a phone should not leave the drawer open behind the
        // next page.
        sidebar.querySelectorAll('a[href]').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth < 992) close();
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && window.innerWidth < 992) {
                close();
            }
        });

        // Returning to desktop while the drawer is open leaves an orphaned
        // backdrop behind, so clear the state on the breakpoint crossing.
        if (window.matchMedia) {
            const mq = window.matchMedia('(min-width: 992px)');
            const reset = function () {
                if (!mq.matches) return;
                if (backdrop) backdrop.classList.remove('is-visible');
                sidebar.classList.remove('show');
                sidebar.style.visibility = '';
                sidebar.setAttribute('aria-modal', 'false');
                sidebar.setAttribute('role', '');
            };
            if (mq.addEventListener) {
                mq.addEventListener('change', reset);
            } else if (mq.addListener) {
                mq.addListener(reset);
            }
        }
    }

    /* ----------------------------------------------------------------------
       3. Alerts
       ---------------------------------------------------------------------- */

    function dismissAlert(el) {
        if (window.bootstrap && bootstrap.Alert) {
            bootstrap.Alert.getOrCreateInstance(el).close();
        } else {
            el.remove();
        }
    }

    function initAlerts() {
        document.querySelectorAll('.alert[data-autodismiss]').forEach(function (alert) {
            const delay = parseInt(alert.getAttribute('data-autodismiss'), 10) || 8000;
            window.setTimeout(function () {
                dismissAlert(alert);
            }, delay);
        });
    }

    /* ----------------------------------------------------------------------
       4. Confirmation for destructive submissions
       ---------------------------------------------------------------------- */

    function initConfirmations() {
        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (!form.hasAttribute('data-confirm')) return;

            const message = form.getAttribute('data-confirm') || 'Are you sure?';
            if (!window.confirm(message)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }

    /* ----------------------------------------------------------------------
       5. Table search
       ---------------------------------------------------------------------- */

    function initTableSearch() {
        document.querySelectorAll('[data-table-search]').forEach(function (input) {
            const targetSel = input.getAttribute('data-table-search');
            const table = document.querySelector(targetSel);
            if (!table) return;

            const empty = document.querySelector(
                input.getAttribute('data-table-search-empty') || '#' + targetSel.replace(/[^A-Za-z0-9]/g, '') + 'Empty'
            );

            function run() {
                const term = input.value.trim().toLowerCase();
                let visible = 0;

                table.querySelectorAll('tbody tr').forEach(function (row) {
                    // Rows carrying data-no-filter (e.g. an "empty" row) stay put.
                    if (row.hasAttribute('data-no-filter')) return;
                    const match = term === '' || row.textContent.toLowerCase().indexOf(term) !== -1;
                    row.hidden = !match;
                    if (match) visible++;
                });

                if (empty) {
                    empty.hidden = visible > 0;
                }
            }

            let timer = null;
            input.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(run, 120);
            });
            input.addEventListener('search', run);
            run();
        });
    }

    /* ----------------------------------------------------------------------
       6. Auto-submitting filter forms
       ---------------------------------------------------------------------- */

    function initAutoSubmit() {
        document.querySelectorAll('form[data-auto-submit]').forEach(function (form) {
            form.querySelectorAll('select[data-auto-submit]').forEach(function (el) {
                el.addEventListener('change', function () {
                    form.submit();
                });
            });
        });
    }

    /* ----------------------------------------------------------------------
       7. Select-all checkboxes
       ---------------------------------------------------------------------- */

    function initSelectAll() {
        document.querySelectorAll('[data-select-all]').forEach(function (master) {
            const scope = master.closest('table') || document;
            const value = master.getAttribute('data-select-all');
            let selector;
            if (!value) {
                selector = 'tbody input[type="checkbox"]';
            } else if (/^[.\[#]/.test(value) || /\s|:|,/.test(value)) {
                // A CSS selector, e.g. data-select-all=".submission-checkbox".
                selector = value;
            } else {
                // Otherwise an input name, e.g. data-select-all="selected_submissions[]".
                selector = 'input[type="checkbox"][name="' + value + '"]';
            }

            function boxes() {
                return Array.prototype.slice.call(scope.querySelectorAll(selector));
            }

            function sync() {
                const all = boxes();
                const checked = all.filter(function (b) { return b.checked; });
                master.checked = all.length > 0 && checked.length === all.length;
                master.indeterminate = checked.length > 0 && checked.length < all.length;
            }

            master.addEventListener('change', function () {
                boxes().forEach(function (box) {
                    box.checked = master.checked;
                });
                sync();
                document.dispatchEvent(new CustomEvent('ils:selection-change'));
            });

            scope.addEventListener('change', function (e) {
                if (e.target.matches(selector)) sync();
            });

            sync();
        });
    }

    /* ----------------------------------------------------------------------
       8. Drag-and-drop ordering
       ---------------------------------------------------------------------- */

    function initSortable() {
        document.querySelectorAll('[data-sortable]').forEach(function (list) {
            const handleSel = list.getAttribute('data-sortable-handle') || '.sortable-handle';
            let dragged = null;
            let submitting = false;

            list.querySelectorAll('[draggable="true"]').forEach(function (item) {
                item.addEventListener('dragstart', function (e) {
                    dragged = item;
                    item.classList.add('is-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    // Firefox requires data to be set for the drag to start.
                    try {
                        e.dataTransfer.setData('text/plain', '');
                    } catch (err) { /* no-op */ }
                });

                item.addEventListener('dragend', function () {
                    item.classList.remove('is-dragging');
                    const wasDragging = dragged !== null;
                    dragged = null;
                    if (wasDragging && !submitting) {
                        syncOrder();
                    }
                });

                item.addEventListener('dragover', function (e) {
                    if (!dragged || dragged === item) return;
                    e.preventDefault();
                    const rect = item.getBoundingClientRect();
                    const after = (e.clientY - rect.top) / rect.height > 0.5;
                    list.insertBefore(dragged, after ? item.nextSibling : item);
                });

                item.addEventListener('dragleave', function () {
                    /* Visual feedback is driven by .is-dragging only. */
                });
            });

            // Handles are buttons by default; stop them from submitting or
            // capturing focus in a way that fights the drag.
            list.addEventListener('mousedown', function (e) {
                if (e.target.closest(handleSel)) {
                    e.preventDefault();
                }
            });

            function syncOrder() {
                const prefix = list.getAttribute('data-sortable-prefix') || 'position';
                let i = 1;

                const ids = [];
                list.querySelectorAll('[data-sortable-item]').forEach(function (item) {
                    i++;
                    ids.push(item.dataset.sortableItem);
                    const input = item.querySelector('input[name="' + prefix + '[' + item.dataset.sortableItem + ']"]');
                    if (input) input.value = i;
                });

                const field = list.getAttribute('data-sortable-field');
                if (field) {
                    const hidden = document.querySelector(field);
                    if (hidden) hidden.value = ids.join(',');
                }

                document.dispatchEvent(new CustomEvent('ils:sortable-change', { detail: { list: list, order: ids } }));

                // Opt-in auto-save: the form is submitted from JS rather than
                // relying on a visible Save button, which is why the server
                // still re-validates ownership of every id in the list.
                if (list.hasAttribute('data-sortable-autosubmit')) {
                    const form = list.closest('form') || document.getElementById('reorderForm');
                    if (form) {
                        submitting = true;
                        form.submit();
                    }
                }
            }
        });
    }

    /* ----------------------------------------------------------------------
       9. File input feedback
       ---------------------------------------------------------------------- */

    function humanSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function initFileInputs() {
        document.querySelectorAll('input[type="file"][data-file-feedback]').forEach(function (input) {
            const output = document.querySelector(input.getAttribute('data-file-feedback'));
            const maxMb = parseFloat(input.getAttribute('data-max-mb') || '0');

            input.addEventListener('change', function () {
                if (!output) return;
                const file = input.files && input.files[0];

                if (!file) {
                    output.textContent = '';
                    output.className = 'form-text';
                    input.classList.remove('is-invalid');
                    return;
                }

                const summary = file.name + ' - ' + humanSize(file.size);
                output.textContent = summary;
                output.className = 'form-text text-success';

                if (maxMb > 0 && file.size > maxMb * 1024 * 1024) {
                    output.textContent = summary + ' - larger than the ' + maxMb + ' MB limit';
                    output.className = 'form-text text-danger fw-semibold';
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                }
            });
        });
    }

    /* ----------------------------------------------------------------------
       10. Textarea counters and auto-grow
       ---------------------------------------------------------------------- */

    function initTextareas() {
        document.querySelectorAll('textarea[data-counter]').forEach(function (area) {
            const output = document.querySelector(area.getAttribute('data-counter'));
            const max = area.getAttribute('maxlength');

            function update() {
                if (!output) return;
                const text = area.value.trim();
                output.textContent = max
                    ? text.length + ' / ' + max
                    : String(text.length);
            }

            area.addEventListener('input', update);
            update();
        });

        document.querySelectorAll('textarea[data-autogrow]').forEach(function (area) {
            const grow = function () {
                area.style.height = 'auto';
                area.style.height = area.scrollHeight + 'px';
            };
            area.addEventListener('input', grow);
            grow();
        });
    }

    /* ----------------------------------------------------------------------
       11. Scoring calculators
       ---------------------------------------------------------------------- */

    function initScoreCalculators() {
        document.querySelectorAll('[data-score-calculator]').forEach(function (box) {
            const inputs = box.querySelectorAll('input[type="number"]');
            const output = document.querySelector(box.getAttribute('data-score-calculator'));
            if (!inputs.length || !output) return;

            function update() {
                let earned = 0;
                let possible = 0;
                let filled = 0;

                inputs.forEach(function (input) {
                    const max = parseFloat(input.getAttribute('max'));
                    if (!isNaN(max)) possible += max;
                    const val = parseFloat(input.value);
                    if (!isNaN(val)) {
                        earned += val;
                        filled++;
                    }
                });

                const pct = possible > 0 ? Math.round((earned / possible) * 100) : 0;
                output.textContent = filled
                    ? earned + ' / ' + possible + ' points (' + pct + '%)'
                    : 'No scores entered yet';
            }

            inputs.forEach(function (input) {
                input.addEventListener('input', update);
            });
            update();
        });
    }

    /* ----------------------------------------------------------------------
       15. Row-driven modals and bulk selection count
       ---------------------------------------------------------------------- */

    function text(el, value) {
        if (el) el.textContent = value;
    }

    function badge(label, variant) {
        return '<span class="badge ' + variant + '">' + label + '</span>';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderPeerReviews(reviews) {
        if (!reviews.length) {
            return '<p class="text-center text-muted py-4 mb-0">' +
                'No peer reviews have been assigned for this submission yet.</p>';
        }

        return reviews.map(function (review) {
            const name = (review.first_name || '') + ' ' + (review.last_name || '');
            const completed = review.status === 'completed';
            const score = review.avg_score === null || review.avg_score === undefined
                ? ''
                : ' ' + badge('Score: ' + Number(review.avg_score).toFixed(1), 'badge-soft-info');

            const feedback = review.overall_feedback
                ? '<p class="mb-1"><strong>Overall feedback:</strong></p>' +
                  '<p class="mb-2">' + escapeHtml(review.overall_feedback)
                        .replace(/\r?\n/g, '<br>') + '</p>'
                : '<p class="text-muted mb-2">No overall feedback provided yet.</p>';

            return '<div class="card mb-3">' +
                '<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">' +
                    '<div><strong>' + escapeHtml(name) + '</strong>' +
                        (review.is_anonymous ? ' ' + badge('Anonymous', 'badge-soft-neutral') : '') +
                    '</div>' +
                    '<div>' + badge(
                        completed ? 'Completed' : String(review.status || '').replace(/_/g, ' ')
                            .replace(/^./, function (c) { return c.toUpperCase(); }),
                        completed ? 'badge-soft-success' : 'badge-soft-warning'
                    ) + score + '</div>' +
                '</div>' +
                '<div class="card-body">' + feedback +
                    '<small class="text-muted">Assigned ' +
                    escapeHtml(review.review_date || '') + '</small>' +
                '</div>' +
            '</div>';
        }).join('');
    }

    function initRowModals() {
        // One shared "grade" dialog, filled from the clicked row.
        document.querySelectorAll('[data-bs-target="#gradeModal"]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const id = trigger.getAttribute('data-submission-id') || '';
                text(document.getElementById('gradeStudentName'), trigger.getAttribute('data-student-name') || '');
                text(document.getElementById('gradePeerScore'), trigger.getAttribute('data-peer-score') || '—');

                const gradeInput = document.getElementById('gradeInput');
                if (gradeInput) gradeInput.value = trigger.getAttribute('data-grade') || '';

                const feedback = document.getElementById('feedbackInput');
                if (feedback) {
                    feedback.value = trigger.getAttribute('data-feedback') || '';
                    feedback.dispatchEvent(new Event('input'));
                }

                const hidden = document.getElementById('gradeSubmissionId');
                if (hidden) hidden.value = id;
            });
        });

        // One shared "peer reviews" dialog, filled from JSON on the trigger.
        document.querySelectorAll('[data-bs-target="#peerReviewsModal"]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const title = document.getElementById('peerReviewsModalLabel');
                text(title, 'Peer Reviews' +
                    (trigger.getAttribute('data-student-name')
                        ? ' for ' + trigger.getAttribute('data-student-name') : ''));

                const body = document.getElementById('peerReviewsBody');
                if (!body) return;

                let reviews = [];
                try {
                    reviews = JSON.parse(trigger.getAttribute('data-reviews') || '[]');
                } catch (err) {
                    reviews = [];
                }
                body.innerHTML = renderPeerReviews(Array.isArray(reviews) ? reviews : []);
            });
        });
    }

    function initBulkSelectionCount() {
        const label = document.querySelector('[data-bulk-count]');
        if (!label) return;

        const boxes = Array.prototype.slice.call(document.querySelectorAll('.submission-checkbox'));

        function update() {
            const count = boxes.filter(function (b) { return b.checked; }).length;
            label.textContent = count
                ? count + (count === 1 ? ' submission selected.' : ' submissions selected.')
                : 'No submissions selected.';
        }

        document.addEventListener('ils:selection-change', update);
        boxes.forEach(function (box) {
            box.addEventListener('change', update);
        });

        // The table's checkboxes belong to the bulk form through form="bulkForm",
        // so block an empty submit here rather than letting the server reject it.
        const form = document.getElementById('bulkForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (boxes.filter(function (b) { return b.checked; }).length === 0) {
                    e.preventDefault();
                    label.textContent = 'Select at least one submission first.';
                }
            });
        }

        update();
    }

    /* ----------------------------------------------------------------------
       Bootstrap tooltips, if any opt in
       ---------------------------------------------------------------------- */

    function initTooltips() {
        if (!window.bootstrap || !bootstrap.Tooltip) return;
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    }

    /* ----------------------------------------------------------------------
       Boot
       ---------------------------------------------------------------------- */

    function init() {
        initTheme();
        initSidebar();
        initAlerts();
        initConfirmations();
        initTableSearch();
        initAutoSubmit();
        initSelectAll();
        initSortable();
        initFileInputs();
        initTextareas();
        initScoreCalculators();
        initRowModals();
        initBulkSelectionCount();
        initTooltips();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.ilsTheme = { set: setStoredTheme, get: getStoredTheme, apply: applyTheme, current: currentTheme };
})();
