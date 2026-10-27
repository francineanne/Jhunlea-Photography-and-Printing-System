const root = document.documentElement;

document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const dark = root.classList.toggle('dark');

        try {
            localStorage.setItem('theme', dark ? 'dark' : 'light');
        } catch (e) {
            // storage unavailable; theme still applies for this page view
        }
    });
});
