(function () {
    'use strict';

    var root = document.querySelector('[data-estab-handbook]');
    var search = document.querySelector('[data-estab-handbook-search]');
    var clear = document.querySelector('[data-estab-handbook-clear]');
    var status = document.querySelector('[data-estab-handbook-status]');
    var empty = document.querySelector('[data-estab-handbook-empty]');
    var sections = Array.from(
        document.querySelectorAll('[data-estab-handbook-section]')
    );
    var tocLinks = Array.from(
        document.querySelectorAll('[data-estab-handbook-toc] a[href^="#"]')
    );

    if (!root || !search || !clear || !status || !empty || !sections.length) {
        return;
    }

    function normalize(value) {
        return String(value || '')
            .toLocaleLowerCase('de-DE')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    var corpus = new Map();
    sections.forEach(function (section) {
        corpus.set(
            section,
            normalize(
                section.textContent + ' ' + (section.dataset.handbookKeywords || '')
            )
        );
    });

    function updateLocation(query) {
        if (!window.history || typeof window.history.replaceState !== 'function') {
            return;
        }
        var url = new URL(window.location.href);
        if (query) {
            url.searchParams.set('q', query);
        } else {
            url.searchParams.delete('q');
        }
        window.history.replaceState(null, '', url.pathname + url.search + url.hash);
    }

    function applySearch(updateUrl) {
        var rawQuery = search.value.trim();
        var tokens = normalize(rawQuery).split(' ').filter(Boolean);
        var visible = 0;

        sections.forEach(function (section) {
            var haystack = corpus.get(section) || '';
            var matches = tokens.every(function (token) {
                return haystack.indexOf(token) !== -1;
            });
            section.hidden = !matches;
            if (matches) {
                visible += 1;
            }
        });

        tocLinks.forEach(function (link) {
            var target = document.getElementById(
                decodeURIComponent(link.hash.slice(1))
            );
            var item = link.closest('li');
            if (item) {
                item.hidden = Boolean(target && target.hidden);
            }
        });

        clear.hidden = rawQuery === '';
        empty.hidden = visible !== 0;
        root.toggleAttribute('data-estab-handbook-filtered', rawQuery !== '');

        if (rawQuery === '') {
            status.textContent = 'Alle ' + sections.length + ' Kapitel werden angezeigt.';
        } else if (visible === 0) {
            status.textContent = 'Keine Kapitel gefunden.';
        } else if (visible === 1) {
            status.textContent = '1 passendes Kapitel wird angezeigt.';
        } else {
            status.textContent = visible + ' passende Kapitel werden angezeigt.';
        }

        if (updateUrl) {
            updateLocation(rawQuery);
        }
    }

    function resetSearch(focusSearch) {
        search.value = '';
        applySearch(true);
        if (focusSearch) {
            search.focus();
        }
    }

    search.addEventListener('input', function () {
        applySearch(true);
    });
    search.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && search.value !== '') {
            event.preventDefault();
            resetSearch(true);
        }
    });
    clear.addEventListener('click', function () {
        resetSearch(true);
    });

    document.addEventListener('click', function (event) {
        var eventTarget = event.target instanceof Element ? event.target : null;
        var link = eventTarget ? eventTarget.closest('a[href^="#"]') : null;
        if (!link || !link.hash) {
            return;
        }
        var target = document.getElementById(decodeURIComponent(link.hash.slice(1)));
        if (target && target.matches('[data-estab-handbook-section]') && target.hidden) {
            resetSearch(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        var target = event.target;
        var editable = target instanceof HTMLInputElement
            || target instanceof HTMLTextAreaElement
            || target instanceof HTMLSelectElement
            || (target && target.isContentEditable);
        if (event.key === '/' && !editable && !event.ctrlKey && !event.metaKey) {
            event.preventDefault();
            search.focus();
        }
    });

    var initialQuery = new URL(window.location.href).searchParams.get('q');
    if (initialQuery) {
        search.value = initialQuery.slice(0, 200);
    }
    applySearch(false);
}());
