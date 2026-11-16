/* Gaveta lateral: novo/editar lançamento e conta. */
import { estado, cadastros, mudou } from "./estado.js";
import { api } from "./api.js";
import { esc, hoje, brl, avisar, dataCurta } from "./util.js";
import { icone } from "./icones.js";

const gaveta = document.getElementById("gaveta");
gaveta.addEventListener("click", (ev) => { if (ev.target === gaveta) gaveta.close(); });
gaveta.addEventListener("close", () => { gaveta.innerHTML = ""; });

function moldura(titulo, corpo, rodape) {
  gaveta.innerHTML = `<form novalidate>
    <header><h2 id="gaveta-titulo">${esc(titulo)}</h2>
      <button type="button" class="fechar" data-fechar aria-label="Fechar">${icone("x", 20)}</button></header>
    <div class="corpo">${corpo}<p class="erro" data-erro hidden></p></div>
    <footer>${rodape}</footer>
  </form>`;
  gaveta.querySelector("[data-fechar]").addEventListener("click", () => gaveta.close());
  gaveta.querySelectorAll("[data-cancelar]").forEach((b) => b.addEventListener("click", () => gaveta.close()));
  gaveta.showModal();
  return gaveta.querySelector("form");
}

function mostrarErro(form, msg) {
  const el = form.querySelector("[data-erro]");
  el.textContent = msg; el.hidden = false;
}

async function enviar(form, botao, fn) {
  form.querySelector("[data-erro]").hidden = true;
  const texto = botao.textContent;
  botao.disabled = true; botao.textContent = "Salvando…";
  try {
    await fn();
    gaveta.close();
    mudou();
  } catch (e) {
    mostrarErro(form, e.message);
  } finally {
    botao.disabled = false; botao.textContent = texto;
  }
}

const opcoes = (lista, sel, vazio = "") =>
  (vazio ? `<option value="">${esc(vazio)}</option>` : "") +
  lista.map((o) => `<option value="${o.id}"${String(o.id) === String(sel) ? " selected" : ""}>${esc(o.nome)}</option>`).join("");

/** Categorias de um tipo, com as subcategorias recuadas sob a mãe. */
function categoriasDe(categorias, tipo) {
  const doTipo = categorias.filter((c) => c.tipo === tipo && c.ativo);
  const maes = doTipo.filter((c) => !c.pai_id);
  return maes.flatMap((m) => [m, ...doTipo.filter((c) => c.pai_id === m.id).map((f) => ({ ...f, nome: ` ${f.nome}` }))]);
}

// ---------------------------------------------------------------------------
// Lançamento
// ---------------------------------------------------------------------------
export async function abrirLancamento(l = null, padrao = {}) {
  const { contas, categorias } = await cadastros();
  const ativas = contas.filter((c) => c.ativo || (l && (c.id === l.conta_id || c.id === l.conta_destino_id)));
  if (!ativas.length) {
    avisar("Cadastre uma conta primeiro.");
    location.hash = "#/contas";
    return;
  }
  const novo = !l;
  const v = l || {
    tipo: padrao.tipo || "despesa", conta_id: padrao.conta_id || ativas.find((c) => c.tipo !== "cartao")?.id || ativas[0].id,
    data: hoje(), pago: 1, ...padrao,
  };
  const parcelado = l?.grupo ? `<p class="nota-parcela">Parcela ${l.parcela} de ${l.parcelas}. Mudanças valem só para esta parcela.</p>` : "";

  const form = moldura(novo ? "Novo lançamento" : "Editar lançamento", `
    <div class="segmento" role="radiogroup" aria-label="Tipo">
      ${["despesa", "receita", "transferencia"].map((t) => `<label class="seg-${t}"><input type="radio" name="tipo" value="${t}"${v.tipo === t ? " checked" : ""}>
        ${{ despesa: "Despesa", receita: "Receita", transferencia: "Transferência" }[t]}</label>`).join("")}
    </div>
    <label class="campo campo-valor">Valor
      <span class="valor-entrada"><span>R$</span><input name="valor" inputmode="decimal" autocomplete="off" placeholder="0,00"
        value="${l ? esc(Number(l.valor).toFixed(2).replace(".", ",")) : ""}" required></span></label>
    <label class="campo">Descrição <input name="descricao" maxlength="255" autocomplete="off" value="${esc(v.descricao || "")}" placeholder="Ex.: Mercado, Salário, Uber" required></label>
    <div class="linha-2">
      <label class="campo">Data <input name="data" type="date" value="${esc(String(v.data).slice(0, 10))}" required></label>
      <label class="campo" data-so="nao-transferencia">Categoria <select name="categoria_id"></select></label>
    </div>
    <div class="linha-2">
      <label class="campo"><span data-rotulo-conta>Conta</span> <select name="conta_id">${opcoes(ativas, v.conta_id)}</select></label>
      <label class="campo" data-so="transferencia">Para <select name="conta_destino_id">${opcoes(ativas, v.conta_destino_id, "Escolha")}</select></label>
      ${novo ? `<label class="campo" data-so="parcelavel">Parcelas <input name="parcelas" type="number" min="1" max="120" value="1"></label>` : ""}
    </div>
    <label class="interruptor" data-so="nao-cartao"><input type="checkbox" name="pago"${Number(v.pago) ? " checked" : ""}>
      <span data-rotulo-pago>Já foi pago</span></label>
    <p class="dica" data-dica-fatura hidden></p>
    ${parcelado}
    <label class="campo">Observação <textarea name="observacao" rows="2" maxlength="5000">${esc(v.observacao || "")}</textarea></label>
  `, `${novo ? "" : `<button type="button" class="botao perigo" data-apagar>${icone("trash-2", 16)}Apagar</button>`}
      <div class="direita"><button type="button" class="botao" data-cancelar>Cancelar</button>
      <button type="submit" class="botao primario">${novo ? "Lançar" : "Salvar"}</button></div>`);

  const tipoAtual = () => form.querySelector("[name=tipo]:checked").value;
  const contaAtual = () => contas.find((c) => String(c.id) === form.conta_id.value);
  const ajustar = () => {
    const t = tipoAtual(), c = contaAtual(), cartao = c?.tipo === "cartao";
    const sel = form.categoria_id.value || v.categoria_id;
    if (t !== "transferencia") form.categoria_id.innerHTML = opcoes(categoriasDe(categorias, t), sel, "Sem categoria");
    form.querySelectorAll("[data-so]").forEach((el) => {
      const r = el.dataset.so;
      el.hidden = (r === "transferencia" && t !== "transferencia") || (r === "nao-transferencia" && t === "transferencia")
        || (r === "parcelavel" && t === "transferencia") || (r === "nao-cartao" && cartao);
    });
    form.querySelector("[data-rotulo-conta]").textContent = t === "transferencia" ? "De" : cartao ? "Cartão" : "Conta";
    form.querySelector("[data-rotulo-pago]").textContent = t === "receita" ? "Já foi recebido" : "Já foi pago";
    const dica = form.querySelector("[data-dica-fatura]");
    dica.hidden = !cartao || t === "transferencia";
    if (cartao && c.dia_fechamento) dica.textContent = `Cartão fecha dia ${c.dia_fechamento} e vence dia ${c.dia_vencimento}: compras a partir do fechamento vão para a fatura seguinte.`;
  };
  form.addEventListener("change", (ev) => { if (["tipo", "conta_id"].includes(ev.target.name)) ajustar(); });
  ajustar();
  setTimeout(() => form.valor.focus(), 60);

  const botao = form.querySelector("[type=submit]");
  form.addEventListener("submit", (ev) => {
    ev.preventDefault();
    const t = tipoAtual();
    const dados = {
      acao: "salvar", id: l?.id, tipo: t, valor: form.valor.value, descricao: form.descricao.value, data: form.data.value,
      conta_id: form.conta_id.value, conta_destino_id: t === "transferencia" ? form.conta_destino_id.value : null,
      categoria_id: t === "transferencia" ? null : form.categoria_id.value, pago: form.pago.checked,
      parcelas: form.parcelas ? form.parcelas.value : undefined, observacao: form.observacao.value,
      // pagamento de fatura: mantém a fatura escolhida (senão o servidor usa a do mês do pagamento)
      fatura: t === "transferencia" && v.fatura && String(form.conta_destino_id.value) === String(v.conta_destino_id) ? v.fatura : undefined,
    };
    enviar(form, botao, async () => {
      const r = await api.post("lancamentos", dados);
      avisar(r.ids?.length > 1 ? `${r.ids.length} parcelas lançadas.` : novo ? "Lançado." : "Salvo.");
    });
  });

  form.querySelector("[data-apagar]")?.addEventListener("click", (ev) => confirmarApagar(form, ev.currentTarget, l));
}

function confirmarApagar(form, botao, l) {
  const rodape = form.querySelector("footer");
  rodape.innerHTML = `<span class="pergunta">Apagar este lançamento?</span>
    <div class="direita">
      <button type="button" class="botao" data-nao>Não</button>
      ${l.grupo ? `<button type="button" class="botao perigo" data-todas>Esta e as próximas</button>` : ""}
      <button type="button" class="botao perigo" data-sim>${l.grupo ? "Só esta" : "Apagar"}</button>
    </div>`;
  const apagar = (todas) => enviar(form, rodape.querySelector(todas ? "[data-todas]" : "[data-sim]"), async () => {
    const r = await api.post("lancamentos", { acao: "apagar", id: l.id, todas_parcelas: todas });
    avisar(r.apagados > 1 ? `${r.apagados} parcelas apagadas.` : "Apagado.");
  });
  rodape.querySelector("[data-sim]").addEventListener("click", () => apagar(false));
  rodape.querySelector("[data-todas]")?.addEventListener("click", () => apagar(true));
  rodape.querySelector("[data-nao]").addEventListener("click", () => { gaveta.close(); abrirLancamento(l); });
}

// ---------------------------------------------------------------------------
// Conta / cartão
// ---------------------------------------------------------------------------
const TIPOS_CONTA = { corrente: "Conta corrente", poupanca: "Poupança", carteira: "Carteira (dinheiro)", investimento: "Conta de investimento", cartao: "Cartão de crédito" };
const CORES = ["#2C5E4A", "#820AD1", "#EC7000", "#CC092F", "#005CA9", "#FFCC29", "#5B4A8B", "#17211D"];

export async function abrirConta(c = null, padrao = {}) {
  const { contas } = await cadastros();
  const novo = !c;
  const v = c || { tipo: padrao.tipo || "corrente", cor: CORES[0], data_inicial: hoje(), ...padrao };
  const pagadoras = contas.filter((x) => x.tipo !== "cartao" && x.ativo);
  const form = moldura(novo ? (v.tipo === "cartao" ? "Novo cartão" : "Nova conta") : `Editar ${v.tipo === "cartao" ? "cartão" : "conta"}`, `
    <label class="campo">Tipo <select name="tipo">${Object.entries(TIPOS_CONTA).map(([k, r]) => `<option value="${k}"${v.tipo === k ? " selected" : ""}>${r}</option>`).join("")}</select></label>
    <label class="campo">Nome <input name="nome" maxlength="80" value="${esc(v.nome || "")}" placeholder="Ex.: Nubank, Itaú, Carteira" required></label>
    <label class="campo">Instituição <input name="instituicao" maxlength="80" value="${esc(v.instituicao || "")}"></label>
    <div class="linha-2" data-so="conta">
      <label class="campo">Saldo em <input name="data_inicial" type="date" value="${esc(v.data_inicial || hoje())}"></label>
      <label class="campo">Saldo nessa data <input name="saldo_inicial" inputmode="decimal" value="${esc(String(Number(v.saldo_inicial || 0).toFixed(2)).replace(".", ","))}"></label>
    </div>
    <div class="linha-2" data-so="cartao">
      <label class="campo">Fecha no dia <input name="dia_fechamento" type="number" min="1" max="31" value="${esc(v.dia_fechamento || "")}"></label>
      <label class="campo">Vence no dia <input name="dia_vencimento" type="number" min="1" max="31" value="${esc(v.dia_vencimento || "")}"></label>
    </div>
    <div class="linha-2" data-so="cartao">
      <label class="campo">Limite <input name="limite" inputmode="decimal" value="${v.limite ? esc(String(Number(v.limite).toFixed(2)).replace(".", ",")) : ""}"></label>
      <label class="campo">Paga com <select name="conta_pagamento_id">${opcoes(pagadoras, v.conta_pagamento_id, "Escolha")}</select></label>
    </div>
    <fieldset class="cores"><legend>Cor</legend>${CORES.map((cor) => `<label style="--c:${cor}"><input type="radio" name="cor" value="${cor}"${v.cor === cor ? " checked" : ""}><span class="sr">${cor}</span></label>`).join("")}</fieldset>
    ${!novo ? `<label class="interruptor"><input type="checkbox" name="ativo"${Number(v.ativo) ? " checked" : ""}> <span>Em uso</span></label>` : ""}
  `, `${novo ? "" : `<button type="button" class="botao perigo" data-apagar>${icone("trash-2", 16)}Apagar</button>`}
      <div class="direita"><button type="button" class="botao" data-cancelar>Cancelar</button>
      <button type="submit" class="botao primario">Salvar</button></div>`);

  const ajustar = () => {
    const cartao = form.tipo.value === "cartao";
    form.querySelectorAll("[data-so=cartao]").forEach((el) => (el.hidden = !cartao));
    form.querySelectorAll("[data-so=conta]").forEach((el) => (el.hidden = cartao));
  };
  form.tipo.addEventListener("change", ajustar);
  ajustar();
  setTimeout(() => form.nome.focus(), 60);

  const botao = form.querySelector("[type=submit]");
  form.addEventListener("submit", (ev) => {
    ev.preventDefault();
    const dados = Object.fromEntries(new FormData(form));
    dados.ativo = form.ativo ? form.ativo.checked : true;
    enviar(form, botao, async () => { await api.post("cadastros", { acao: "salvar_conta", id: c?.id, ...dados }); avisar("Salvo."); });
  });
  form.querySelector("[data-apagar]")?.addEventListener("click", () => {
    const rodape = form.querySelector("footer");
    rodape.innerHTML = `<span class="pergunta">Apagar ${esc(c.nome)}? Se tiver lançamentos, ela só sai das listas.</span>
      <div class="direita"><button type="button" class="botao" data-nao>Não</button><button type="button" class="botao perigo" data-sim>Apagar</button></div>`;
    rodape.querySelector("[data-nao]").addEventListener("click", () => { gaveta.close(); abrirConta(c); });
    rodape.querySelector("[data-sim]").addEventListener("click", (ev) => enviar(form, ev.currentTarget, async () => {
      const r = await api.post("cadastros", { acao: "apagar_conta", id: c.id });
      avisar(r.acao === "arquivada" ? "Conta arquivada (tem lançamentos)." : "Conta apagada.");
    }));
  });
}

export { brl, dataCurta };
