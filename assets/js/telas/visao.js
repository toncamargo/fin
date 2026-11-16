/* Visão do mês: quanto sobrou, onde foi o dinheiro, contas, faturas e o que falta pagar. */
import { estado } from "../estado.js";
import { api } from "../api.js";
import { esc, brl, valor, nomeMes, nomeMesCurto, dataCurta, avisar } from "../util.js";
import { icone } from "../icones.js";
import { abrirLancamento, abrirConta } from "../gaveta.js";

export async function mostrar(tela) {
  tela.innerHTML = esqueleto();
  const r = await api.get("resumo", { mes: estado.mes });
  if (r.mes !== estado.mes) return; // mudou de mês enquanto carregava
  tela.innerHTML = r.vazio ? boasVindas() : conteudo(r);
  ligar(tela, r);
}

const esqueleto = () => `<div class="esq-hero"></div><div class="esq-linhas">${"<i></i>".repeat(6)}</div>`;

function boasVindas() {
  if (estado.teste) return `<section class="boas-vindas">
    <h1>Ainda não há dados de teste</h1>
    <p>Crie um exemplo com seis meses de salário, contas fixas, mercado, dois cartões (com faturas e uma compra parcelada),
       orçamento, metas e investimentos. Tudo fica marcado como teste e não se mistura com os seus dados.</p>
    <div class="acoes"><button class="botao primario" data-gerar-teste>${icone("sparkles", 16)}Criar dados de exemplo</button></div>
  </section>`;
  return `<section class="boas-vindas">
    <h1>Vamos começar pelas suas contas</h1>
    <p>Cadastre onde o seu dinheiro fica (conta corrente, poupança, carteira) e os cartões de crédito.
       Com o saldo de hoje de cada uma, o Fin passa a calcular tudo a partir daqui.</p>
    <div class="acoes"><button class="botao primario" data-nova-conta>${icone("wallet", 16)}Nova conta</button>
      <button class="botao" data-novo-cartao>${icone("credit-card", 16)}Novo cartão</button></div>
  </section>`;
}

function conteudo(r) {
  const t = r.totais;
  const sobrou = t.saldo >= 0;
  const maior = Math.max(t.receitas, t.despesas, 1);
  return `
  <section class="hero">
    <p class="hero-rotulo">${t.quantidade ? (sobrou ? "Sobrou" : "Faltou") + " em " + nomeMes(r.mes) : "Nada lançado em " + nomeMes(r.mes) + " ainda"}</p>
    <p class="hero-numero valor ${sobrou ? "" : "despesa"}">${esc(brl(Math.abs(t.saldo)))}</p>
    <div class="hero-barras">
      <div><span>Entrou</span>${valor(t.receitas, "receita")}<i class="barra receita" style="--p:${(t.receitas / maior) * 100}%"></i></div>
      <div><span>Saiu</span>${valor(t.despesas, "despesa")}<i class="barra despesa" style="--p:${(t.despesas / maior) * 100}%"></i></div>
    </div>
    ${t.a_pagar || t.a_receber ? `<p class="hero-pendente">${t.a_pagar ? `Ainda a pagar ${valor(t.a_pagar)}` : ""}${t.a_pagar && t.a_receber ? " · " : ""}${t.a_receber ? `a receber ${valor(t.a_receber)}` : ""}</p>` : ""}
  </section>

  <div class="colunas">
    <section class="bloco">
      <div class="bloco-topo"><h2>Onde foi o dinheiro</h2><a href="#/lancamentos" class="link">Ver lançamentos</a></div>
      ${categorias(r)}
    </section>

    <aside class="lateral">
      <section class="bloco">
        <div class="bloco-topo"><h2>Contas</h2><span class="valor total" title="Soma das contas">${esc(brl(r.patrimonio))}</span></div>
        <ul class="lista-contas">${r.contas.filter((c) => c.tipo !== "cartao").map((c) => `
          <li><a href="#/lancamentos?conta=${c.id}"><i class="ponto" style="--c:${esc(c.cor || "#2C5E4A")}"></i>${esc(c.nome)}</a>${valor(c.saldo, c.saldo < 0 ? "despesa" : "")}</li>`).join("")}
        </ul>
      </section>
      ${faturas(r)}
      ${abertos(r)}
      <section class="bloco">
        <div class="bloco-topo"><h2>Últimos 6 meses</h2></div>
        ${grafico(r.serie)}
      </section>
    </aside>
  </div>`;
}

function categorias(r) {
  if (!r.categorias.length) return `<p class="vazio">Nenhuma despesa em ${nomeMes(r.mes)}.</p>`;
  const total = r.categorias.reduce((s, c) => s + c.gasto, 0) || 1;
  return `<ul class="lista-cat">${r.categorias.map((c) => {
    const ref = c.orcado || total;
    const p = Math.min(100, (c.gasto / ref) * 100);
    const estourou = c.orcado && c.gasto > c.orcado;
    const nota = c.orcado
      ? (estourou ? `<span class="estouro">passou ${esc(brl(c.gasto - c.orcado))}</span>` : `de ${esc(brl(c.orcado))}`)
      : `${Math.round((c.gasto / total) * 100)}%`;
    return `<li style="--c:${esc(c.cor || "#8A8F98")}">
      <a href="#/lancamentos?categoria=${c.id ?? "sem"}" class="cat-nome"><span class="cat-icone">${icone(c.icone || "circle", 15)}</span>${esc(c.nome)}</a>
      <span class="cat-valor">${valor(c.gasto)}<small>${nota}</small></span>
      <i class="cat-barra${estourou ? " estourou" : ""}" style="--p:${p}%"></i>
    </li>`;
  }).join("")}</ul>`;
}

function faturas(r) {
  const cartoes = r.contas.filter((c) => c.tipo === "cartao");
  if (!cartoes.length) return "";
  return `<section class="bloco">
    <div class="bloco-topo"><h2>Faturas de ${nomeMesCurto(r.mes)}</h2></div>
    <ul class="lista-contas">${cartoes.map((c) => {
      const aberta = c.total - c.pago;
      const status = c.total <= 0 ? "sem compras" : aberta <= 0.005 ? "paga" : `vence ${dataCurta(c.vencimento)}`;
      return `<li><a href="#/lancamentos?conta=${c.id}"><i class="ponto cartao" style="--c:${esc(c.cor || "#5B4A8B")}"></i>${esc(c.nome)}
        <small>${status}</small></a>${valor(c.total)}</li>`;
    }).join("")}</ul>
  </section>`;
}

function abertos(r) {
  if (!r.abertos.length) return "";
  return `<section class="bloco">
    <div class="bloco-topo"><h2>Em aberto</h2></div>
    <ul class="lista-abertos">${r.abertos.map((l) => `
      <li><button data-pagar="${l.id}" title="Marcar como ${l.tipo === "receita" ? "recebido" : "pago"}" aria-label="Marcar ${esc(l.descricao)} como ${l.tipo === "receita" ? "recebido" : "pago"}">${icone("check", 14)}</button>
        <span>${esc(l.descricao)}<small>${dataCurta(l.data)} · ${esc(l.conta_nome)}</small></span>${valor(l.valor, l.tipo)}</li>`).join("")}
    </ul>
  </section>`;
}

/** Barras pareadas (entrou x saiu) dos últimos 6 meses, em SVG. */
function grafico(serie) {
  const W = 300, H = 120, base = H - 18;
  const max = Math.max(1, ...serie.flatMap((m) => [m.receitas, m.despesas]));
  const passo = W / serie.length;
  const barras = serie.map((m, i) => {
    const x = i * passo + passo / 2;
    const hr = (m.receitas / max) * (base - 6), hd = (m.despesas / max) * (base - 6);
    const atual = m.mes === estado.mes;
    return `<g class="${atual ? "atual" : ""}">
      <title>${nomeMes(m.mes)}: entrou ${brl(m.receitas)}, saiu ${brl(m.despesas)}</title>
      <rect class="g-receita" x="${x - 11}" y="${base - hr}" width="10" height="${hr}" rx="2"/>
      <rect class="g-despesa" x="${x + 1}" y="${base - hd}" width="10" height="${hd}" rx="2"/>
      <text x="${x}" y="${H - 3}" text-anchor="middle">${nomeMesCurto(m.mes)}</text></g>`;
  }).join("");
  return `<svg class="grafico privado" viewBox="0 0 ${W} ${H}" role="img" aria-label="Entradas e saídas dos últimos 6 meses">
    <line x1="0" x2="${W}" y1="${base}" y2="${base}" class="g-base"/>${barras}</svg>`;
}

function ligar(tela, r) {
  tela.querySelector("[data-nova-conta]")?.addEventListener("click", () => abrirConta());
  tela.querySelector("[data-gerar-teste]")?.addEventListener("click", async (ev) => {
    const b = ev.currentTarget;
    const texto = b.innerHTML;
    b.disabled = true;
    b.textContent = "Criando… (leva alguns segundos)";
    try {
      const r = await api.post("teste", { acao: "gerar" });
      avisar(`Exemplo criado: ${r.lancamentos} lançamentos.`);
      document.dispatchEvent(new CustomEvent("fin:mudou"));
    } catch (e) {
      b.disabled = false;
      b.innerHTML = texto;
      avisar(e.message, "erro");
    }
  });
  tela.querySelector("[data-novo-cartao]")?.addEventListener("click", () => abrirConta(null, { tipo: "cartao" }));
  tela.querySelectorAll("[data-pagar]").forEach((b) => b.addEventListener("click", async () => {
    b.disabled = true;
    await api.post("lancamentos", { acao: "pago", id: b.dataset.pagar, pago: true });
    document.dispatchEvent(new CustomEvent("fin:mudou"));
  }));
}

export { abrirLancamento };
