/* Lançamentos do mês, agrupados por dia, com busca e filtros. */
import { estado, cadastros } from "../estado.js";
import { api } from "../api.js";
import { esc, valor, nomeMes, dataLonga } from "../util.js";
import { icone } from "../icones.js";
import { abrirLancamento } from "../gaveta.js";

let filtros = { q: "", tipo: "", conta: "", categoria: "" };
let pedido = 0;

export async function mostrar(tela) {
  // Filtros vindos do endereço (#/lancamentos?conta=2)
  const params = new URLSearchParams(location.hash.split("?")[1] || "");
  for (const k of ["conta", "categoria", "tipo"]) if (params.has(k)) filtros[k] = params.get(k);

  const { contas, categorias } = await cadastros();
  if (!tela.querySelector(".tela-lanc")) {
    tela.innerHTML = `<div class="tela-lanc">
      <header class="cabeca"><h1 data-titulo></h1><p class="resumo-linha" data-totais></p></header>
      <div class="filtros-lanc">
        <label class="busca">${icone("search", 16)}<input type="search" name="q" placeholder="Buscar descrição" value="${esc(filtros.q)}" aria-label="Buscar"></label>
        <div class="chips" role="group" aria-label="Tipo">
          ${[["", "Tudo"], ["despesa", "Despesas"], ["receita", "Receitas"], ["transferencia", "Transferências"]].map(([v, r]) =>
            `<button class="chip" data-tipo="${v}" aria-pressed="${filtros.tipo === v}">${r}</button>`).join("")}
        </div>
        <select name="conta" aria-label="Conta"><option value="">Todas as contas</option>${contas.map((c) =>
          `<option value="${c.id}"${String(c.id) === filtros.conta ? " selected" : ""}>${esc(c.nome)}</option>`).join("")}</select>
        <select name="categoria" aria-label="Categoria"><option value="">Todas as categorias</option><option value="sem"${filtros.categoria === "sem" ? " selected" : ""}>Sem categoria</option>${categorias.filter((c) => !c.pai_id).map((c) =>
          `<option value="${c.id}"${String(c.id) === filtros.categoria ? " selected" : ""}>${esc(c.nome)}</option>`).join("")}</select>
      </div>
      <div data-lista class="lista-dias"></div>
    </div>`;
    ligarFiltros(tela);
  }
  await carregar(tela);
}

async function carregar(tela) {
  const meu = ++pedido;
  const lista = tela.querySelector("[data-lista]");
  lista.classList.add("carregando");
  const r = await api.get("lancamentos", { mes: estado.mes, ...filtros });
  if (meu !== pedido) return;
  lista.classList.remove("carregando");
  tela.querySelector("[data-titulo]").textContent = `Lançamentos de ${nomeMes(r.mes)}`;
  const t = r.totais;
  tela.querySelector("[data-totais]").innerHTML = r.itens.length
    ? `${r.itens.length} ${r.itens.length === 1 ? "lançamento" : "lançamentos"} · entrou ${valor(t.receitas, "receita")} · saiu ${valor(t.despesas, "despesa")}`
    : "";
  lista.innerHTML = r.itens.length ? porDia(r.itens) : vazio();
  lista.querySelectorAll("[data-id]").forEach((li) => li.addEventListener("click", (ev) => {
    if (ev.target.closest("[data-pagar]")) return;
    abrirLancamento(r.itens.find((l) => String(l.id) === li.dataset.id));
  }));
  lista.querySelectorAll("[data-pagar]").forEach((b) => b.addEventListener("click", async () => {
    b.disabled = true;
    await api.post("lancamentos", { acao: "pago", id: b.dataset.pagar, pago: true });
    carregar(tela);
  }));
  lista.querySelector("[data-novo]")?.addEventListener("click", () => abrirLancamento());
}

function vazio() {
  const filtrado = Object.values(filtros).some(Boolean);
  return `<div class="vazio-grande"><p>${filtrado ? "Nada encontrado com esses filtros." : `Nenhum lançamento em ${nomeMes(estado.mes)}.`}</p>
    ${filtrado ? "" : `<button class="botao primario" data-novo>${icone("plus", 16)}Lançar</button>`}</div>`;
}

function porDia(itens) {
  const dias = new Map();
  for (const l of itens) {
    const d = l.data.slice(0, 10);
    if (!dias.has(d)) dias.set(d, []);
    dias.get(d).push(l);
  }
  return [...dias].map(([dia, ls]) => {
    const saldo = ls.reduce((s, l) => s + (l.tipo === "receita" ? +l.valor : l.tipo === "despesa" ? -l.valor : 0), 0);
    return `<section class="dia">
      <h2><span>${esc(dataLonga(dia))}</span>${saldo ? valor(Math.abs(saldo), saldo > 0 ? "receita" : "despesa") : ""}</h2>
      <ul>${ls.map(linha).join("")}</ul>
    </section>`;
  }).join("");
}

function linha(l) {
  const cor = l.tipo === "transferencia" ? "#5A6962" : l.categoria_cor || "#8A8F98";
  const ic = l.tipo === "transferencia" ? "arrow-left-right" : l.categoria_icone || "circle";
  const onde = l.tipo === "transferencia" ? `${esc(l.conta_nome)} → ${esc(l.destino_nome)}` : `${esc(l.categoria_nome || "Sem categoria")} · ${esc(l.conta_nome)}`;
  const selos = [
    l.parcelas ? `<span class="selo">${l.parcela}/${l.parcelas}</span>` : "",
    !Number(l.pago) ? `<span class="selo aberto">${l.tipo === "receita" ? "a receber" : "a pagar"}</span>` : "",
  ].join("");
  return `<li data-id="${l.id}" tabindex="0" role="button" style="--c:${esc(cor)}">
    <span class="lanc-icone">${icone(ic, 16)}</span>
    <span class="lanc-texto"><strong>${esc(l.descricao)}${selos}</strong><small>${onde}</small></span>
    ${!Number(l.pago) ? `<button class="marcar" data-pagar="${l.id}" title="Marcar como ${l.tipo === "receita" ? "recebido" : "pago"}">${icone("check", 14)}</button>` : ""}
    ${valor(l.valor, l.tipo === "transferencia" ? "" : l.tipo)}
  </li>`;
}

function ligarFiltros(tela) {
  let t;
  const atualizar = () => carregar(tela);
  tela.querySelector("[name=q]").addEventListener("input", (ev) => { filtros.q = ev.target.value; clearTimeout(t); t = setTimeout(atualizar, 250); });
  tela.querySelectorAll("[data-tipo]").forEach((b) => b.addEventListener("click", () => {
    filtros.tipo = b.dataset.tipo;
    tela.querySelectorAll("[data-tipo]").forEach((x) => x.setAttribute("aria-pressed", x === b));
    atualizar();
  }));
  for (const nome of ["conta", "categoria"]) {
    tela.querySelector(`[name=${nome}]`).addEventListener("change", (ev) => { filtros[nome] = ev.target.value; atualizar(); });
  }
  tela.addEventListener("keydown", (ev) => {
    const li = ev.target.closest("li[data-id]");
    if (li && (ev.key === "Enter" || ev.key === " ") && ev.target === li) { ev.preventDefault(); li.click(); }
  });
}

/** Ao sair da tela, os filtros vindos do endereço não ficam presos. */
window.addEventListener("hashchange", () => {
  if (!location.hash.startsWith("#/lancamentos")) filtros = { q: "", tipo: "", conta: "", categoria: "" };
});
