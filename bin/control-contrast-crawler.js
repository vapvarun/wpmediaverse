// MediaVerse control-contrast crawler (WCAG 1.4.11 non-text 3:1, 1.4.3 text 4.5:1).
// Dev tool: bin/ is in .distignore and the Gruntfile dist excludes, so it never ships.
// Adapted from the validated BuddyNext and Jetonomy crawlers (same measuring core).
//
// Scope: every input, select, textarea, button, summary, [role=button|tab|switch|menuitem|checkbox|radio|option]
// and every <a> whose class names a control (btn, button, link, cta, chip, tab, toggle, pill), inside any
// element with an mvs- class (blocks, shortcodes, templates, dialogs, popovers) and, on the wp-admin
// MediaVerse and Pro pages, inside #wpbody-content. States: default, hover, focus-visible (forced with :focus, as a real keyboard focus is), active.
// Front: light + dark, set through the real switches MediaVerse follows (bn_theme for BuddyNext's
// data-bn-theme, bx-color-mode for Reign's data-bx-mode), 1280 + 390. Admin: light (MediaVerse admin
// has no dark mode), 1280 + 390.
//
// Each run opens its own browser context (the shared MCP tab is left alone) and logs in through the
// dev auto-login as opts.as: 'admin' (default), a member login such as 'alice', or 'guest'.
// Fixtures: the pages /zz-contrast-blocks/ (every mvs/ block) and /zz-contrast-codes/ (every mvs_
// shortcode), built the way bin/boot-matrix-smoke.sh builds its probe page, plus one document in the
// admin's drive.
//   browser_run_code_unsafe  filename = bin/control-contrast-crawler.js   (installs page.context().mvcCrawl)
//   async (page) => page.context().mvcCrawl(page, { set: 'front', base: 'http://buddynext.local' })
//   async (page) => page.context().mvcCrawl(page, { set: 'front', as: 'alice' })
//   async (page) => page.context().mvcCrawl(page, { set: 'admin' })
// A path "url#key" runs ACTIONS[key] (clicks, in order) before measuring; an opener that is not on
// the page is listed under openerMissing, never silently skipped. EXEMPT controls are counted apart.
// A field drawing no edge and an invisible fill takes its wrapper's border (search pills). A control
// showing an image (a media tile) is identified by the image. Text over a gradient is measured
// against the gradient's lightest stop.
// A one-sided rule under a control that is not a button, [role=button] or field (row separator, tab
// underline) is a divider, judged by its text. An icon-only button is judged by its icon (3:1 against
// its fill, "icon"); every one is listed under iconOnly with its lowest ratio. A button with neither
// text nor icon, a transparent border and a fill under 3:1 fails as "transparent-edge". A summary or
// [aria-expanded] control's chevron must reach 3:1; one with no indicator is listed under noIndicator.
// Hover and active are forced on the control's ancestors too, as a real pointer does. A focusable
// control whose keyboard focus changes nothing (outline, shadow, border, fill, colour) fails as
// no-focus-indicator.

async (page) => {
const FRONT = [
  '/explore/', '/explore-media/', '/explore-media/#lightbox', '/explore-media/#lbshare', '/explore-media/#lbcollect',
  '/explore-media/#lbreport', '/explore-media/#single', '/my-media/', '/my-media/#upload', '/my-media/media/',
  '/my-media/media/#edit', '/my-media/albums/', '/my-media/albums/#newalbum',
  '/my-media/collections/', '/my-media/collections/#newcollection', '/my-media/documents/',
  '/my-media/documents/#docshare', '/my-media/documents/#docdialog', '/upload-media/', '/spaces/founders-lounge/media/',
  '/spaces/founders-lounge/files/', '/zz-contrast-blocks/', '/zz-contrast-codes/',
];

const ADMIN = [
  'wpmediaverse', 'mvs-media', 'mvs-documents', 'mvs-tags', 'mvs-moderation&tab=pending', 'mvs-moderation&tab=resolved',
  'mvs-moderation&tab=user-reports', 'mvs-stats&tab=overview', 'mvs-stats&tab=video-analytics', 'mvs-competitions',
  'mvs-challenges', 'mvs-tournaments', 'mvs-battles', 'mvs-theme-library', 'mvs-settings', 'mvs-settings#sections', 'mvs-setup',
  'mvs-stories', 'mvs-analytics', 'mvs-reports', 'mvs-integrations', 'mvs-logs', 'mvs-migration',
].map((p) => '/wp-admin/admin.php?page=' + p);

const click = (s) => `[data-wp-on--click="actions.${s}"]`;
const ACTIONS = {
  // A grid tile opens the lightbox (the link stays a real link without JS).
  lightbox: ['.mvs-grid-item-link'],
  lbshare: ['.mvs-grid-item-link', click('lightboxShare')],
  lbcollect: ['.mvs-grid-item-link', click('lightboxOpenCollections')],
  // Report shows only on someone else's media: step through the lightbox until it does.
  lbreport: ['.mvs-grid-item-link', { until: '.mvs-lightbox-action--report', next: click('lightboxNext') }, click('lightboxReport')],
  // The single media page the tile links to.
  single: [{ follow: '.mvs-grid-item-link' }],
  upload: [click('openUploadModal')],
  edit: [click('openEditModal')],
  newalbum: [`${click('openCreateAlbum')}, ${click('openAlbumModal')}`],
  newcollection: [click('openCreateCollection')],
  // The row's actions panel (rename, move, access, share with a person).
  docshare: ['summary.mvs-drive__actions-toggle'],
  // The document page's Share dialog (Pro document-share.js), opened from its Share button.
  docdialog: [{ follow: '.mvs-drive__row:not(:has(.mvs-drive__chip--folder)) a.mvs-drive__name' }, 'button.mvs-doc-share-button'],
  // Settings: every section is on one page, shown one at a time; reveal them all.
  sections: [{ eval: () => document.querySelectorAll('.mvs-settings-card[data-section]').forEach((c) => { c.hidden = false; c.style.display = 'block'; }) }],
};
// Design exemptions, each with its reason:
// - The Instagram layout's "Add a comment" field is borderless by design, like the feed it copies;
//   it is drawn with no edge today and stays that way (its top rule is the post's divider).
// - The logged-out banner is a fixed 135deg purple gradient. The crawler measures text against the
//   gradient's lightest stop (#667eea, 3.66:1 for white); at the controls' real position on the
//   gradient white text measures 4.84:1 or more (1280 and 390). Register's edge is solid white,
//   3.66:1 even against that lightest stop.
// - wp-admin's own .nav-tab tabs (the Competitions section nav) are WordPress core chrome: each is
//   identified by its label (5.35:1 or more) and the active one merges into the panel below by
//   design; restyling them would set MediaVerse screens apart from every other admin screen.
// - wp-admin's list-table .toggle-row (phones) is core chrome too; core draws its focus ring on
//   ::before, which the no-focus-indicator check does not read.
const EXEMPT = ['a.nav-tab', 'button.toggle-row', 'input.mvs-ig-comment-input', '.mvs-logged-out-banner__close', '.mvs-logged-out-banner__actions .mvs-btn--secondary'];

// Runs inside the page. Returns one record per tagged control.
const IN_PAGE = (args) => {
  const { scope, tag } = args;
  const cv = document.createElement('canvas');
  cv.width = cv.height = 1;
  const cx = cv.getContext('2d', { willReadFrequently: true });
  const over = (base, color, alpha = 1) => {
    cx.globalAlpha = 1;
    cx.fillStyle = `rgb(${base[0]},${base[1]},${base[2]})`;
    cx.fillRect(0, 0, 1, 1);
    cx.fillStyle = 'rgba(0,0,0,0)';
    cx.fillStyle = color;
    cx.globalAlpha = alpha;
    cx.fillRect(0, 0, 1, 1);
    cx.globalAlpha = 1;
    const d = cx.getImageData(0, 0, 1, 1).data;
    return [d[0], d[1], d[2]];
  };
  const alphaOf = (color) => {
    cx.clearRect(0, 0, 1, 1);
    cx.fillStyle = 'rgba(0,0,0,0)';
    cx.fillStyle = color;
    cx.fillRect(0, 0, 1, 1);
    return cx.getImageData(0, 0, 1, 1).data[3] / 255;
  };
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; };
  const hex = (c) => '#' + c.map((v) => v.toString(16).padStart(2, '0')).join('');
  const opacityOf = (el) => { let o = 1; for (let n = el; n && n.nodeType === 1; n = n.parentElement) o *= parseFloat(getComputedStyle(n).opacity); return o; };
  // Background stack from <html> down to (and optionally including) el.
  const bgUnder = (el, includeSelf) => {
    const chain = [];
    for (let n = includeSelf ? el : el.parentElement; n && n.nodeType === 1; n = n.parentElement) chain.unshift(n);
    let c = [255, 255, 255];
    for (const n of chain) {
      const ncs = getComputedStyle(n);
      const bg = ncs.backgroundColor;
      if (alphaOf(bg) > 0) c = over(c, bg, n === el ? 1 : 1);
      // A gradient paints over the colour: take its lightest stop (the worst case for light
      // text, which is what sits on these fills).
      const stops = /gradient/.test(ncs.backgroundImage) ? ncs.backgroundImage.match(/rgba?\([^)]*\)/g) : null;
      if (stops) c = stops.map((s) => over(c, s)).reduce((m, x) => (lum(x) > lum(m) ? x : m));
    }
    return c;
  };
  const sel = (el) => {
    const cls = [...el.classList].filter((c) => !c.startsWith('data-')).slice(0, 4).map((c) => '.' + c).join('');
    const p = el.parentElement;
    const pc = p ? (p.id ? '#' + p.id : [...p.classList].slice(0, 2).map((c) => '.' + c).join('')) : '';
    const t = el.tagName.toLowerCase() + (el.type && el.tagName === 'INPUT' ? `[type=${el.type}]` : '') + (el.id ? '#' + el.id : '') + cls;
    return (pc ? pc + ' > ' : '') + t;
  };
  const label = (el) => (el.getAttribute('aria-label') || el.innerText || el.value || el.placeholder || '').trim().replace(/\s+/g, ' ').slice(0, 40);

  if (tag) {
    document.querySelectorAll('[data-mvc]').forEach((n) => n.removeAttribute('data-mvc'));
    const Q = 'input:not([type=hidden]), select, textarea, button, summary, .button, [role=button], [role=tab], [role=switch], [role=menuitem], [role=menuitemradio], [role=menuitemcheckbox], [role=checkbox], [role=radio], [role=option], '
      + ['btn', 'button', 'link', 'cta', 'chip', 'tab', 'toggle', 'pill'].map((k) => `a[class*="${k}"]`).join(', ');
    const inScope = (el) => {
      if (scope === 'admin') {
        const w = el.closest('#wpbody-content');
        if (!w || el.closest('#screen-meta, #screen-meta-links')) return false; // WP core screen chrome
        const notice = el.closest('.notice, .updated, .error, .update-nag');
        return !notice || /mvs-|mediaverse/.test(notice.className);
      }
      if (el.closest('#wpadminbar')) return false;
      if (el.closest('[class*="wp-block-mvs"]')) return true;
      for (let n = el; n && n !== document.body; n = n.parentElement) {
        if ([...n.classList].some((c) => c.startsWith('mvs-'))) return true; // shortcodes, templates, dialogs, popovers
      }
      return false;
    };
    let i = 0;
    for (const el of document.querySelectorAll(Q)) {
      if (!inScope(el)) continue;
      if (!el.checkVisibility({ opacityProperty: true, visibilityProperty: true })) continue;
      const r = el.getBoundingClientRect();
      if (r.width < 4 || r.height < 4) continue;
      const cs = getComputedStyle(el);
      if (el.tagName === 'INPUT' && /^(checkbox|radio)$/.test(el.type) && cs.appearance !== 'none') continue; // UA-drawn
      if (el.tagName === 'INPUT' && /^(range|color|file|image)$/.test(el.type)) continue;
      // Visually hidden pattern (clip / 1px).
      if (cs.clip === 'rect(0px, 0px, 0px, 0px)' || cs.clipPath === 'inset(50%)') continue;
      el.setAttribute('data-mvc', String(i++));
    }
    document.querySelectorAll('[data-mvc-anc]').forEach((n) => n.removeAttribute('data-mvc-anc'));
    document.querySelectorAll('[data-mvc]').forEach((el) => { for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) a.setAttribute('data-mvc-anc', ''); });
    return i;
  }

  const out = [];
  for (const el of document.querySelectorAll('[data-mvc]')) {
    const cs = getComputedStyle(el);
    const op = opacityOf(el);
    const surf = bgUnder(el, false);
    const isField = /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) && !/^(button|submit|reset)$/.test(el.type || '');
    const fillA = alphaOf(cs.backgroundColor);
    const fill = fillA > 0 ? over(surf, cs.backgroundColor, op) : surf;
    const fillR = ratio(fill, surf);
    const parts = [];
    if (fillA > 0) parts.push({ k: 'fill', c: cs.backgroundColor, r: fillR });
    for (const s of ['top', 'right', 'bottom', 'left']) {
      const w = parseFloat(cs[`border-${s}-width`] || cs.getPropertyValue(`border-${s}-width`));
      const st = cs.getPropertyValue(`border-${s}-style`);
      const col = cs.getPropertyValue(`border-${s}-color`);
      if (w > 0 && st !== 'none' && st !== 'hidden' && alphaOf(col) > 0) {
        parts.push({ k: 'border-' + s, c: col, r: ratio(over(surf, col, op * alphaOf(col) / alphaOf(col)), surf), w });
      }
    }
    const bs = cs.boxShadow;
    if (bs && bs !== 'none') {
      // Split on commas that are not inside parentheses.
      const shadows = bs.split(/,(?![^(]*\))/);
      for (const sh of shadows) {
        const m = sh.match(/(rgba?\([^)]*\)|color\([^)]*\)|oklab\([^)]*\)|oklch\([^)]*\)|#[0-9a-f]+)/i);
        if (!m) continue;
        const nums = sh.replace(m[0], '').replace('inset', '').trim().split(/\s+/).map(parseFloat);
        const [x = 0, y = 0, blur = 0, spread = 0] = nums;
        if (spread > 0 || ((x || y) && blur === 0)) {
          const inset = /inset/.test(sh);
          const base = inset ? fill : surf;
          parts.push({ k: inset ? 'inset-shadow' : 'shadow-ring', c: m[0], r: ratio(over(base, m[0], op), inset ? fill : surf) });
        }
      }
    }
    const ow = parseFloat(cs.outlineWidth);
    if (ow > 0 && cs.outlineStyle !== 'none' && alphaOf(cs.outlineColor) > 0) {
      parts.push({ k: 'outline', c: cs.outlineColor, r: ratio(over(surf, cs.outlineColor, op), surf) });
    }
    // A field that draws nothing itself may sit in a wrapper that draws the
    // box (search pills). Use the nearest of two ancestors that has a border
    // and hugs the field.
    if (isField && parts.every((p) => p.k === 'fill' && p.r < 1.1)) {
      const fr = el.getBoundingClientRect();
      for (let a = el.parentElement, d = 0; a && d < 2; a = a.parentElement, d++) {
        const acs = getComputedStyle(a);
        const ar = a.getBoundingClientRect();
        // The tag box grows with its wrapped pills and still is the field's edge.
        if (ar.height > fr.height * 2 + 24 && !a.matches('.mvs-tag-pills')) break;
        const asurf = bgUnder(a, false);
        for (const s of ['top', 'right', 'bottom', 'left']) {
          const w = parseFloat(acs.getPropertyValue(`border-${s}-width`));
          const col = acs.getPropertyValue(`border-${s}-color`);
          if (w > 0 && !/none|hidden/.test(acs.getPropertyValue(`border-${s}-style`)) && alphaOf(col) > 0) parts.push({ k: 'wrap-border-' + s, c: col, r: ratio(over(asurf, col, op), asurf) });
        }
        if (parts.length) break;
      }
    }
    const best = parts.reduce((m, p) => Math.max(m, p.r), 0);
    const drawsEdge = parts.some((p) => p.k !== 'fill');
    // A rule on ONE side only (a list-row separator, a tab underline) divides rows; it is
    // not the control's boundary. Such a control is identified by its text, like a link.
    // Never for a button or field: a lone bottom border there IS its only edge.
    const buttonLike = el.tagName === 'BUTTON' || el.getAttribute('role') === 'button' || el.tagName === 'INPUT';
    const sides = parts.filter((p) => p.k.startsWith('border-'));
    const dividerOnly = !isField && !buttonLike && sides.length === 1 && parts.every((p) => p.k.startsWith('border-') || (p.k === 'fill' && p.r < 1.1));
    // An icon-only button (no text of its own) whose border slot is transparent and whose
    // fill does not reach 3:1 has no visible shape: its icon alone must carry 1.4.11.
    const hiddenEdge = ['top', 'right', 'bottom', 'left'].some((s) => parseFloat(cs.getPropertyValue(`border-${s}-width`)) > 0 && alphaOf(cs.getPropertyValue(`border-${s}-color`)) === 0);
    let nonText = 'pass';
    if (dividerOnly && sides[0].r < 3) nonText = 'divider';
    else if (parts.length === 0) nonText = isField ? 'fail-no-boundary' : 'text-only';
    else if (best < 3) nonText = (drawsEdge || isField) ? 'fail' : 'fill-only';

    // Disclosure indicator (summary, [aria-expanded]): the chevron is a graphical object
    // (3:1 against the control's fill). Read a child svg, a drawn ::before/::after, or a
    // visible ::marker. None found is reported, not failed.
    let indicator = null;
    if (el.tagName === 'SUMMARY' || el.hasAttribute('aria-expanded')) {
      const cands = [];
      const svg = el.querySelector('svg');
      if (svg && svg.checkVisibility()) { const scs = getComputedStyle(svg); cands.push(scs.stroke && scs.stroke !== 'none' && !/url/.test(scs.stroke) ? scs.stroke : scs.color); }
      for (const pe of ['::before', '::after', '::marker']) {
        const pcs = getComputedStyle(el, pe);
        if (pe === '::marker' ? (el.tagName !== 'SUMMARY' || cs.display !== 'list-item' || pcs.content === 'none') : (!pcs.content || pcs.content === 'none' || pcs.display === 'none')) continue;
        const bw = ['top', 'right', 'bottom', 'left'].find((s) => parseFloat(pcs.getPropertyValue(`border-${s}-width`)) > 0 && alphaOf(pcs.getPropertyValue(`border-${s}-color`)) > 0);
        cands.push(bw ? pcs.getPropertyValue(`border-${bw}-color`) : (alphaOf(pcs.backgroundColor) > 0 && pcs.content === '""' ? pcs.backgroundColor : pcs.color));
      }
      indicator = cands.length ? cands.map((c) => ratio(over(fill, c, op), fill)).reduce((m, r) => Math.max(m, r), 0) : 'none';
      if (typeof indicator === 'number' && indicator < 3 && nonText === 'pass') nonText = 'fail-indicator';
    }

    // Text runs.
    const texts = [];
    if (!isField) {
      const seen = new Set();
      const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      let n;
      while ((n = walker.nextNode())) {
        if (!n.textContent.trim()) continue;
        const pe = n.parentElement;
        if (!pe || seen.has(pe)) continue;
        if (!pe.checkVisibility({ opacityProperty: true, visibilityProperty: true })) continue;
        const pr = pe.getBoundingClientRect();
        const pcsc = getComputedStyle(pe);
        if (pr.width <= 2 || pr.height <= 2 || pcsc.clip === 'rect(0px, 0px, 0px, 0px)' || pcsc.clipPath === 'inset(50%)') continue; // screen-reader-only text
        seen.add(pe);
        const pcs = getComputedStyle(pe);
        const bg = bgUnder(pe, true);
        const fg = over(bg, pcs.color, opacityOf(pe));
        const size = parseFloat(pcs.fontSize);
        const bold = parseInt(pcs.fontWeight, 10) >= 700;
        const large = size >= 24 || (bold && size >= 18.66);
        texts.push({ r: ratio(fg, bg), need: large ? 3 : 4.5, fg: hex(fg), bg: hex(bg), text: n.textContent.trim().slice(0, 30) });
      }
      if (el.tagName === 'INPUT' && el.value) {
        const fg = over(fill, cs.color, op);
        texts.push({ r: ratio(fg, fill), need: 4.5, fg: hex(fg), bg: hex(fill), text: el.value.slice(0, 30) });
      }
    }
    // Icon-only button (no visible text): WCAG 1.4.11 treats the icon as the graphical object,
    // so it must reach 3:1 against the fill; its ratio is reported for every such button. With no
    // icon and no visible edge (transparent border, fill under 3:1), nothing shows the control.
    let icon = null;
    // A control that shows an image (a media tile) is identified by that image.
    const img = el.querySelector('img');
    const hasImg = !!(img && img.checkVisibility() && img.getBoundingClientRect().width >= 16);
    if (buttonLike && !isField && !texts.length && !(el.value || '').trim()) {
      const svg = el.querySelector('svg');
      if (svg && svg.checkVisibility()) {
        const scs = getComputedStyle(svg);
        const c = scs.stroke && scs.stroke !== 'none' && !/url/.test(scs.stroke) ? scs.stroke : (scs.fill && scs.fill !== 'none' && !/url/.test(scs.fill) ? scs.fill : scs.color);
        icon = ratio(over(fill, c, op * parseFloat(scs.opacity || 1)), fill);
      }
      if (nonText !== 'fail-indicator') {
        if (icon !== null && icon < 3) nonText = 'fail-icon';
        else if (icon === null && !hasImg && hiddenEdge && best < 3) nonText = 'fail-transparent-edge';
      }
    }
    const worstText = texts.reduce((m, t) => (!m || t.r / t.need < m.r / m.need ? t : m), null);
    const sig = [cs.outlineStyle, cs.outlineWidth, cs.outlineColor, cs.boxShadow, cs.borderTopColor, cs.borderBottomColor, cs.borderBottomWidth, cs.backgroundColor, cs.color, cs.textDecorationLine,
      // A field whose box draws the ring (:focus-within on the tag box, the search pill).
      ...[el.parentElement, el.parentElement && el.parentElement.parentElement].filter(Boolean).map((a) => { const x = getComputedStyle(a); return x.outlineStyle + x.outlineColor + x.boxShadow + x.borderTopColor; })].join('|');
    out.push({
      i: +el.getAttribute('data-mvc'),
      sig,
      focusable: el.tabIndex >= 0,
      exempt: args.exempt.some((s) => el.matches(s)),
      sel: sel(el),
      label: label(el),
      disabled: el.disabled === true || el.getAttribute('aria-disabled') === 'true' || el.classList.contains('disabled'),
      surf: hex(surf),
      fill: hex(fill),
      parts: parts.map((p) => `${p.k}:${p.r}`).join(' '),
      best,
      nonText,
      indicator,
      icon,
      text: worstText,
      textFail: !!(worstText && worstText.r < worstText.need),
    });
  }
  return out;
};

const mvcCrawl = async (shared, opts = {}) => {
  const BASE = opts.base || 'http://buddynext.local';
  const as = opts.as || 'admin';
  // Own context: the shared MCP tab may be driven by another session.
  const ctx = await shared.context().browser().newContext();
  // QA environment switches (bin/qa-env-mu-plugin.php): { mvs_qa_theme: 'twentytwentyfive', mvs_qa_stock: '1', ... }.
  if (opts.cookies) await ctx.addCookies(Object.entries(opts.cookies).map(([name, value]) => ({ name, value: String(value), url: BASE })));
  const page = await ctx.newPage();
  if (as !== 'guest') await page.goto(BASE + '/?autologin=' + encodeURIComponent(as === 'admin' ? '1' : as), { waitUntil: 'load' });
  const darkMissing = [];
  const set = opts.set || 'front';
  const urls = opts.urls || (set === 'admin' ? ADMIN : FRONT);
  const modes = opts.modes || (set === 'admin' ? ['light'] : ['light', 'dark']);
  const widths = opts.widths || [1280, 390];
  const states = opts.states || ['default', 'hover', 'focus-visible', 'active'];
  const cdp = await page.context().newCDPSession(page);
  const violations = [];
  const disabledInfo = [];
  const fillOnly = [];
  const noIndicator = new Set();
  const iconOnly = {};
  const exempt = new Set();
  const openerMissing = [];
  const pages = [];
  let controls = 0;
  for (const width of widths) {
    await page.setViewportSize({ width, height: width > 800 ? 900 : 844 });
    for (const path of urls) {
      for (const mode of modes) {
        let resp;
        const [url, act] = path.split('#');
        try {
          if (!page.url().startsWith(BASE)) await page.goto(BASE + '/');
          await page.evaluate((m) => { try { localStorage.setItem('bn_theme', m); localStorage.setItem('bx-color-mode', m); } catch (e) {} }, mode);
          resp = await page.goto(BASE + url, { waitUntil: 'load', timeout: 30000 });
        } catch (e) { pages.push({ path, mode, width, error: String(e).slice(0, 80) }); continue; }
        if (act) {
          const key = ACTIONS[url.replace(/\/$/, '').split('/').pop() + '-' + act] ? url.replace(/\/$/, '').split('/').pop() + '-' + act : act;
          let ok = !!ACTIONS[key];
          for (const step of ACTIONS[key] || []) {
            try {
              if (step.eval) { await page.evaluate(step.eval); continue; }
              if (step.follow) { await page.goto(await page.locator(step.follow).first().getAttribute('href'), { waitUntil: 'load' }); continue; }
              if (step.until) {
                for (let n = 0; n < (step.max || 30) && !(await page.locator(step.until).filter({ visible: true }).count()); n++) { await page.locator(step.next).filter({ visible: true }).first().click({ timeout: 5000 }); await page.waitForTimeout(400); }
                if (!(await page.locator(step.until).filter({ visible: true }).count())) throw new Error('until');
                continue;
              }
              const loc = page.locator(typeof step === 'string' ? step : step.check).filter({ visible: true }).first();
              // Site chrome fixed to the viewport (a cookie notice) can cover the opener at 390: centre it first.
              await loc.evaluate((el) => el.scrollIntoView({ block: 'center' })).catch(() => {});
              if (typeof step === 'string') await loc.click({ timeout: 5000 }); else await loc.check({ timeout: 5000 });
              await page.waitForTimeout(250);
            } catch (e) { ok = false; break; }
          }
          if (!ok) { openerMissing.push(`${path} ${mode} ${width}`); continue; }
        }
        // A route may redirect client-side after load (/search/ -> /activity/search/): settle once.
        const prep = async () => {
          await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important;caret-color:transparent!important}' });
          await page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); });
          await page.waitForTimeout(150);
          // A dialog or an opened panel (new folder) focuses its first field: blur it, after the
          // toggle handlers ran, so the rest state is measured at rest.
          await page.evaluate(() => { if (document.activeElement && document.activeElement !== document.body) document.activeElement.blur(); });
          return page.evaluate(IN_PAGE, { scope: set, tag: true, exempt: EXEMPT });
        };
        let n;
        try { n = await prep(); } catch (e) { await page.waitForLoadState('load'); await page.waitForTimeout(500); n = await prep(); }
        const finalUrl = page.url();
        if (mode === 'dark' && !(await page.evaluate(() => !!document.querySelector('[data-bn-theme="dark"], [data-bx-mode="dark"], [data-theme="dark"]')))) darkMissing.push(`${path} ${width}`);
        controls += n;
        pages.push({ path, final: finalUrl.replace(BASE, ''), status: resp && resp.status(), mode, width, controls: n });
        if (!n) continue;
        const sheets = {};
        const onSheet = (e) => { sheets[e.header.styleSheetId] = e.header.sourceURL || '(inline)'; };
        cdp.on('CSS.styleSheetAdded', onSheet);
        await cdp.send('DOM.enable');
        await cdp.send('CSS.enable');
        const { root } = await cdp.send('DOM.getDocument', { depth: -1 });
        const { nodeIds } = await cdp.send('DOM.querySelectorAll', { nodeId: root.nodeId, selector: '[data-mvc]' });
        // A hovered or pressed control hovers its ancestors too (a pill under the remove button).
        const { nodeIds: ancIds } = await cdp.send('DOM.querySelectorAll', { nodeId: root.nodeId, selector: '[data-mvc-anc]' });
        const restSig = {};
        // getDocument re-sends styleSheetAdded only on CSS.enable; give it a tick.
        await page.waitForTimeout(50);
        for (const state of states) {
          if (state !== 'default') await Promise.all(nodeIds.map((nodeId) => cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: state === 'focus-visible' ? ['focus', 'focus-visible'] : [state] }).catch(() => {})));
          if (state === 'hover' || state === 'active') await Promise.all(ancIds.map((nodeId) => cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [state] }).catch(() => {})));
          let recs = [];
          try { recs = await page.evaluate(IN_PAGE, { scope: set, tag: false, exempt: EXEMPT }); } catch (e) { pages.push({ path, mode, width, state, error: String(e).slice(0, 80) }); }
          for (const r of recs) {
            if (r.disabled) {
              if (state === 'default') disabledInfo.push({ path, mode, width, sel: r.sel, label: r.label, nonText: r.nonText, best: r.best, text: r.text && r.text.r });
              continue;
            }
            if (r.exempt) { exempt.add(r.sel); continue; }
            if (state === 'default') restSig[r.i] = r.sig;
            // Keyboard focus that changes nothing on the control: no focus indicator at all.
            if (state === 'focus-visible' && r.focusable && restSig[r.i] === r.sig) {
              violations.push({ path, mode, width, state, sel: r.sel, label: r.label, kind: 'no-focus-indicator', parts: '', text: null, rule: [] });
            }
            if (r.nonText === 'fill-only') fillOnly.push({ sel: r.sel, label: r.label, state, mode, width, best: r.best, path });
            const bad = [];
            if (/^fail/.test(r.nonText)) bad.push(r.nonText === 'fail' || r.nonText === 'fail-no-boundary' ? 'nonText' : r.nonText.replace('fail-', ''));
            if (state === 'default' && r.indicator === 'none') noIndicator.add(`${r.sel} "${r.label}"`);
            if (r.icon !== null) { const k = r.sel; iconOnly[k] = Math.min(iconOnly[k] ?? 99, r.icon); }
            if (r.textFail) bad.push('text');
            if (!bad.length) continue;
            const v = { path, mode, width, state, sel: r.sel, label: r.label, kind: bad.join('+'), icon: r.icon, surf: r.surf, fill: r.fill, parts: r.parts, best: r.best, text: r.text, rule: [] };
            // Winning rule for the failing property.
            try {
              const ms = await cdp.send('CSS.getMatchedStylesForNode', { nodeId: nodeIds[r.i] });
              const want = bad.every((k) => k === 'text')
                ? ['color', 'background-color', 'background']
                : ['border-color', 'border', 'border-top-color', 'border-top', 'border-inline-start-color', 'border-width', 'border-style', 'background-color', 'background', 'box-shadow'];
              const hits = [];
              for (const m of ms.matchedCSSRules || []) {
                const props = (m.rule.style.cssProperties || []).filter((p) => want.includes(p.name) && p.value && !p.disabled && p.implicit !== true);
                if (!props.length || m.rule.origin !== 'regular') continue;
                hits.push({ sheet: (sheets[m.rule.styleSheetId] || m.rule.styleSheetId).replace(BASE, '').replace(/\?.*$/, ''), line: m.rule.style.range ? m.rule.style.range.startLine + 1 : null, selector: m.rule.selectorList.text.slice(0, 120), decl: [...new Set(props.map((p) => `${p.name}: ${p.value.replace(/\s*!important/, '')}${p.important ? ' !important' : ''}`))].join('; ').slice(0, 200), important: props.some((p) => p.important) });
              }
              // Cascade order: last wins, !important beats normal.
              const imp = hits.filter((h) => h.important);
              v.rule = (imp.length ? imp : hits).slice(-2);
            } catch (e) { v.rule = [{ error: String(e).slice(0, 60) }]; }
            violations.push(v);
          }
          if (state !== 'default') await Promise.all(nodeIds.concat(state === 'hover' || state === 'active' ? ancIds : []).map((nodeId) => cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] }).catch(() => {})));
        }
        cdp.off('CSS.styleSheetAdded', onSheet);
        await cdp.send('CSS.disable');
      }
    }
  }
  await cdp.detach();
  await ctx.close();
  // Collapse identical (selector, kind, state, mode) across pages/widths.
  const groups = {};
  for (const v of violations) {
    const key = [v.sel, v.kind, v.state, v.mode].join('|');
    if (!groups[key]) groups[key] = { ...v, pages: new Set(), widths: new Set() };
    groups[key].pages.add(v.path);
    groups[key].widths.add(v.width);
  }
  const table = Object.values(groups).map((g) => ({ sel: g.sel, label: g.label, kind: g.kind, icon: g.icon, state: g.state, mode: g.mode, widths: [...g.widths].join('/'), pages: [...g.pages].slice(0, 3).join(' ') + (g.pages.size > 3 ? ` (+${g.pages.size - 3})` : ''), surf: g.surf, fill: g.fill, parts: g.parts, text: g.text ? `${g.text.r} (${g.text.fg} on ${g.text.bg}) "${g.text.text}"` : '', rule: g.rule }));
  const fo = {};
  for (const f of fillOnly) { const k = f.sel + '|' + f.state + '|' + f.mode; if (!fo[k] || f.best < fo[k].best) fo[k] = f; }
  return { as, darkMissing, fillOnly: Object.values(fo).map((f) => `${f.state}/${f.mode} ${f.sel} "${f.label}" fill ${f.best}`), set, modes, widths, states, pagesLoaded: pages.length, controlsMeasured: controls, violationCount: violations.length, exempt: [...exempt], openerMissing, noIndicator: [...noIndicator], iconOnly: Object.entries(iconOnly).map(([k, r]) => `${k} icon ${r}`), uniqueViolations: table.length, table, disabled: opts.showDisabled ? disabledInfo : disabledInfo.length, pages: opts.showPages ? pages : undefined };
};

// Kept on the browser context so later calls can run it without re-sending this file.
page.context().mvcCrawl = mvcCrawl;
return 'mvcCrawl installed';
}
