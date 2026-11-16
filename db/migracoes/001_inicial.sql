-- 001: estrutura inicial do Fin (2026-11-16)
-- Valores sempre positivos; o sentido vem de lancamentos.tipo (despesa, receita, transferencia).

-- Contas: corrente, poupança, carteira, investimento e cartões de crédito
CREATE TABLE IF NOT EXISTS contas (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome             VARCHAR(80)  NOT NULL,
  tipo             ENUM('corrente','poupanca','carteira','investimento','cartao') NOT NULL DEFAULT 'corrente',
  instituicao      VARCHAR(80)  NULL,
  cor              CHAR(7)      NULL,                 -- #RRGGBB
  saldo_inicial    DECIMAL(14,2) NOT NULL DEFAULT 0,  -- saldo na data_inicial (não se aplica a cartão)
  data_inicial     DATE         NULL,
  -- só cartões
  limite           DECIMAL(14,2) NULL,
  dia_fechamento   TINYINT UNSIGNED NULL,             -- 1..31
  dia_vencimento   TINYINT UNSIGNED NULL,             -- 1..31
  conta_pagamento_id BIGINT UNSIGNED NULL,            -- conta que paga a fatura
  ativo            BOOLEAN      NOT NULL DEFAULT 1,
  ordem            SMALLINT     NOT NULL DEFAULT 0,
  criado           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (conta_pagamento_id) REFERENCES contas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categorias com um nível de subcategoria (pai_id)
CREATE TABLE IF NOT EXISTS categorias (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(60) NOT NULL,
  tipo        ENUM('despesa','receita') NOT NULL DEFAULT 'despesa',
  pai_id      BIGINT UNSIGNED NULL,
  cor         CHAR(7)     NULL,
  icone       VARCHAR(40) NULL,
  ativo       BOOLEAN     NOT NULL DEFAULT 1,
  ordem       SMALLINT    NOT NULL DEFAULT 0,
  UNIQUE KEY (tipo, pai_id, nome),
  FOREIGN KEY (pai_id) REFERENCES categorias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Importações de extrato/fatura (CSV, OFX)
CREATE TABLE IF NOT EXISTS importacoes (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conta_id    BIGINT UNSIGNED NOT NULL,
  arquivo     VARCHAR(255) NOT NULL,
  formato     ENUM('csv','ofx') NOT NULL,
  linhas      INT NOT NULL DEFAULT 0,
  importadas  INT NOT NULL DEFAULT 0,
  ignoradas   INT NOT NULL DEFAULT 0,               -- duplicadas
  criado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conta_id) REFERENCES contas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lançamentos
CREATE TABLE IF NOT EXISTS lancamentos (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo           ENUM('despesa','receita','transferencia') NOT NULL,
  conta_id       BIGINT UNSIGNED NOT NULL,
  conta_destino_id BIGINT UNSIGNED NULL,            -- só transferência (inclui pagamento de fatura)
  categoria_id   BIGINT UNSIGNED NULL,
  descricao      VARCHAR(255) NOT NULL,
  valor          DECIMAL(14,2) NOT NULL,            -- sempre positivo
  data           DATE NOT NULL,                     -- data da compra / movimento
  fatura         DATE NULL,                         -- cartão: 1º dia do mês da fatura em que entra
  pago           BOOLEAN NOT NULL DEFAULT 1,        -- 0 = previsto / a pagar
  parcela        SMALLINT UNSIGNED NULL,            -- 3 (de 10)
  parcelas       SMALLINT UNSIGNED NULL,            -- 10
  grupo          CHAR(16) NULL,                     -- liga as parcelas de uma mesma compra
  observacao     TEXT NULL,
  importacao_id  BIGINT UNSIGNED NULL,
  hash_origem    CHAR(40) NULL,                     -- impressão digital da linha importada (evita duplicar)
  criado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CHECK (valor > 0),
  INDEX (data), INDEX (conta_id, data), INDEX (categoria_id, data), INDEX (fatura), INDEX (grupo),
  UNIQUE KEY (conta_id, hash_origem),
  FOREIGN KEY (conta_id) REFERENCES contas(id),
  FOREIGN KEY (conta_destino_id) REFERENCES contas(id),
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL,
  FOREIGN KEY (importacao_id) REFERENCES importacoes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regras para categorizar automaticamente o que vem de importação ("IFOOD" → Restaurantes)
CREATE TABLE IF NOT EXISTS regras (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contem        VARCHAR(80) NOT NULL,                -- trecho do texto da descrição (sem acento, minúsculo)
  categoria_id  BIGINT UNSIGNED NOT NULL,
  descricao_nova VARCHAR(255) NULL,                  -- opcional: renomeia ("PAG*JOSEDASILVA" → "Feira")
  criado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (contem),
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orçamento mensal por categoria
CREATE TABLE IF NOT EXISTS orcamentos (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mes           CHAR(7) NOT NULL,                    -- AAAA-MM
  categoria_id  BIGINT UNSIGNED NOT NULL,
  valor         DECIMAL(14,2) NOT NULL,
  UNIQUE KEY (mes, categoria_id),
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metas (reserva de emergência, viagem...) e seus aportes
CREATE TABLE IF NOT EXISTS metas (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(80) NOT NULL,
  valor_alvo  DECIMAL(14,2) NOT NULL,
  prazo       DATE NULL,
  cor         CHAR(7) NULL,
  status      ENUM('ativa','pausada','concluida','arquivada') NOT NULL DEFAULT 'ativa',
  observacao  TEXT NULL,
  criado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aportes (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meta_id     BIGINT UNSIGNED NOT NULL,
  data        DATE NOT NULL,
  valor       DECIMAL(14,2) NOT NULL,                -- negativo = resgate
  observacao  VARCHAR(255) NULL,
  criado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (meta_id, data),
  FOREIGN KEY (meta_id) REFERENCES metas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Investimentos e o histórico de posição de cada um
CREATE TABLE IF NOT EXISTS investimentos (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(100) NOT NULL,                -- "Tesouro Selic 2029", "CDB Inter 110%"
  classe       ENUM('renda_fixa','tesouro','acoes','fiis','fundos','previdencia','cripto','outros') NOT NULL DEFAULT 'renda_fixa',
  instituicao  VARCHAR(80) NULL,
  meta_id      BIGINT UNSIGNED NULL,                 -- para qual objetivo
  liquidez     VARCHAR(40) NULL,                     -- "diária", "no vencimento"
  vencimento   DATE NULL,
  ativo        BOOLEAN NOT NULL DEFAULT 1,
  criado       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (meta_id) REFERENCES metas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posicoes (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  investimento_id  BIGINT UNSIGNED NOT NULL,
  data             DATE NOT NULL,
  valor_aplicado   DECIMAL(14,2) NOT NULL,           -- total investido até a data
  valor_atual      DECIMAL(14,2) NOT NULL,           -- saldo bruto na data
  UNIQUE KEY (investimento_id, data),
  FOREIGN KEY (investimento_id) REFERENCES investimentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controle das migrações aplicadas
CREATE TABLE IF NOT EXISTS migracoes (
  arquivo   VARCHAR(120) PRIMARY KEY,
  aplicada  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
