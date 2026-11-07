const menuButton = document.querySelector('.menu-toggle');
const nav = document.querySelector('.nav-links');
const panelScrollKey = 'max720rp-panel-scroll';

if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
const savedPanelScroll = sessionStorage.getItem(panelScrollKey);
if (savedPanelScroll !== null) {
  sessionStorage.removeItem(panelScrollKey);
  const restoreY = Number(savedPanelScroll);
  if (Number.isFinite(restoreY)) requestAnimationFrame(() => window.scrollTo(0, restoreY));
}

document.querySelectorAll('.admin-section form, .product-admin form').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    sessionStorage.setItem(panelScrollKey, String(window.scrollY));
  });
});

if (menuButton && nav) menuButton.addEventListener('click', () => {
  const open = menuButton.getAttribute('aria-expanded') === 'true';
  menuButton.setAttribute('aria-expanded', String(!open));
  nav.classList.toggle('is-open', !open);
  window.dispatchEvent(new Event('resize'));
});

if (nav) {
  const activeLink = nav.querySelector('a.active');
  const moveMarker = (link) => {
    if (!link) {
      nav.style.setProperty('--marker-width', '0px');
      return;
    }
    const navBox = nav.getBoundingClientRect();
    const linkBox = link.getBoundingClientRect();
    nav.style.setProperty('--marker-left', `${linkBox.left - navBox.left}px`);
    nav.style.setProperty('--marker-width', `${linkBox.width}px`);
  };
  nav.querySelectorAll('a').forEach((link) => link.addEventListener('mouseenter', () => moveMarker(link)));
  nav.addEventListener('mouseleave', () => moveMarker(activeLink));
  window.addEventListener('resize', () => moveMarker(activeLink));
  requestAnimationFrame(() => moveMarker(activeLink));
}

document.querySelectorAll('.flash').forEach((message) => {
  window.setTimeout(() => {
    message.classList.add('is-leaving');
    window.setTimeout(() => message.remove(), 450);
  }, 8000);
});
