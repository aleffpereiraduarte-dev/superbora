# 🎨 Editor Visual Pro - Mercado OneMundo

Editor visual completo estilo Elementor/Wix para editar páginas diretamente no navegador.

## ✨ Funcionalidades

### 🧱 Construtor Visual
- ✅ Drag & Drop de elementos
- ✅ Seleção direta na página
- ✅ Edição inline de textos (duplo clique)
- ✅ Interface WYSIWYG

### 📐 Layout e Estrutura
- ✅ Seções e containers
- ✅ Colunas flexíveis
- ✅ Espaçadores
- ✅ Divisores

### 🔌 Widgets/Elementos
**Básico:**
- Títulos (H1-H6)
- Textos/Parágrafos
- Botões
- Links
- Imagens
- Ícones

**E-commerce:**
- Grid de Produtos
- Categorias
- Carrinho
- Busca
- Filtros

**Marketing:**
- Banner/Hero
- Carrossel
- Countdown
- Newsletter
- CTA
- Depoimentos

### 🧰 Estilização Visual
- ✅ Cores (fundo, texto, bordas)
- ✅ Tipografia (fonte, tamanho, peso, alinhamento)
- ✅ Espaçamentos (padding)
- ✅ Bordas (arredondamento)
- ✅ Background image

### 📱 Responsividade
- ✅ Preview Desktop
- ✅ Preview Tablet (768px)
- ✅ Preview Mobile (375px)

### 🎨 Estilos Globais
- ✅ Paleta de cores global
- ✅ Tipografia global
- ✅ CSS Variables

### ⚙️ Sistema
- ✅ Salvar rascunho
- ✅ Publicar
- ✅ Histórico (Undo/Redo)
- ✅ Atalhos de teclado
- ✅ Lista de camadas

---

## 📦 Instalação

### 1. Upload dos arquivos

Copie os arquivos para seu servidor:

```
/mercado/admin/
├── editor-pro.php       # Editor principal
├── editor-loader.php    # Loader para aplicar estilos
└── api/
    └── editor-save.php  # API de salvamento
```

### 2. Configurar banco de dados

Edite o arquivo `api/editor-save.php` e ajuste as credenciais:

```php
$config = [
    'host' => 'localhost',
    'dbname' => 'love1',     // Seu banco
    'user' => 'root',        // Seu usuário
    'pass' => ''             // Sua senha
];
```

As tabelas são criadas automaticamente:
- `om_editor_pages` - Páginas salvas
- `om_editor_versions` - Histórico de versões
- `om_editor_media` - Biblioteca de mídia

### 3. Incluir loader no template

No header do seu template (`/mercado/view/theme/default/template/common/header.twig`):

```php
<?php include DIR_APPLICATION . '../admin/editor-loader.php'; ?>
```

Ou adicione antes do `</head>`:

```html
<link rel="stylesheet" href="/mercado/admin/editor-styles.css">
```

### 4. Acessar o Editor

```
https://seusite.com/mercado/admin/editor-pro.php
```

---

## 🎯 Como Usar

### Edição Básica

1. **Abra o editor** (`/mercado/admin/editor-pro.php`)
2. **Clique em qualquer elemento** na página
3. **Painel direito** mostra as propriedades
4. **Altere cores, fontes, espaçamentos**
5. **Clique em Salvar**

### Edição de Texto

1. **Duplo clique** em títulos, parágrafos, botões
2. **Digite o novo texto**
3. **Enter** para confirmar ou **Esc** para cancelar

### Atalhos de Teclado

| Atalho | Ação |
|--------|------|
| `Ctrl + Z` | Desfazer |
| `Ctrl + Shift + Z` | Refazer |
| `Ctrl + S` | Salvar |
| `Delete` | Excluir elemento |
| `Esc` | Deselecionar |

### Viewport Responsivo

Use os botões no topo para visualizar:
- 🖥️ **Desktop** - Largura total
- 📱 **Tablet** - 768px
- 📱 **Mobile** - 375px

---

## 🔧 Estrutura de Arquivos

```
mercado/admin/
├── editor-pro.php           # Editor visual completo
├── editor-pro.html          # Versão standalone (demo)
├── editor-pro.js            # JavaScript do editor
├── editor-loader.php        # Aplica estilos salvos
└── api/
    └── editor-save.php      # API REST
```

### Tabelas do Banco

```sql
-- Páginas salvas
om_editor_pages (
    id, page_key, title, description, slug,
    html_content, global_styles, settings,
    status, is_homepage, version,
    created_at, updated_at
)

-- Histórico de versões
om_editor_versions (
    id, page_id, version, html_content,
    global_styles, created_at
)

-- Biblioteca de mídia
om_editor_media (
    id, filename, original_name, path,
    mime_type, size, width, height, created_at
)
```

---

## 🔒 Segurança

⚠️ **Importante:** Proteja o acesso ao editor!

Descomente as linhas de autenticação no `editor-pro.php`:

```php
session_start();
if (!isset($_SESSION['admin_logged'])) {
    header('Location: /mercado/admin/login.php');
    exit;
}
```

---

## 🐛 Solução de Problemas

### Editor não carrega a página

1. Verifique se o caminho `$mercado_url` está correto
2. Verifique se não há bloqueio de X-Frame-Options
3. Adicione no `.htaccess`:
```apache
Header set X-Frame-Options "SAMEORIGIN"
```

### Estilos não salvam

1. Verifique credenciais do banco em `api/editor-save.php`
2. Verifique permissões de escrita no banco
3. Veja console do navegador (F12) para erros

### Estilos não aparecem na página real

1. Verifique se `editor-loader.php` está incluído no template
2. Verifique se a página foi **publicada** (não apenas salva)
3. Limpe cache do navegador

---

## 📋 Próximas Versões

- [ ] Drag & Drop de widgets do sidebar
- [ ] Templates prontos
- [ ] Biblioteca de mídia completa
- [ ] Animações ao scroll
- [ ] Modo manutenção
- [ ] Multi-idioma
- [ ] Exportar/Importar templates

---

## 🚀 API Endpoints

### Salvar página
```http
POST /mercado/admin/api/editor-save.php
Content-Type: application/json

{
    "action": "save",
    "page": "index",
    "html": "<body>...</body>",
    "globalStyles": { "primary": "#6366f1" }
}
```

### Carregar página
```http
POST /mercado/admin/api/editor-save.php
Content-Type: application/json

{
    "action": "load",
    "page": "index"
}
```

### Publicar
```http
POST /mercado/admin/api/editor-save.php
Content-Type: application/json

{
    "action": "publish",
    "page": "index"
}
```

---

**Desenvolvido para Mercado OneMundo** 🛒✨
