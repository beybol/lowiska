/*
 * Skrypt portalu wędkarza — wyłącznie w układzie portalu (zadanie 032).
 *
 * Zakładki strony łowiska: obie treści są w jednym dokumencie HTML (indeksowane, 027 pkt 9.4).
 * Bez skryptu stoją jedna pod drugą; skrypt chowa nieaktywną i otwiera tę z kotwicy adresu
 * (`#szczegoly` / `#details` — kotwica zależy od języka strony), także na `hashchange`.
 *
 * Kontrakt znaczników:
 *   [data-tabs]                     — kontener zakładek
 *   [data-tab-link="<kotwica>"]     — link zakładki, `href="#<kotwica>"`
 *   [data-tab-panel="<kotwica>"]    — treść zakładki, `id="<kotwica>"`
 *   [data-tab-open="<kotwica>"]     — przycisk w treści: przełącza zakładkę bez przewijania
 * Pierwszy panel jest domyślny.
 */
function initTabs(root) {
    const links = Array.from(root.querySelectorAll('[data-tab-link]'));
    const panels = Array.from(root.querySelectorAll('[data-tab-panel]'));

    if (panels.length === 0) {
        return;
    }

    const show = (name) => {
        const target = panels.find((panel) => panel.dataset.tabPanel === name) ?? panels[0];

        panels.forEach((panel) => {
            panel.hidden = panel !== target;
        });

        links.forEach((link) => {
            const active = link.dataset.tabLink === target.dataset.tabPanel;
            link.setAttribute('aria-selected', active ? 'true' : 'false');
            link.classList.toggle('is-active', active);
        });
    };

    const fromHash = () => decodeURIComponent(window.location.hash.replace(/^#/, ''));

    root.classList.add('tabs-ready');
    show(fromHash());

    links.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const name = link.dataset.tabLink;
            // Pierwsza zakładka nie zostawia kotwicy — adres łowiska zostaje kanoniczny.
            const hash = name === panels[0].dataset.tabPanel ? ' ' : `#${name}`;
            history.replaceState(null, '', hash === ' ' ? window.location.pathname + window.location.search : hash);
            show(name);
        });
    });

    // Przyciski wewnątrz treści (np. „Szczegóły →" pod opisem) przełączają zakładkę BEZ przewijania
    // i bez kotwicy w adresie — kotwica powodowała skok strony. Bez skryptu są ukryte (`hidden`),
    // bo obie treści i tak stoją jedna pod drugą.
    root.querySelectorAll('[data-tab-open]').forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', () => show(button.dataset.tabOpen));
    });

    window.addEventListener('hashchange', () => show(fromHash()));
}

document.querySelectorAll('[data-tabs]').forEach(initTabs);

/*
 * Kalendarz strony łowiska — stopniowe ulepszenie (ADR-022).
 *
 * Każdy przełącznik to zwykły link z parametrami; bez skryptu działa przeładowaniem. Skrypt pobiera
 * TEN SAM adres z nagłówkiem `X-Portal-Fragment: calendar`, podmienia wyłącznie fragment
 * `[data-calendar]` i zapisuje adres w historii — stan zostaje w adresie, strona nie skacze.
 */
async function loadCalendar(url, { push } = { push: true }) {
    const current = document.querySelector('[data-calendar]');

    if (!current) {
        window.location.href = url;
        return;
    }

    current.setAttribute('aria-busy', 'true');

    try {
        const response = await fetch(url, { headers: { 'X-Portal-Fragment': 'calendar' } });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const template = document.createElement('template');
        template.innerHTML = (await response.text()).trim();
        const next = template.content.querySelector('[data-calendar]');

        if (!next) {
            throw new Error('No calendar fragment in the response');
        }

        current.replaceWith(next);

        if (push) {
            history.pushState({ calendar: true }, '', url);
        }
    } catch {
        // Cokolwiek poszło nie tak — zwykłe przejście, które zawsze działa.
        window.location.href = url;
    }
}

document.addEventListener('click', (event) => {
    const link = event.target.closest('[data-calendar-link]');

    if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
        return;
    }

    event.preventDefault();
    loadCalendar(link.href);
});

window.addEventListener('popstate', () => {
    if (document.querySelector('[data-calendar]')) {
        loadCalendar(window.location.href, { push: false });
    }
});

/*
 * Podpowiedź komórki: siatka jest przewijanym kontenerem (przyklejony nagłówek i kolumna), więc
 * dymek pozycjonowany absolutnie byłby przez niego przycięty. Skrypt przypina go do okna
 * (`position: fixed`) obok komórki; bez skryptu działa wariant CSS.
 */
function placeTip(anchor) {
    const body = anchor.querySelector('[data-calendar-tip-body]');

    if (!body) {
        return;
    }

    const rect = anchor.getBoundingClientRect();
    const width = body.offsetWidth || 256;
    const left = Math.min(Math.max(8, rect.left + rect.width / 2 - width / 2), window.innerWidth - width - 8);
    const below = rect.bottom + 6;
    const top = below + body.offsetHeight > window.innerHeight ? Math.max(8, rect.top - body.offsetHeight - 6) : below;

    Object.assign(body.style, { position: 'fixed', left: `${left}px`, top: `${top}px`, transform: 'none', marginTop: '0' });
}

['mouseover', 'focusin'].forEach((type) => {
    document.addEventListener(type, (event) => {
        const anchor = event.target.closest('[data-calendar-tip]');

        if (anchor) {
            placeTip(anchor);
        }
    });
});
