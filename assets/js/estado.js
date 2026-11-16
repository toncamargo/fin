/* Estado compartilhado entre as telas. */
import { ler, mesAtual } from "./util.js";
import { api } from "./api.js";

export const estado = {
  mes: mesAtual(),
  privado: ler("privado", false),
  cadastros: null,      // { contas, categorias } — recarregado quando algo muda
};

/** Avisa as telas que os dados mudaram (lançamento salvo, conta criada...). */
export const mudou = () => document.dispatchEvent(new CustomEvent("fin:mudou"));

/** Contas e categorias, com cache até algo mudar. */
export async function cadastros() {
  if (!estado.cadastros) estado.cadastros = await api.get("cadastros");
  return estado.cadastros;
}
