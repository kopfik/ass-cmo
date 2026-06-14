(function () {
    const storageKey = 'ass-cmo-theme';
    const root = document.documentElement;
    const buttons = Array.from(document.querySelectorAll('[data-theme-choice]'));

    function systemTheme() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function applyTheme(choice) {
        const selected = choice || localStorage.getItem(storageKey) || 'auto';
        const effective = selected === 'auto' ? systemTheme() : selected;

        root.dataset.theme = effective;
        root.dataset.themeChoice = selected;

        for (const button of buttons) {
            button.classList.toggle('active', button.dataset.themeChoice === selected);
        }
    }

    for (const button of buttons) {
        button.addEventListener('click', () => {
            localStorage.setItem(storageKey, button.dataset.themeChoice);
            applyTheme(button.dataset.themeChoice);
        });
    }

    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if ((localStorage.getItem(storageKey) || 'auto') === 'auto') {
                applyTheme('auto');
            }
        });
    }

    applyTheme(localStorage.getItem(storageKey) || 'auto');
})();
