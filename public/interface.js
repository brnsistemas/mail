'use strict';
// Optional enhancements. Forms and navigation also work without JavaScript.
function revealSettingsForm() {
    const target = document.getElementById(location.hash.slice(1));
    if (target?.matches('details.settings-create')) target.open = true;
}
revealSettingsForm();
window.addEventListener('hashchange', revealSettingsForm);
if (document.querySelector('.notice.error')) {
    document.querySelectorAll('details.settings-create').forEach(section => { section.open = true; });
}
for (const picker of document.querySelectorAll('[data-mailbox-picker]')) {
    const search = picker.querySelector('[data-box-search]');
    const options = [...picker.querySelectorAll('[data-box-option]')];
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
    const refresh = () => {
        const query = normalize(search.value.trim());
        let selected = 0;
        for (const option of options) {
            const checked = option.querySelector('input[type=checkbox]').checked;
            if (checked) selected++;
            const group = option.closest('[data-box-group]');
            const text = option.textContent + ' ' + group.querySelector('legend').textContent;
            // Keep selected boxes visible; filtering must never silently change permissions.
            option.hidden = !checked && !normalize(text).includes(query);
        }
        for (const group of picker.querySelectorAll('[data-box-group]')) {
            group.hidden = ![...group.querySelectorAll('[data-box-option]')].some(option => !option.hidden);
        }
        picker.querySelector('[data-box-count]').textContent = `${selected} ${selected === 1 ? 'caixa selecionada' : 'caixas selecionadas'}`;
        picker.querySelector('[data-box-empty]').hidden = !options.length || options.some(option => !option.hidden);
    };
    search.addEventListener('input', refresh);
    picker.addEventListener('change', refresh);
    refresh();
}
// Keep the current section visible in the horizontal mobile menu.
for (const nav of document.querySelectorAll('.management-nav')) {
    const current = nav.querySelector('[aria-current=page]');
    if (current && nav.scrollWidth > nav.clientWidth) {
        nav.scrollLeft += current.getBoundingClientRect().left - nav.getBoundingClientRect().left - 12;
    }
}
