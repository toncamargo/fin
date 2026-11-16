/* Funções auxiliares: texto seguro, dinheiro, datas, armazenamento local. */

export const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

const fmtBRL = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });
export const brl = (v) => fmtBRL.format(Number(v || 0));
/** Valor com cor/sinal conforme o tipo. */
export function valor(v, tipo = "") {
  // Com tipo, o sinal vem do tipo (o número vai sem sinal); sem tipo, mostra o sinal do próprio número
  const sinal = tipo === "despesa" ? "\u2212\u2009" : tipo === "receita" ? "+\u2009" : "";
  return `<span class="valor ${tipo}">${sinal}${esc(brl(tipo ? Math.abs(v) : v))}</span>`;
}

const MESES = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"];
export const mesAtual = () => hoje().slice(0, 7);
export function hoje() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}
export function somarMes(mes, n) {
  const [a, m] = mes.split("-").map(Number);
  const d = new Date(a, m - 1 + n, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}
export const nomeMes = (mes) => MESES[Number(mes.slice(5, 7)) - 1];
export const nomeMesCurto = (mes) => nomeMes(mes).slice(0, 3);
/** "2026-11-05" → "05/11" (ou "05/11/2025" se for de outro ano) */
export function dataCurta(iso) {
  if (!iso) return "";
  const [a, m, d] = String(iso).slice(0, 10).split("-");
  return a === String(new Date().getFullYear()) ? `${d}/${m}` : `${d}/${m}/${a}`;
}
/** "2026-11-05" → "qua, 5 nov" */
export function dataLonga(iso) {
  const d = new Date(String(iso).slice(0, 10) + "T12:00:00");
  return d.toLocaleDateString("pt-BR", { weekday: "short", day: "numeric", month: "short" }).replace(/\./g, "");
}

export function ler(chave, padrao) {
  try { const v = localStorage.getItem("fin:" + chave); return v === null ? padrao : JSON.parse(v); } catch { return padrao; }
}
export function gravar(chave, valor) {
  try { localStorage.setItem("fin:" + chave, JSON.stringify(valor)); } catch { /* sem armazenamento: tudo bem */ }
}

/** Aviso rápido no rodapé. */
let timerAviso;
export function avisar(texto, tipo = "") {
  const el = document.getElementById("aviso");
  el.textContent = texto;
  el.className = "aviso " + tipo;
  el.hidden = false;
  clearTimeout(timerAviso);
  timerAviso = setTimeout(() => (el.hidden = true), tipo === "erro" ? 5000 : 2600);
}
