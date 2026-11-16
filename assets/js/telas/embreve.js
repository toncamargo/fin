/* Telas ainda em construção. */
import { icone } from "../icones.js";

const TEXTO = {
  orcamento: ["Orçamento", "pie-chart", "Defina um limite por categoria para cada mês e acompanhe quanto já foi."],
  metas: ["Metas", "target", "Reserva de emergência, viagem, reforma: valor-alvo, prazo e aportes."],
  investimentos: ["Investimentos", "trending-up", "Carteira por classe, quanto foi aplicado e quanto vale hoje."],
  importar: ["Importar extrato", "file-up", "Envie o CSV ou OFX do banco ou da fatura; o Fin evita duplicados e categoriza pelas suas regras."],
};

export async function mostrar(tela, nome) {
  const [titulo, ic, texto] = TEXTO[nome];
  tela.innerHTML = `<div class="vazio-grande embreve">${icone(ic, 28)}<h1>${titulo}</h1><p>${texto}</p><p class="selo">Próxima etapa</p></div>`;
}
