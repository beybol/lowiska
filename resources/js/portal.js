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
