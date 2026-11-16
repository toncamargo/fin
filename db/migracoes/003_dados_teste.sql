-- 003: dados de teste separados dos reais (coluna "teste"; o site alterna entre os dois) — 2026-11-16
ALTER TABLE contas        ADD COLUMN IF NOT EXISTS teste BOOLEAN NOT NULL DEFAULT 0;
ALTER TABLE lancamentos   ADD COLUMN IF NOT EXISTS teste BOOLEAN NOT NULL DEFAULT 0;
ALTER TABLE importacoes   ADD COLUMN IF NOT EXISTS teste BOOLEAN NOT NULL DEFAULT 0;
ALTER TABLE orcamentos    ADD COLUMN IF NOT EXISTS teste BOOLEAN NOT NULL DEFAULT 0;
ALTER TABLE metas         ADD COLUMN IF NOT EXISTS teste BOOLEAN NOT NULL DEFAULT 0;
ALTER TABLE investimentos ADD COLUMN IF NOT EXISTS teste BOOLEAN NOT NULL DEFAULT 0;

-- O orçamento de teste não pode colidir com o real do mesmo mês/categoria
ALTER TABLE orcamentos DROP INDEX IF EXISTS mes;
ALTER TABLE orcamentos ADD UNIQUE KEY IF NOT EXISTS mes_categoria_teste (mes, categoria_id, teste);

CREATE INDEX IF NOT EXISTS contas_teste ON contas (teste);
