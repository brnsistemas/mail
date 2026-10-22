'use strict';
// Progressive enhancement only: links, filters and pagination work without JS.
function revealAdminTarget() {
    const target = document.getElementById(location.hash.slice(1));
    if (target?.matches('details.admin-create')) target.open = true;
    // Preserve previous inventory bookmarks after the administration was split.
    if (location.pathname === '/admin') {
        const legacy = {'#mailbox-overview': '/admin/mailboxes', '#user-overview': '/admin/users'};
        if (legacy[location.hash]) location.replace(legacy[location.hash]);
    }
}
revealAdminTarget();
window.addEventListener('hashchange', revealAdminTarget);
if (document.querySelector('.notice.error')) {
    document.querySelectorAll('details.admin-create').forEach(section => { section.open = true; });
}
const filter = document.querySelector('[data-permission-filter]');
filter?.addEventListener('input', () => {
    const query = filter.value.trim().toLocaleLowerCase('pt-BR');
    document.querySelectorAll('[data-permission-option]').forEach(option => {
        // A selected box remains visible so an active filter cannot hide a grant.
        option.hidden = !option.textContent.toLocaleLowerCase('pt-BR').includes(query) && !option.querySelector('input').checked;
    });
});
const boxInputs = document.querySelectorAll('[data-permission-option] input');
const count = document.querySelector('[data-permission-count]');
const updateCount = () => {
    if (count) count.textContent = `${[...boxInputs].filter(input => input.checked).length} caixa(s) selecionada(s).`;
};
boxInputs.forEach(input => input.addEventListener('change', updateCount));
updateCount();
