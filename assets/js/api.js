/* Conversa com a API em PHP. Sem login → evento "fin:login". */

async function pedir(url, opcoes = {}) {
  let r;
  try {
    r = await fetch(url, { cache: "no-store", credentials: "same-origin", ...opcoes });
  } catch {
    throw new Error("Sem conexão com o servidor.");
  }
  let j = null;
  try { j = await r.json(); } catch { /* resposta vazia */ }
  if (r.status === 401) document.dispatchEvent(new CustomEvent("fin:login"));
  if (!r.ok || !j || j.erro) {
    const e = new Error(j?.erro || `Erro ${r.status} ao falar com o servidor.`);
    e.status = r.status;
    throw e;
  }
  return j;
}

const qs = (params) => new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== "")).toString();

export const api = {
  get: (recurso, params = {}) => pedir(`api/${recurso}.php?${qs(params)}`),
  post: (recurso, dados) => pedir(`api/${recurso}.php`, {
    method: "POST", headers: { "Content-Type": "application/json", "X-Fin": "1" }, body: JSON.stringify(dados),
  }),
  eu: () => pedir("api/auth/eu.php"),
  sair: () => fetch("api/auth/sair.php", { method: "POST", headers: { "X-Fin": "1" } }),
};
