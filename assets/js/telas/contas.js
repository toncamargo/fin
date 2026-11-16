/* Contas e cartões: saldos, faturas, limite e categorias. */
import { cadastros } from "../estado.js";
import { api } from "../api.js";
import { esc, brl, valor, dataCurta, nomeMes } from "../util.js";
import { icone } from "../icones.js";
import { abrirConta, abrirLancamento } from "../gaveta.js";

const TIPO = { corrente: "Conta corrente", poupanca: "Poupança", carteira: "Carteira", investimento: "Investimentos", cartao: "Cartão de crédito" };

export async function mostrar(tela) {
  const { contas, categorias } = await cadastros();
  const ativas = contas.filter((c) => c.ativo);
  const comuns = ativas.filter((c) => c.tipo !== "cartao");
  const cartoes = ativas.filter((c) => c.tipo === "cartao");
  const arquivadas = contas.filter((c) => !c.ativo);
  const total = comuns.reduce((s, c) => s + Number(c.saldo || 0), 0);

  tela.innerHTML = `
  <header class="cabeca cabeca-acoes"><div><h1>Contas e cartões</h1>
    <p class="resumo-linha">Saldo somado das contas: ${valor(total)}</p></div>
    <div class="acoes"><button class="botao" data-nova="corrente">${icone("wallet", 16)}Nova conta</button>
      <button class="botao" data-nova="cartao">${icone("credit-card", 16)}Novo cartão</button></div></header>

  <section class="bloco">
    <div class="bloco-topo"><h2>Contas</h2></div>
    ${comuns.length ? `<ul class="cartoes-conta">${comuns.map((c) => `
      <li style="--c:${esc(c.cor || "#2C5E4A")}" data-conta="${c.id}" tabindex="0" role="button">
        <span class="tipo">${TIPO[c.tipo]}${c.instituicao && c.instituicao !== c.nome ? " · " + esc(c.instituicao) : ""}</span>
        <strong>${esc(c.nome)}</strong>
        ${valor(c.saldo, c.saldo < 0 ? "despesa" : "")}
      </li>`).join("")}</ul>` : `<p class="vazio">Nenhuma conta ainda.</p>`}
  </section>

  <section class="bloco">
    <div class="bloco-topo"><h2>Cartões</h2></div>
    ${cartoes.length ? `<ul class="cartoes-cartao">${cartoes.map(cartao).join("")}</ul>` : `<p class="vazio">Nenhum cartão ainda.</p>`}
  </section>

  <section class="bloco">
    <div class="bloco-topo"><h2>Categorias</h2><button class="link" data-nova-cat>Nova categoria</button></div>
    <div class="cats">
      ${["despesa", "receita"].map((tipo) => `<div><h3>${tipo === "despesa" ? "Despesas" : "Receitas"}</h3><ul class="lista-chips">
        ${categorias.filter((c) => c.tipo === tipo && !c.pai_id).map((c) => `<li style="--c:${esc(c.cor || "#8A8F98")}"><button data-cat="${c.id}">${icone(c.icone || "circle", 14)}${esc(c.nome)}</button></li>`).join("")}
      </ul></div>`).join("")}
    </div>
  </section>

  ${arquivadas.length ? `<section class="bloco"><div class="bloco-topo"><h2>Arquivadas</h2></div>
    <ul class="lista-contas">${arquivadas.map((c) => `<li><button class="link" data-conta="${c.id}">${esc(c.nome)}</button><small>${TIPO[c.tipo]}</small></li>`).join("")}</ul></section>` : ""}`;

  tela.querySelectorAll("[data-nova]").forEach((b) => b.addEventListener("click", () => abrirConta(null, { tipo: b.dataset.nova })));
  tela.querySelectorAll("[data-conta]").forEach((el) => {
    const abrir = () => abrirConta(contas.find((c) => String(c.id) === el.dataset.conta));
    el.addEventListener("click", (ev) => { if (!ev.target.closest("[data-pagar-fatura]")) abrir(); });
    el.addEventListener("keydown", (ev) => { if ((ev.key === "Enter" || ev.key === " ") && ev.target === el) { ev.preventDefault(); abrir(); } });
  });
  tela.querySelectorAll("[data-pagar-fatura]").forEach((b) => b.addEventListener("click", () => {
    const c = contas.find((x) => String(x.id) === b.dataset.pagarFatura);
    const f = c.fatura_atual;
    abrirLancamento(null, {
      tipo: "transferencia", conta_id: c.conta_pagamento_id || undefined, conta_destino_id: c.id,
      descricao: `Fatura ${c.nome} ${nomeMes(f.fatura.slice(0, 7))}`, data: f.vencimento, fatura: f.fatura,
    }).then(() => {
      const campo = document.querySelector("#gaveta [name=valor]");
      if (campo) campo.value = Math.max(0, f.total - f.pago).toFixed(2).replace(".", ",");
    });
  }));
  tela.querySelectorAll("[data-cat]").forEach((b) => b.addEventListener("click", () => editarCategoria(categorias.find((c) => String(c.id) === b.dataset.cat))));
  tela.querySelector("[data-nova-cat]").addEventListener("click", () => editarCategoria(null));
}

function cartao(c) {
  const f = c.fatura_atual;
  const usado = Math.max(0, Number(c.usado || 0));
  const limite = Number(c.limite || 0);
  const aberta = f ? f.total - f.pago : 0;
  return `<li style="--c:${esc(c.cor || "#5B4A8B")}" data-conta="${c.id}" tabindex="0" role="button">
    <span class="tipo">Fecha dia ${c.dia_fechamento} · vence dia ${c.dia_vencimento}</span>
    <strong>${esc(c.nome)}</strong>
    ${f ? `<span class="fatura">Fatura de ${nomeMes(f.fatura.slice(0, 7))}: ${valor(f.total)}
      <small>${f.total <= 0 ? "sem compras" : aberta <= 0.005 ? "paga" : `vence ${dataCurta(f.vencimento)}`}</small></span>` : ""}
    ${limite ? `<span class="limite"><i style="--p:${Math.min(100, (usado / limite) * 100)}%"></i>
      <small>${esc(brl(usado))} usados de ${esc(brl(limite))}</small></span>` : ""}
    ${f && aberta > 0.005 ? `<button class="botao" data-pagar-fatura="${c.id}">Pagar fatura</button>` : ""}
  </li>`;
}

async function editarCategoria(cat) {
  const nome = prompt(cat ? `Novo nome para "${cat.nome}" (deixe vazio para apagar):` : "Nome da nova categoria de despesa:", cat?.nome || "");
  if (nome === null) return;
  try {
    if (cat && !nome.trim()) {
      if (!confirm(`Apagar a categoria "${cat.nome}"? Os lançamentos dela ficam sem categoria.`)) return;
      await api.post("cadastros", { acao: "apagar_categoria", id: cat.id });
    } else {
      await api.post("cadastros", { acao: "salvar_categoria", id: cat?.id, nome, tipo: cat?.tipo || "despesa", cor: cat?.cor, icone: cat?.icone, pai_id: cat?.pai_id });
    }
    document.dispatchEvent(new CustomEvent("fin:mudou"));
  } catch (e) { alert(e.message); }
}
