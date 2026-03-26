# Mercearia do Tatu — Sistema de Gestão

## Requisitos
- PHP 7.4+ ou PHP 8.x
- MySQL 5.7+ ou MariaDB 10.3+
- Servidor Apache ou Nginx com mod_rewrite

## Instalação Rápida (XAMPP/WAMP/Laragon)

1. **Copie a pasta** `pdv/` para dentro do seu `htdocs` (XAMPP) ou `www` (WAMP):
   ```
   C:\xampp\htdocs\pdv\
   ```

2. **Configure o banco de dados** em `includes/config.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');       // sua senha MySQL
   define('DB_NAME', 'mercearia_tatu');
   ```

3. **Acesse no navegador:**
   ```
   http://localhost/pdv/login.php
   ```

4. O sistema **cria o banco automaticamente** na primeira vez.

## Acesso Padrão
- **E-mail:** admin@pdv.com
- **Senha:** admin123

⚠️ Troque a senha após o primeiro acesso em: Usuários → Editar

---

## Funcionalidades

### PDV / Caixa
- Busca de produto por **código EAN** (leitor de código de barras)
- Busca por nome ou código interno
- Carrinho com controle de quantidade
- Desconto por venda
- Formas de pagamento: Dinheiro, Pix, Débito, Crédito, Fiado
- Baixa automática de estoque ao finalizar

### Produtos
- Cadastro completo com **EAN/Código de Barras**
- Preço de custo e venda
- Cálculo automático de margem e markup
- Controle de estoque mínimo
- Alertas de estoque baixo

### Estoque
- Entrada de estoque (com NF)
- Saída manual
- Ajuste de estoque (contagem)
- Histórico completo de movimentações

### Vendas
- Histórico completo por período
- Filtro por forma de pagamento
- Detalhamento de cada venda com lucro
- Cancelamento de venda (admin) com restauração de estoque

### Relatórios
- Vendas por dia (gráfico)
- Por forma de pagamento (gráfico)
- Top produtos mais vendidos
- Por categoria
- Resumo com faturamento, custo e lucro

### Cadastros
- Produtos, Categorias, Fornecedores, Clientes, Usuários

---

## Estrutura de Arquivos
```
pdv/
├── index.php              # Início
├── login.php              # Login
├── logout.php
├── includes/
│   ├── config.php         # Configurações e helpers
│   ├── setup.php          # Instalador automático do banco
│   ├── header.php         # Layout header
│   └── footer.php         # Layout footer
├── pages/
│   ├── pdv.php            # Caixa / PDV
│   ├── products.php       # Produtos
│   ├── sales.php          # Vendas
│   ├── stock_in.php       # Entrada de estoque
│   ├── stock_adjust.php   # Ajuste de estoque
│   ├── stock_history.php  # Histórico
│   ├── customers.php      # Clientes
│   ├── suppliers.php      # Fornecedores
│   ├── purchases.php      # Compras
│   ├── reports.php        # Relatórios
│   ├── categories.php     # Categorias
│   └── users.php          # Usuários
└── assets/
    ├── css/main.css
    └── js/main.js
```
