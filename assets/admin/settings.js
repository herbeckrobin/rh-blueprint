(function () {
    'use strict';

    const wrap = document.querySelector('.rhbp-settings');
    if (!wrap) {
        return;
    }

    const searchInput = wrap.querySelector('#rhbp-search-input');
    const tabs = Array.from(wrap.querySelectorAll('.rhbp-tabs .nav-tab'));
    const panels = Array.from(wrap.querySelectorAll('.rhbp-tab-panel'));
    const activeTab = wrap.dataset.activeTab || (tabs[0] && tabs[0].dataset.tab);

    function showPanel(tabId) {
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.tabPanel !== tabId;
        });
        tabs.forEach((tab) => {
            tab.classList.toggle('nav-tab-active', tab.dataset.tab === tabId);
        });
        wrap.dataset.activeTab = tabId;
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', (event) => {
            event.preventDefault();
            showPanel(tab.dataset.tab);
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab.dataset.tab);
            window.history.replaceState({}, '', url);
        });
    });

    if (searchInput) {
        const rows = Array.from(wrap.querySelectorAll('.form-table tr'));

        searchInput.addEventListener('input', () => {
            const term = searchInput.value.trim().toLowerCase();
            const searching = term.length > 0;

            wrap.classList.toggle('is-searching', searching);

            if (!searching) {
                rows.forEach((row) => {
                    row.hidden = false;
                });
                showPanel(wrap.dataset.activeTab || activeTab);
                return;
            }

            panels.forEach((panel) => {
                panel.hidden = false;
            });

            let visibleCount = 0;

            rows.forEach((row) => {
                const field = row.querySelector('.rhbp-field');
                const index = field ? (field.dataset.searchIndex || '') : (row.textContent || '').toLowerCase();
                const match = index.indexOf(term) !== -1;
                row.hidden = !match;
                if (match) {
                    visibleCount += 1;
                }
            });

            panels.forEach((panel) => {
                const visibleRows = panel.querySelectorAll('.form-table tr:not([hidden])');
                const emptyMarker = panel.querySelector('.rhbp-no-results');

                if (visibleRows.length === 0) {
                    panel.hidden = true;
                } else if (emptyMarker) {
                    emptyMarker.remove();
                }
            });

            if (visibleCount === 0) {
                let banner = wrap.querySelector('.rhbp-no-results-global');
                if (!banner) {
                    banner = document.createElement('p');
                    banner.className = 'rhbp-no-results rhbp-no-results-global';
                    banner.textContent = 'Keine Treffer.';
                    wrap.querySelector('.rhbp-form').prepend(banner);
                }
            } else {
                const banner = wrap.querySelector('.rhbp-no-results-global');
                if (banner) {
                    banner.remove();
                }
            }
        });
    }
}());
