-- 002: categorias iniciais (dá para renomear, apagar e criar outras pelo site)
INSERT IGNORE INTO categorias (nome, tipo, cor, icone, ordem) VALUES
  ('Moradia',        'despesa', '#8C6A4F', 'home',          10),
  ('Mercado',        'despesa', '#5F8F3E', 'shopping-cart', 20),
  ('Alimentação fora','despesa', '#D2773C', 'utensils',     30),
  ('Transporte',     'despesa', '#3F7FBF', 'car',           40),
  ('Saúde',          'despesa', '#C8505A', 'heart-pulse',   50),
  ('Educação',       'despesa', '#6B5FB5', 'graduation-cap',60),
  ('Lazer',          'despesa', '#D4A12A', 'sparkles',      70),
  ('Assinaturas',    'despesa', '#4F8A8B', 'repeat',        80),
  ('Compras',        'despesa', '#B5577E', 'shopping-bag',  90),
  ('Impostos e taxas','despesa', '#6E6E6E', 'landmark',    100),
  ('Outros gastos',  'despesa', '#8A8F98', 'circle',       999),
  ('Salário',        'receita', '#2E8B6A', 'briefcase',     10),
  ('Rendimentos',    'receita', '#3C9C8C', 'trending-up',   20),
  ('Reembolsos',     'receita', '#5E9DB8', 'rotate-ccw',    30),
  ('Outras receitas','receita', '#7A9A5A', 'circle',       999);
