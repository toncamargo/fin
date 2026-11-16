# Fin

Site pessoal para controlar finanças: lançamentos, contas e cartões, orçamento, metas e investimentos.
Login com Google; dados num banco MySQL/MariaDB (Hostinger). Endereço: **fin.toncamargo.com.br**.

## Como rodar no computador

```bash
cd ~/Ton/fin
cp .env.example .env          # preencha MYSQL_* (e GOOGLE_* para o login)
php scripts/migrar.php        # cria/atualiza as tabelas
php -S 127.0.0.1:8788 router.php
```
Abra **http://localhost:8788** (o Google não aceita 127.0.0.1).
Sem `GOOGLE_*` no `.env`, o site funciona só no próprio computador, sem login.

## Estrutura

```
index.html                  casca do app (trilho, telas, gaveta)
assets/css/                 base.css (cores, tipografia) · app.css (estrutura) · telas.css
assets/js/                  main.js (login, mês, rotas) · gaveta.js (formulários) · api.js · estado.js · util.js · icones.js
assets/js/telas/            visao.js · lancamentos.js · contas.js · embreve.js
assets/img/                 favicon e a trama de fundo
assets/vendor/              fonte Manrope e licenças
api/                        resumo.php · lancamentos.php · cadastros.php · auth/ (login com Google)
api/lib/                    config, banco, sessão, regras de negócio (financas.php) — bloqueado na web
db/migracoes/               SQL numerado; aplicado por scripts/migrar.php
scripts/migrar.php          migrações (--status, --testar)
router.php                  servidor local (reproduz as proteções do .htaccess)
```

## Regras

- Valores sempre positivos; o tipo diz se é **despesa**, **receita** ou **transferência**.
- **Cartão de crédito**: cada compra entra numa fatura (mês do vencimento). Comprou no dia do
  fechamento ou depois, vai para a seguinte. No mês, gasto no cartão conta pela fatura.
- **Pagar fatura** é uma transferência da conta para o cartão: não conta como despesa de novo.
- **Parcelado**: vira uma linha por mês ligadas pelo mesmo grupo; dá para apagar "esta e as próximas".
- **Saldo da conta** = saldo inicial + o que já foi pago/recebido a partir daquela data.
- Lançamento com data futura entra como "a pagar"/"a receber" até ser marcado.

## Segurança

- Toda a API exige login com Google; só os e-mails de `FIN_EMAILS` entram.
- Escrita: só POST + JSON + cabeçalho `X-Fin` (bloqueia formulários de outros sites).
- Credenciais só no `.env` (fora do git; online, `.env-fin` um nível acima da pasta pública).
- `api/lib`, `scripts`, `db` e arquivos ocultos ficam bloqueados (`.htaccess` e `router.php`).

## Login com Google

Dá para reaproveitar o cliente OAuth do Site Brain: em Google Cloud → Credenciais → o cliente
"Aplicativo da Web", acrescente
- Origens: `http://localhost:8788` e `https://fin.toncamargo.com.br`
- Redirecionamentos: `http://localhost:8788/api/auth/retorno.php` e `https://fin.toncamargo.com.br/api/auth/retorno.php`

e copie `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` para o `.env` do Fin, com `FIN_EMAILS=seu@gmail.com`.

## Próximas etapas

Orçamento (limite por categoria), Metas (aportes), Investimentos (posições) e Importar extrato (CSV/OFX).
