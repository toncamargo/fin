/* Entrada do Fin: login, mês, navegação entre telas, atalhos. */
import { estado } from "./estado.js";
import { api } from "./api.js";
import { esc, gravar, somarMes, mesAtual, nomeMes, avisar } from "./util.js";
import { icone } from "./icones.js";
import { abrirLancamento } from "./gaveta.js";
import * as visao from "./telas/visao.js";
import * as lancamentos from "./telas/lancamentos.js";
import * as contas from "./telas/contas.js";
import * as embreve from "./telas/embreve.js";

const ROTAS = {
  "": ["visao", visao], lancamentos: ["lancamentos", lancamentos], contas: ["contas", contas],
  orcamento: ["orcamento", embreve], metas: ["metas", embreve], investimentos: ["investimentos", embreve], importar: ["importar", embreve],
};
const tela = document.getElementById("tela");

// ---------- Login ----------
const ERROS_LOGIN = {
  "nao-autorizado": "Esta conta Google não tem acesso ao Fin.",
  cancelado: "O login foi cancelado.",
  expirado: "O login demorou demais. Tente de novo.",
  falha: "Não foi possível concluir o login com o Google. Tente de novo.",
  sessao: "O servidor não conseguiu guardar a sessão de login.",
  "login-nao-configurado": "O login com Google ainda não foi configurado no servidor (.env).",
};
function mostrarLogin() {
  document.getElementById("app").hidden = true;
  document.getElementById("login").hidden = false;
}
document.addEventListener("fin:login", mostrarLogin);

async function iniciar() {
  window.__finIniciou = true; // o aviso de "servidor parado" do index.html não aparece mais
  const erro = new URLSearchParams(location.search).get("erro");
  if (erro) {
    const el = document.getElementById("login-erro");
    el.textContent = ERROS_LOGIN[erro] || ERROS_LOGIN.falha;
    el.hidden = false;
    history.replaceState(null, "", location.pathname + location.hash);
  }
  let eu;
  try { eu = await api.eu(); } catch { mostrarLogin(); return; }
  document.getElementById("app").hidden = false;
  montarConta(eu);
  montarMes();
  document.body.classList.toggle("privado", estado.privado);
  atualizarPrivado();
  navegar();
}

function montarConta(eu) {
  const b = document.getElementById("btn-conta");
  const inicial = esc((eu.nome || "?").trim()[0].toUpperCase());
  const foto = eu.foto ? `<img src="${esc(eu.foto)}" alt="" referrerpolicy="no-referrer">` : `<span class="inicial">${inicial}</span>`;
  b.innerHTML = `${foto}<span><strong>${esc(eu.nome)}</strong>${eu.local ? "neste computador" : "Sair"}</span>`;
  b.title = eu.local ? "Uso local, sem login" : `${eu.email} — clique para sair`;
  b.addEventListener("click", async () => {
    if (eu.local) return;
    await api.sair();
    location.replace(location.pathname);
  });
}

// ---------- Mês ----------
function montarMes() {
  document.querySelectorAll(".mes-seta").forEach((b) => {
    b.innerHTML = icone(b.dataset.mes === "-1" ? "chevron-left" : "chevron-right", 18);
    b.addEventListener("click", () => trocarMes(somarMes(estado.mes, Number(b.dataset.mes))));
  });
  document.getElementById("mes-nome").addEventListener("click", () => trocarMes(mesAtual()));
  pintarMes();
}
function pintarMes() {
  const atual = estado.mes === mesAtual();
  document.getElementById("mes-nome").innerHTML = `${nomeMes(estado.mes)}<small>${estado.mes.slice(0, 4)}${atual ? " · este mês" : ""}</small>`;
}
function trocarMes(mes) {
  estado.mes = mes;
  pintarMes();
  renderizar();
}

// ---------- Navegação ----------
let rotaAtual = null;
function navegar() {
  const chave = location.hash.replace(/^#\/?/, "").split("?")[0];
  const [nome, modulo] = ROTAS[chave] || ROTAS[""];
  rotaAtual = { nome, modulo };
  document.querySelectorAll(".nav a").forEach((a) => a.toggleAttribute("aria-current", false));
  document.querySelector(`.nav a[data-rota="${nome}"]`)?.setAttribute("aria-current", "page");
  renderizar(true);
}
async function renderizar(rolarTopo = false) {
  if (!rotaAtual) return;
  try {
    await rotaAtual.modulo.mostrar(tela, rotaAtual.nome);
    if (rolarTopo) { window.scrollTo(0, 0); tela.focus({ preventScroll: true }); }
  } catch (e) {
    if (e.status === 401) return;
    tela.innerHTML = `<div class="vazio-grande"><h1>Não consegui carregar</h1><p>${esc(e.message)}</p>
      <button class="botao" onclick="location.reload()">Tentar de novo</button></div>`;
  }
}
window.addEventListener("hashchange", navegar);
document.addEventListener("fin:mudou", () => { estado.cadastros = null; renderizar(); });

// ---------- Valores escondidos ----------
function atualizarPrivado() {
  const b = document.getElementById("btn-privado");
  b.textContent = estado.privado ? "Mostrar valores" : "Esconder valores";
  b.setAttribute("aria-pressed", estado.privado);
}
document.getElementById("btn-privado").addEventListener("click", () => {
  estado.privado = !estado.privado;
  gravar("privado", estado.privado);
  document.body.classList.toggle("privado", estado.privado);
  atualizarPrivado();
});

// ---------- Novo lançamento ----------
const btnLancar = document.getElementById("btn-lancar");
btnLancar.innerHTML = `${icone("plus", 20)}<span>Lançar</span>`;
btnLancar.addEventListener("click", () => abrirLancamento());
document.addEventListener("keydown", (ev) => {
  const digitando = ["INPUT", "TEXTAREA", "SELECT"].includes(document.activeElement?.tagName) || document.getElementById("gaveta").open;
  if (digitando || ev.metaKey || ev.ctrlKey || ev.altKey) return;
  if (ev.key === "n" || ev.key === "N") { ev.preventDefault(); abrirLancamento(); }
  if (ev.key === "ArrowLeft" && ev.shiftKey) trocarMes(somarMes(estado.mes, -1));
  if (ev.key === "ArrowRight" && ev.shiftKey) trocarMes(somarMes(estado.mes, 1));
});

iniciar().catch((e) => avisar(e.message, "erro"));
