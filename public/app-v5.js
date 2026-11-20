const $ = selector => document.querySelector(selector);
const els = {
  cid: $("#cid"), connect: $("#connect"), demo: $("#demo"), message: $("#activity-status"),
  quarter: $("#quarter-name"), quarterOptions: [...document.querySelectorAll(".quarter-option")],
  previousQuarterName: $("#previous-quarter-name"), currentQuarterName: $("#current-quarter-name"),
  utcStart: $("#utc-start"), utcEnd: $("#utc-end"), localZone: $("#local-zone"), localStart: $("#local-start"), localEnd: $("#local-end"),
  total: $("#total-time"), sessions: $("#session-count"), regions: $("#region-count"), list: $("#regions"), label: $("#data-label")
};
const phoneLayout = window.matchMedia("(max-width: 734px)");
const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

/* ---------- Quarters ---------- */
function quarter(offset = 0) { const now = new Date(); let index = Math.floor(now.getUTCMonth() / 3) + offset; const year = now.getUTCFullYear() + Math.floor(index / 4); index = (index % 4 + 4) % 4; return { year, number: index + 1, start: new Date(Date.UTC(year, index * 3, 1)), end: new Date(Date.UTC(year, index * 3 + 3, 1)) }; }
function quarterKey(offset = 0) { const q = quarter(offset); return `${q.year}-Q${q.number}`; }
function dateText(date, zone) { return new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", year: "numeric", hour: "2-digit", minute: "2-digit", hourCycle: "h23", timeZone: zone }).format(date); }
function minutesText(value) { const total = Math.round(value); const hours = Math.floor(total / 60); const minutes = total % 60; return hours ? `${hours}h ${minutes}m` : `${minutes}m`; }
function selectedOffset() { return Number(document.querySelector(".quarter-option.is-selected")?.dataset.quarterOffset || 0); }
function setQuarter(offset = selectedOffset()) {
  const q = quarter(offset); const previous = quarter(-1); const current = quarter(0); const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  els.quarter.textContent = `Q${q.number} ${q.year}`;
  els.utcStart.textContent = dateText(q.start, "UTC"); els.utcEnd.textContent = dateText(q.end, "UTC");
  els.localZone.textContent = zone; els.localStart.textContent = dateText(q.start, zone); els.localEnd.textContent = dateText(q.end, zone);
  els.previousQuarterName.textContent = `Q${previous.number} ${previous.year}`; els.currentQuarterName.textContent = `Q${current.number} ${current.year}`;
}
function setMessage(text = "") { els.message.textContent = text; }

/* ---------- Results ---------- */
const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);
/** Share of the area within its own Division only, never of the overall total. */
function shareText(part, whole) { if (!whole) return "0%"; const value = part / whole * 100; return value >= 99.95 ? "100%" : value < 0.1 ? "<0.1%" : `${value.toFixed(1)}%`; }
const SEGMENT_COLORS = 8;

function divisionTile(group) {
  const areas = group.areas.map((area, index) => ({ ...area, color: index < SEGMENT_COLORS ? index + 1 : "other", share: group.minutes ? area.minutes / group.minutes * 100 : 0 }));
  const segments = areas.map(area => `<span class="share-segment" style="--share:${area.share};--seg:var(--c-${area.color})" title="${escapeHtml(area.name)} · ${shareText(area.minutes, group.minutes)}"></span>`).join("");
  const rows = areas.map(area => `<details class="area-row"><summary><span class="area-dot" style="--seg:var(--c-${area.color})"></span><span class="area-name">${escapeHtml(area.name)}</span><span class="area-share">${shareText(area.minutes, group.minutes)}</span><span class="area-time">${minutesText(area.minutes)}</span><span class="icon icon-chevron" aria-hidden="true"></span></summary><div class="seat-list">${area.seats.map(seat => `<div class="seat-row"><span>${escapeHtml(seat.name)}</span><strong>${minutesText(seat.minutes)}</strong></div>`).join("")}</div></details>`).join("");
  return `<article class="tile division-tile"><header class="division-head"><div><p class="tile-kicker">Division</p><h3>${escapeHtml(group.name)}</h3></div><strong class="division-time">${minutesText(group.minutes)}</strong></header><div class="share-bar" role="img" aria-label="${escapeHtml(group.name)} time by area">${segments}</div><div class="area-list">${rows}</div></article>`;
}

function render(summary, label) {
  els.total.textContent = minutesText(summary.totalMinutes); els.sessions.textContent = summary.recordCount; els.regions.textContent = summary.areaCount; els.label.textContent = label;
  els.list.innerHTML = summary.divisions.length ? summary.divisions.map(divisionTile).join("") : `<div class="tile empty-state">No ATC time found in this UTC quarter.</div>`;
  requestAnimationFrame(() => requestAnimationFrame(() => els.list.querySelectorAll(".share-bar").forEach(bar => bar.classList.add("is-drawn"))));
}
async function fetchSummary(cid, quarterParam, signal) { const response = await fetch(`/api.php?cid=${encodeURIComponent(cid)}&quarter=${quarterParam}`, { cache: "no-store", signal }); const type = response.headers.get("content-type") || ""; if (!type.includes("application/json")) throw new Error(`The activity service returned an error (${response.status}). Try again later.`); const payload = await response.json(); if (!response.ok) throw new Error(payload.error || payload.detail || `Activity service returned ${response.status}.`); return payload; }
/* Only the latest request may render: switching quarters cancels the one still in flight,
   so a slow answer for the other quarter can never overwrite the selected one. */
let activeRequest = null;
function setLoading(loading) { els.connect.disabled = loading; els.list.classList.toggle("is-loading", loading); els.list.setAttribute("aria-busy", String(loading)); }
async function load(cid = els.cid.value.trim(), { scroll = false } = {}) {
  if (!/^\d{3,10}$/.test(cid)) { setMessage("Enter a valid VATSIM CID."); return; }
  activeRequest?.abort();
  const request = new AbortController(); activeRequest = request;
  const timeout = window.setTimeout(() => request.abort("timeout"), 30000);
  setQuarter(); setMessage("Loading activity…"); setLoading(true);
  try {
    const summary = await fetchSummary(cid, quarterKey(selectedOffset()), request.signal);
    if (activeRequest !== request) return;
    render(summary, `CID ${cid}`); setMessage(""); if (scroll) revealResults();
  } catch (error) {
    if (activeRequest !== request) return;
    setMessage(request.signal.aborted ? "The request timed out. Try again." : error.message || "Could not load activity. Try again later.");
  } finally {
    window.clearTimeout(timeout);
    if (activeRequest === request) { activeRequest = null; setLoading(false); }
  }
}
function sample() {
  const random = () => 30 + Math.floor(Math.random() * 211);
  const [memphis, losAngeles, newYork, toronto] = [random(), random(), random(), random()];
  const usa = memphis + losAngeles + newYork;
  render({ totalMinutes: usa + toronto, recordCount: 4, areaCount: 4, divisions: [
    { name: "VATUSA", minutes: usa, areas: [{ name: "ARTCC · Memphis", minutes: memphis, seats: [{ name: "MEM_22_CTR", minutes: memphis }] }, { name: "ARTCC · Los Angeles", minutes: losAngeles, seats: [{ name: "LAX_25_CTR", minutes: losAngeles }] }, { name: "ARTCC · New York", minutes: newYork, seats: [{ name: "NY_CAM_APP", minutes: newYork }] }].sort((a, b) => b.minutes - a.minutes) },
    { name: "VATCAN", minutes: toronto, areas: [{ name: "FIR · Toronto", minutes: toronto, seats: [{ name: "TOR_CTR", minutes: toronto }] }] }
  ].sort((a, b) => b.minutes - a.minutes) }, "Sample data");
  activeRequest?.abort(); activeRequest = null; setLoading(false);
  setMessage(""); revealResults();
}
function revealResults() { if (phoneLayout.matches || window.scrollY < 80) $("#activity").scrollIntoView({ behavior: reduceMotion.matches ? "auto" : "smooth" }); }

/* ---------- Theme (same behaviour as jurina.org) ---------- */
function initTheme() {
  const button = $(".theme-button"); const meta = $('meta[name="theme-color"]'); const system = window.matchMedia("(prefers-color-scheme: dark)");
  const read = () => { try { return localStorage.getItem("theme") || "system"; } catch { return "system"; } };
  const apply = preference => { const dark = preference === "dark" || (preference === "system" && system.matches); document.documentElement.dataset.theme = dark ? "dark" : "light"; button.querySelector(".theme-icon").innerHTML = `<span class="icon icon-${dark ? "sun" : "moon"}"></span>`; meta.content = dark ? "#000000" : "#f5f5f7"; };
  apply(read());
  button.addEventListener("click", () => { const next = document.documentElement.dataset.theme === "dark" ? "light" : "dark"; try { localStorage.setItem("theme", next); } catch {} apply(next); });
  system.addEventListener("change", () => { if (read() === "system") apply("system"); });
}

/* ---------- Phone menu: apple.com globalnav sheet ---------- */
let setMenu = () => {};
function initMobileMenu() {
  const button = $(".menu-button"); const menu = $(".mobile-menu"); let open = false;
  setMenu = next => { if (next === open) return; open = next; document.body.classList.toggle("menu-open", open); button.setAttribute("aria-expanded", String(open)); button.setAttribute("aria-label", open ? "Close menu" : "Menu"); menu.setAttribute("aria-hidden", String(!open)); menu.toggleAttribute("inert", !open); button.querySelectorAll(open ? ".menu-anim-open" : ".menu-anim-close").forEach(animation => animation.beginElement()); };
  button.addEventListener("click", () => setMenu(!open));
  menu.querySelectorAll("[data-close-menu]").forEach(link => link.addEventListener("click", () => setMenu(false)));
  phoneLayout.addEventListener("change", () => { if (!phoneLayout.matches) setMenu(false); });
}

/* ---------- Modals ---------- */
function initModals() {
  let opener = null;
  const close = modal => { modal.classList.remove("is-open"); document.body.classList.remove("modal-open"); window.setTimeout(() => { modal.hidden = true; }, reduceMotion.matches ? 0 : 280); opener?.focus(); };
  document.querySelectorAll("[data-modal]").forEach(button => button.addEventListener("click", () => { setMenu(false); opener = button; const modal = document.getElementById(button.dataset.modal); modal.hidden = false; document.body.classList.add("modal-open"); requestAnimationFrame(() => requestAnimationFrame(() => modal.classList.add("is-open"))); modal.querySelector(".modal-close").focus(); }));
  document.querySelectorAll("[data-close-modal]").forEach(button => button.addEventListener("click", () => close(button.closest(".modal"))));
  document.addEventListener("keydown", event => { if (event.key !== "Escape") return; setMenu(false); document.querySelectorAll(".modal:not([hidden])").forEach(close); });
}

/* ---------- Fade-up on scroll ---------- */
function initReveal() {
  const items = document.querySelectorAll(".reveal");
  if (!("IntersectionObserver" in window) || reduceMotion.matches) return;
  document.documentElement.classList.add("reveal-ready");
  const observer = new IntersectionObserver(entries => entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add("is-visible"); observer.unobserve(entry.target); } }), { rootMargin: "0px 0px -8% 0px" });
  items.forEach(item => observer.observe(item));
}

let displayedCurrentQuarter = quarterKey();
function syncQuarter() { const current = quarterKey(); if (current === displayedCurrentQuarter) return; displayedCurrentQuarter = current; setQuarter(); if (selectedOffset() === 0 && /^\d{3,10}$/.test(els.cid.value.trim())) load(); }

els.connect.addEventListener("click", () => load(undefined, { scroll: true }));
els.demo.addEventListener("click", sample);
function selectQuarterButton(button) { els.quarterOptions.forEach(option => { option.classList.toggle("is-selected", option === button); option.setAttribute("aria-pressed", String(option === button)); }); button.parentElement.dataset.selected = String(els.quarterOptions.indexOf(button)); }
els.quarterOptions.forEach(button => button.addEventListener("click", () => { if (button.classList.contains("is-selected")) return; selectQuarterButton(button); setQuarter(Number(button.dataset.quarterOffset)); if (/^\d{3,10}$/.test(els.cid.value.trim())) load(); }));
els.cid.addEventListener("keydown", event => { if (event.key === "Enter") load(undefined, { scroll: true }); });
selectQuarterButton(document.querySelector(".quarter-option.is-selected"));
setQuarter(); window.setInterval(syncQuarter, 60000);
initTheme(); initMobileMenu(); initModals(); initReveal();
void document.body.offsetWidth;
document.documentElement.classList.remove("is-booting");
